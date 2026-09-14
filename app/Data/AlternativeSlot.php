<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A bookable time offered when the requested one is full.
 */
final readonly class AlternativeSlot
{
    /**
     * @param  CarbonImmutable  $startsAt  UTC.
     * @param  int  $seatsAvailable  Seats free in the tightest slot of this
     *                               option's dwell window, so the party really
     *                               can be seated for their whole stay.
     */
    public function __construct(
        public CarbonImmutable $startsAt,
        public int $seatsAvailable,
    ) {}
}
