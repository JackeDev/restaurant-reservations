<?php

namespace App\Services;

use App\Contracts\SlotAllocator;
use App\Data\ConfirmedReservation;
use App\Data\DwellWindow;
use App\Data\ReservationDraft;
use App\Data\ReservationResult;
use App\Events\ReservationConfirmed;
use App\Events\ReservationRejected;
use App\Repositories\CustomerRepository;
use App\Repositories\ReservationRepository;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place a reservation is made.
 *
 * Both the MCP tool and the voice webhook go through here, so the rules live in
 * a single place and the two entry points cannot drift apart.
 *
 * Decides whether a booking may happen; never how it is stored. Redis is behind
 * the SlotAllocator port and the database behind the two repositories, so there
 * is no Eloquent in this file at all — swapping either one leaves these rules
 * untouched.
 */
class ReservationService
{
    public function __construct(
        private readonly SlotAllocator $allocator,
        private readonly AlternativeSlotFinder $alternatives,
        private readonly OpeningHours $openingHours,
        private readonly CustomerRepository $customers,
        private readonly ReservationRepository $reservations,
        private readonly RestaurantClock $clock,
    ) {}

    public function reserve(ReservationDraft $draft): ReservationResult
    {
        if ($rejection = $this->rejectIfUnbookable($draft)) {
            return $rejection;
        }

        $window = $draft->dwellWindow();

        $allocatorStartedAt = microtime(true);
        $seatsLeft = $this->allocator->allocate($window, $draft->partySize);
        $allocatorMs = (microtime(true) - $allocatorStartedAt) * 1000;

        if ($seatsLeft < 0) {
            return $this->reject(
                $draft,
                'full',
                sprintf(
                    'There is no table for %d at %s on %s.',
                    $draft->partySize,
                    $this->clock->localTime($draft->requestedFor),
                    $this->clock->localDate($draft->requestedFor),
                ),
            );
        }

        try {
            $databaseStartedAt = microtime(true);
            $booking = $this->persist($draft, $window);
            $databaseMs = (microtime(true) - $databaseStartedAt) * 1000;
        } catch (Throwable $e) {
            /*
             * The seats are taken but there is no booking behind them, so give
             * them back before the failure propagates. The caller gets an
             * error, never a confirmation for a reservation that does not
             * exist: the response is only written after this block succeeds.
             */
            $this->allocator->release($window, $draft->partySize);

            throw $e;
        }

        $this->warnIfSlow($booking, $allocatorMs, $databaseMs);

        ReservationConfirmed::dispatch($booking, $seatsLeft);

        return ReservationResult::confirmed($booking);
    }

    /**
     * Reject requests that can never be satisfied, before touching Redis.
     */
    private function rejectIfUnbookable(ReservationDraft $draft): ?ReservationResult
    {
        if ($draft->requestedFor->lessThanOrEqualTo($this->clock->now())) {
            return $this->reject($draft, 'in_past', 'That time has already passed.');
        }

        if (! $this->openingHours->isOnGrid($draft->requestedFor)) {
            return $this->reject($draft, 'off_grid', sprintf(
                'Bookings start every %d minutes.',
                config('restaurant.slot_minutes'),
            ));
        }

        if (! $this->openingHours->isWithinOpeningHours($draft->requestedFor)) {
            $hours = $this->openingHours->describeFor($draft->requestedFor);
            $day = $this->describeDay($draft->requestedFor);

            /*
             * Opening hours differ by weekday, and these are the hours of the
             * requested day only. Naming that day is what stops the sentence
             * reading as a rule for the whole week — a voice agent says this out
             * loud, and "we only open 12:00-16:00" would send a customer away
             * believing we never serve dinner.
             */
            return $this->reject($draft, 'closed', $hours === []
                ? sprintf('We are closed on %s.', $day)
                : sprintf('On %s we only take bookings between %s.', $day, implode(' and ', $hours)));
        }

        return null;
    }

    /**
     * A day named the way a person would say it: weekday first, then the date.
     */
    private function describeDay(CarbonImmutable $slot): string
    {
        return sprintf(
            '%s %s',
            $this->clock->toLocal($slot)->format('l'),
            $this->clock->localDate($slot),
        );
    }

    /**
     * Build an unavailable result, complete with alternatives to offer.
     */
    private function reject(ReservationDraft $draft, string $code, string $reason): ReservationResult
    {
        $alternatives = $this->alternatives->find($draft->requestedFor, $draft->partySize);

        ReservationRejected::dispatch($draft, $code, $reason, count($alternatives));

        return ReservationResult::unavailable($reason, $alternatives);
    }

    /**
     * Write the booking: the customer first, so the reservation has an owner to
     * point at. Both writes are atomic and lock-free, so they parallelise.
     */
    private function persist(ReservationDraft $draft, DwellWindow $window): ConfirmedReservation
    {
        $customerId = $this->customers->findOrCreate(
            $draft->customerName,
            $draft->customerEmail,
            $draft->customerPhone,
        );

        return $this->reservations->create($draft, $customerId, $window);
    }

    /**
     * Break the timings out by dependency, so that when a booking is slow it is
     * immediately clear whether Redis or Postgres is responsible.
     */
    private function warnIfSlow(ConfirmedReservation $booking, float $allocatorMs, float $databaseMs): void
    {
        $threshold = (int) config('reservations.slow_threshold_ms');

        if ($allocatorMs + $databaseMs < $threshold) {
            return;
        }

        Log::warning('reservation.slow', [
            'reference' => $booking->reference,
            'allocator_ms' => round($allocatorMs, 2),
            'database_ms' => round($databaseMs, 2),
        ]);
    }
}
