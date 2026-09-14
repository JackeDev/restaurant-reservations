<?php

namespace App\Data;

/**
 * The outcome of trying to book a table.
 *
 * "Unavailable" is modelled as a normal result carrying alternatives, not as an
 * exception. A caller — very often an AI agent — needs to be able to act on the
 * suggestion and offer the customer another time, which it cannot do if the
 * call simply failed.
 *
 * Deliberately free of Eloquent: a confirmed booking travels as plain values so
 * that nothing above the service has to know how reservations are stored.
 */
final readonly class ReservationResult
{
    /**
     * @param  list<AlternativeSlot>  $alternatives
     */
    private function __construct(
        public ReservationOutcome $outcome,
        public ?ConfirmedReservation $reservation,
        public ?string $reason,
        public array $alternatives,
    ) {}

    public static function confirmed(ConfirmedReservation $reservation): self
    {
        return new self(ReservationOutcome::Confirmed, $reservation, null, []);
    }

    /**
     * @param  list<AlternativeSlot>  $alternatives
     */
    public static function unavailable(string $reason, array $alternatives = []): self
    {
        return new self(ReservationOutcome::Unavailable, null, $reason, $alternatives);
    }

    public function isConfirmed(): bool
    {
        return $this->outcome === ReservationOutcome::Confirmed;
    }
}
