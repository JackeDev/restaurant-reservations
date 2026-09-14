<?php

namespace App\Repositories;

use App\Data\ConfirmedReservation;
use App\Data\DwellWindow;
use App\Data\ReservationDraft;
use App\Enums\ReservationStatus;
use App\Models\Reservation;

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
}
