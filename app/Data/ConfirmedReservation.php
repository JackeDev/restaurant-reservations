<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A booking that was made, as plain values.
 *
 * This exists so the Eloquent model never leaves ReservationService. Carrying
 * the model outward looked harmless but cost a query on the hottest path: the
 * MCP tool read $reservation->customer->name, which lazily fetched a customer
 * row to get a name the request had already supplied.
 *
 * Everything here is known before the database is touched — even the reference,
 * which is generated in PHP — so building it costs nothing. What it buys is that
 * swapping how reservations are stored only touches the service that stores
 * them, not the tool that reports them.
 */
final readonly class ConfirmedReservation
{
    /**
     * @param  CarbonImmutable  $reservedFor  UTC.
     */
    public function __construct(
        public string $reference,
        public CarbonImmutable $reservedFor,
        public DwellWindow $window,
        public int $partySize,
        public string $customerName,
        public ?string $notes,
    ) {}
}
