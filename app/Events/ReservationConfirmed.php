<?php

namespace App\Events;

use App\Data\ConfirmedReservation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking that was made.
 *
 * Carries plain values rather than the Eloquent row on purpose. A listener that
 * walked a relation would pay for a query during the request it is meant to be
 * observing, and a model held by a queued listener can go stale; neither is a
 * risk a value object has.
 */
final class ReservationConfirmed
{
    use Dispatchable;

    public function __construct(
        public readonly ConfirmedReservation $reservation,
        public readonly int $seatsLeftInWindow,
    ) {}
}
