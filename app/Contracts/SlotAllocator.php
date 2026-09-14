<?php

namespace App\Contracts;

use App\Data\DwellWindow;
use Carbon\CarbonImmutable;

/**
 * Decides whether a booking fits, and takes the seats if it does.
 *
 * This is the only place in the application where overselling can be prevented,
 * so the contract is deliberately narrow: allocate either takes every seat the
 * booking needs or takes none at all, with no observable state in between.
 */
interface SlotAllocator
{
    /**
     * Take seats across every slot in the window, all or nothing.
     *
     * @return int Seats left in the tightest slot of the window, or -1 when the
     *             booking does not fit.
     */
    public function allocate(DwellWindow $window, int $partySize): int;

    /**
     * Give seats back across the window. Used to compensate a failed write.
     */
    public function release(DwellWindow $window, int $partySize): void;

    /**
     * Seats left in each of the given slots.
     *
     * Answered in a single round trip so that evaluating many candidate times
     * costs no more than evaluating one.
     *
     * @param  list<CarbonImmutable>  $slots  UTC instants.
     * @return array<string, int> Keyed by the slot's ISO-8601 minute, "Y-m-d\TH:i".
     */
    public function remainingFor(array $slots): array;

    /**
     * Overwrite a slot's remaining seats, given how many are genuinely booked.
     *
     * Only ever raises the counter, never lowers it.
     *
     * @return bool Whether the counter needed repairing.
     */
    public function reconcile(CarbonImmutable $slot, int $bookedSeats): bool;
}
