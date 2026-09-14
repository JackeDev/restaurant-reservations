<?php

namespace App\Services;

use App\Contracts\SlotAllocator;
use App\Data\AlternativeSlot;
use App\Data\DwellWindow;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;

/**
 * Suggests nearby times when the requested one cannot be booked.
 *
 * Searching outward — 18:30 and 19:30 before 22:00 — is what makes a suggestion
 * feel like a real answer rather than whatever happened to be free.
 *
 * Closed hours are stepped over rather than counted against the search. The
 * reason matters: an enquiry for a Sunday evening, when the restaurant only
 * opens for lunch, has no free slot anywhere near it, and answering it with
 * silence is the least useful thing we could do. Sunday lunch and Monday
 * evening are both real answers, and both are what a person on the phone would
 * offer.
 */
class AlternativeSlotFinder
{
    public function __construct(
        private readonly SlotAllocator $allocator,
        private readonly OpeningHours $openingHours,
        private readonly RestaurantClock $clock,
    ) {}

    /**
     * Find bookable times near the one the customer asked for.
     *
     * @param  CarbonImmutable  $around  UTC instant that could not be booked.
     * @return list<AlternativeSlot> Closest first.
     */
    public function find(CarbonImmutable $around, int $partySize): array
    {
        $now = $this->clock->now();

        /*
         * Looking later starts from the present when the request is already in
         * the past. Anchored on the request itself, a date from last week would
         * spend its whole horizon still in the past and come back with nothing,
         * when what the customer needs to hear is what is free from now on.
         */
        $earlier = $this->walkFrom($around, -1, $now);
        $later = $this->walkFrom(
            $around->greaterThan($now) ? $around : $this->gridBoundaryAtOrBefore($now),
            1,
            $now,
        );

        if ($earlier === [] && $later === []) {
            return [];
        }

        /*
         * A candidate is only usable if every slot of its dwell window has
         * room, so we need the seat counts for the union of all those windows.
         * Fetching them in one round trip keeps evaluating twelve candidates as
         * cheap as evaluating one.
         */
        $windows = [];
        $union = [];

        foreach ([...$earlier, ...$later] as $candidate) {
            $window = DwellWindow::forParty($candidate, $partySize);
            $windows[$this->slotId($candidate)] = $window;

            foreach ($window->slots as $slot) {
                $union[$this->slotId($slot)] = $slot;
            }
        }

        $remaining = $this->allocator->remainingFor(array_values($union));

        return $this->pick(
            $this->viable($earlier, $partySize, $windows, $remaining),
            $this->viable($later, $partySize, $windows, $remaining),
            $around,
        );
    }

    /**
     * Step away from the requested time in one direction, collecting the times
     * the restaurant is open for.
     *
     * The horizon bounds the walk in wall-clock time rather than in candidates,
     * so a stretch of closed hours costs steps but not results.
     *
     * @param  CarbonImmutable  $from  UTC instant to walk away from.
     * @param  int  $direction  -1 to look earlier, 1 to look later.
     * @param  CarbonImmutable  $now  UTC.
     * @return list<CarbonImmutable> Closest first.
     */
    private function walkFrom(CarbonImmutable $from, int $direction, CarbonImmutable $now): array
    {
        $slotMinutes = (int) config('restaurant.slot_minutes');
        $wanted = (int) config('reservations.alternative_candidates_per_direction');
        $maxSteps = intdiv((int) config('reservations.alternative_horizon_hours') * 60, $slotMinutes);

        $found = [];

        for ($step = 1; $step <= $maxSteps && count($found) < $wanted; $step++) {
            $candidate = $from->addMinutes($direction * $step * $slotMinutes);

            if ($candidate->lessThanOrEqualTo($now)) {
                // Looking earlier has reached the present. Nothing further back
                // can be offered, so stop instead of spending the horizon.
                if ($direction < 0) {
                    break;
                }

                continue;
            }

            if ($this->openingHours->isBookable($candidate)) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    /**
     * Keep the candidates that can seat the party for their whole stay.
     *
     * @param  list<CarbonImmutable>  $candidates  Closest first.
     * @param  array<string, DwellWindow>  $windows
     * @param  array<string, int>  $remaining
     * @return list<AlternativeSlot> Closest first.
     */
    private function viable(array $candidates, int $partySize, array $windows, array $remaining): array
    {
        $viable = [];

        foreach ($candidates as $candidate) {
            $seats = $this->seatsAvailableAcross($windows[$this->slotId($candidate)], $remaining);

            if ($seats >= $partySize) {
                $viable[] = new AlternativeSlot($candidate, $seats);
            }
        }

        return $viable;
    }

    /**
     * Choose what to offer: the nearest time on each side first, then the next
     * closest from whichever side it falls on.
     *
     * Leading with one from each side is what makes a closed evening answerable.
     * Ranking purely by proximity would fill every suggestion with that day's
     * lunch and never mention that the following evening is open — and the
     * customer who asked for dinner wants to hear about dinner.
     *
     * @param  list<AlternativeSlot>  $earlier  Closest first.
     * @param  list<AlternativeSlot>  $later  Closest first.
     * @return list<AlternativeSlot> Closest first.
     */
    private function pick(array $earlier, array $later, CarbonImmutable $around): array
    {
        $limit = (int) config('reservations.alternative_limit');

        $byProximity = fn (AlternativeSlot $a, AlternativeSlot $b): int => abs($a->startsAt->getTimestamp() - $around->getTimestamp())
            <=> abs($b->startsAt->getTimestamp() - $around->getTimestamp());

        // array_filter drops the nulls left by a side that had nothing.
        $picked = array_values(array_filter([array_shift($earlier), array_shift($later)]));

        $rest = [...$earlier, ...$later];
        usort($rest, $byProximity);

        foreach ($rest as $alternative) {
            if (count($picked) >= $limit) {
                break;
            }

            $picked[] = $alternative;
        }

        $picked = array_slice($picked, 0, $limit);
        usort($picked, $byProximity);

        return $picked;
    }

    /**
     * Seats a party could actually take for their whole stay: the tightest slot
     * in the window, since one full slot makes the entire booking impossible.
     *
     * @param  array<string, int>  $remaining
     */
    private function seatsAvailableAcross(DwellWindow $window, array $remaining): int
    {
        $seats = PHP_INT_MAX;

        foreach ($window->slots as $slot) {
            $seats = min($seats, $remaining[$this->slotId($slot)] ?? 0);
        }

        return $seats;
    }

    /**
     * The booking-grid boundary at or before an instant.
     *
     * Walking has to start on the grid, because anything off it is rejected as
     * unbookable and the whole horizon would come back empty.
     */
    private function gridBoundaryAtOrBefore(CarbonImmutable $instant): CarbonImmutable
    {
        $slotMinutes = (int) config('restaurant.slot_minutes');
        $local = $this->clock->toLocal($instant);
        $minutesIntoDay = ($local->hour * 60) + $local->minute;

        return $local->startOfDay()
            ->addMinutes(intdiv($minutesIntoDay, $slotMinutes) * $slotMinutes)
            ->utc();
    }

    /**
     * The key a slot is known by, matching the allocator's own identifier.
     */
    private function slotId(CarbonImmutable $slot): string
    {
        return $slot->format('Y-m-d\TH:i');
    }
}
