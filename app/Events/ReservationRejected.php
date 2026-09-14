<?php

namespace App\Events;

use App\Data\ReservationDraft;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking that could not be made.
 *
 * Carries why, not just that it happened: "full", "closed", "off_grid" and
 * "in_past" are very different problems, and the difference is what makes the
 * logs worth reading.
 */
final class ReservationRejected
{
    use Dispatchable;

    public function __construct(
        public readonly ReservationDraft $draft,
        public readonly string $code,
        public readonly string $reason,
        public readonly int $alternativesOffered,
    ) {}
}
