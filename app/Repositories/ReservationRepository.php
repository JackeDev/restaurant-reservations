<?php

namespace App\Repositories;

use App\Data\ConfirmedReservation;
use App\Data\DwellWindow;
use App\Data\ReservationDraft;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Where reservations are written.
 *
 * This is the only class that knows reservations live in Eloquent. It takes
 * domain values in and hands domain values back, so the service that decides
 * whether a booking may happen never has to know how one is stored.
 */
class ReservationRepository
{
    /**
     * Store a confirmed booking.
     *
     * A plain insert, with no read-modify-write, so any number of these can run
     * at once without contending. All the contention was resolved atomically in
     * Redis before we got here.
     *
     * @param  int  $customerId  Already resolved, so this never has to read.
     */
    public function create(ReservationDraft $draft, int $customerId, DwellWindow $window): ConfirmedReservation
    {
        $reservation = Reservation::create([
            'reference' => Reservation::newReference(),
            'customer_id' => $customerId,
            'party_size' => $draft->partySize,
            'reserved_for' => $draft->requestedFor,
            /*
             * Stored rather than derived: changing the configured dwell times
             * tomorrow must not rewrite a booking already made.
             */
            'duration_minutes' => $window->durationMinutes,
            'status' => ReservationStatus::Confirmed,
            'created_via' => $draft->channel,
            'notes' => $draft->notes,
        ]);

        /*
         * Mapped back to plain values here rather than returning the model.
         * Everything but the reference came in with the draft, so this costs
         * nothing — and it means no caller can accidentally walk a relation and
         * pay for a query, which is exactly what used to happen.
         */
        return new ConfirmedReservation(
            reference: $reservation->reference,
            reservedFor: $draft->requestedFor,
            window: $window,
            partySize: $draft->partySize,
            customerName: $draft->customerName,
            notes: $draft->notes,
        );
    }

    /**
     * Seats genuinely sold in each slot, according to the database.
     *
     * Every reservation is expanded across the **whole window it occupies**, not
     * just the slot it starts in. That expansion is the whole point: a table
     * booked at 19:00 is still occupied at 19:30, so grouping by `reserved_for`
     * alone would produce a tidy answer that happens to be wrong — and would
     * agree with a broken allocator.
     *
     * This is the authoritative count. Redis holds what is left to sell, which is
     * a derived number and the one that can drift; these rows are the record of
     * what was actually sold. Both `reservations:verify` and
     * `reservations:sync-capacity` measure Redis against this.
     *
     * @param  CarbonImmutable  $since  Ignore anything finishing before this.
     * @return array<string, int> Slot id (UTC, to the minute) => seats taken.
     */
    public function occupancyBySlot(CarbonImmutable $since): array
    {
        $occupancy = [];

        /*
         * Chunked because a load-test run leaves tens of thousands of rows, and
         * the whole table has no business being in memory at once.
         */
        Reservation::query()
            ->confirmed()
            ->where('reserved_for', '>=', $since)
            ->orderBy('id')
            ->chunk(1000, function ($reservations) use (&$occupancy): void {
                foreach ($reservations as $reservation) {
                    // Built from the stored duration, so a config change today
                    // never rewrites what a past booking actually occupies.
                    foreach ($reservation->dwellWindow()->slots as $slot) {
                        $id = $slot->format('Y-m-d\TH:i');
                        $occupancy[$id] = ($occupancy[$id] ?? 0) + $reservation->party_size;
                    }
                }
            });

        ksort($occupancy);

        return $occupancy;
    }

    /**
     * Walk every confirmed booking from an instant onwards, oldest first.
     *
     * Hands the caller two plain values per row rather than the model, for the
     * same reason `create()` does: nothing outside this class should be able to
     * walk a relation and pay for a query it did not mean to make. Streamed in
     * chunks, because "everything still to come" is tens of thousands of rows
     * after a load test.
     *
     * @param  Closure(CarbonImmutable, int): void  $handle  Receives the slot
     *                                                       (UTC) and party size.
     */
    public function eachUpcoming(CarbonImmutable $from, Closure $handle): void
    {
        Reservation::query()
            ->confirmed()
            ->where('reserved_for', '>=', $from)
            ->orderBy('reserved_for')
            ->chunk(1000, function ($reservations) use ($handle): void {
                foreach ($reservations as $reservation) {
                    $handle($reservation->reserved_for, $reservation->party_size);
                }
            });
    }
}
