<?php

namespace App\Console\Commands;

use App\Contracts\SlotAllocator;
use App\Repositories\ReservationRepository;
use App\Services\OpeningHours;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Proves, from the database alone, that no slot has been oversold.
 *
 * This is the check the load test is pointed at: k6 shows the server survives
 * the traffic, and this shows the bookings it accepted are actually servable.
 * Either without the other proves very little.
 */
class VerifyReservations extends Command
{
    protected $signature = 'reservations:verify
        {--date= : Only report on this local date, YYYY-MM-DD}
        {--all : Include slots that have already passed}
        {--quiet-ok : Print only the slots with a problem}';

    protected $description = 'Check that no slot is oversold and that Redis agrees with the database';

    public function handle(
        SlotAllocator $allocator,
        OpeningHours $openingHours,
        RestaurantClock $clock,
        ReservationRepository $reservations,
    ): int {
        $capacity = (int) config('restaurant.seats_per_slot');

        $occupancy = $reservations->occupancyBySlot($clock->now()->subDay());

        if ($occupancy === []) {
            $this->components->info('No confirmed reservations to check.');

            return self::SUCCESS;
        }

        $slots = $this->slotsToReport(array_keys($occupancy), $clock);

        if ($slots === []) {
            $this->components->info('No reservations in the reporting window. Try --all or --date.');

            return self::SUCCESS;
        }

        /*
         * One round trip for every slot: the allocator already fetches a whole
         * set of counters with a single MGET.
         */
        $remaining = $allocator->remainingFor(array_values($slots));

        [$rows, $oversold, $unsafe, $leaked] = $this->compare($slots, $occupancy, $remaining, $capacity, $clock);

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>%s</> — %d seats per %d-minute slot',
            config('restaurant.name'),
            $capacity,
            (int) config('restaurant.slot_minutes'),
        ));
        $this->newLine();

        if ($rows !== []) {
            $this->table(['Slot (local)', 'Booked', 'Free', 'Redis', 'Status'], $rows);
        }

        $this->summarise(count($slots), $oversold, $unsafe, $leaked);
        $this->reportBookingsOutsideOpeningHours($openingHours, $clock, $reservations);

        return $oversold > 0 || $unsafe > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Which of the occupied slots to actually report on.
     *
     * Past slots are excluded by default: their Redis counters have expired by
     * design, so comparing them would report drift that is simply housekeeping.
     *
     * @param  list<string>  $slotIds
     * @return array<string, CarbonImmutable>
     */
    private function slotsToReport(array $slotIds, RestaurantClock $clock): array
    {
        $onlyDate = $this->option('date');
        $now = $clock->now();
        $slots = [];

        foreach ($slotIds as $id) {
            $slot = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $id, 'UTC');

            if (! $this->option('all') && $slot->lessThan($now)) {
                continue;
            }

            if ($onlyDate !== null && $clock->localDate($slot) !== $onlyDate) {
                continue;
            }

            $slots[$id] = $slot;
        }

        return $slots;
    }

    /**
     * @param  array<string, CarbonImmutable>  $slots
     * @param  array<string, int>  $occupancy
     * @param  array<string, int>  $remaining
     * @return array{0: list<array<int, string>>, 1: int, 2: int, 3: int}
     */
    private function compare(array $slots, array $occupancy, array $remaining, int $capacity, RestaurantClock $clock): array
    {
        $rows = [];
        $oversold = 0;
        $unsafe = 0;
        $leaked = 0;

        foreach ($slots as $id => $slot) {
            $booked = $occupancy[$id];
            $free = $capacity - $booked;
            $redis = $remaining[$id] ?? $capacity;

            [$status, $kind] = match (true) {
                $booked > $capacity => ['<fg=red;options=bold>OVERSOLD by '.($booked - $capacity).'</>', 'oversold'],

                /*
                 * Redis believing more seats are free than the database can
                 * account for is the dangerous direction: those seats can be
                 * sold twice. The opposite is the harmless one — seats held by
                 * a booking that never landed, which sync-capacity recovers.
                 */
                $redis > $free => ['<fg=red>UNSAFE: Redis has '.($redis - $free).' too many</>', 'unsafe'],
                $redis < $free => ['<fg=yellow>'.($free - $redis).' seats held but unsold</>', 'leaked'],

                default => ['<fg=green>OK</>', 'ok'],
            };

            match ($kind) {
                'oversold' => $oversold++,
                'unsafe' => $unsafe++,
                'leaked' => $leaked++,
                default => null,
            };

            if ($kind === 'ok' && $this->option('quiet-ok')) {
                continue;
            }

            $rows[] = [
                $clock->localDate($slot).' '.$clock->localTime($slot),
                (string) $booked,
                (string) $free,
                (string) $redis,
                $status,
            ];
        }

        return [$rows, $oversold, $unsafe, $leaked];
    }

    private function summarise(int $checked, int $oversold, int $unsafe, int $leaked): void
    {
        $this->newLine();
        $this->line(sprintf('  %d slots checked.', $checked));

        if ($oversold === 0 && $unsafe === 0) {
            $this->components->info('No slot is oversold and Redis never claims more seats than the database allows.');
        }

        if ($oversold > 0) {
            $this->components->error(sprintf('%d slots are oversold. The allocator let through more seats than exist.', $oversold));
        }

        if ($unsafe > 0) {
            $this->components->error(sprintf('%d slots where Redis offers seats the database has already sold.', $unsafe));
        }

        if ($leaked > 0) {
            $this->components->warn(sprintf(
                '%d slots hold seats with no booking behind them. Harmless — we undersell, never oversell — and `reservations:sync-capacity` returns them.',
                $leaked,
            ));
        }
    }

    /**
     * Confirmed bookings that no longer sit inside the configured opening hours.
     *
     * Changing the hours never cancels anything: a confirmed reservation is a
     * promise, not a value derived from current config. So the only correct
     * thing to do is say which ones the restaurant still has to honour.
     */
    private function reportBookingsOutsideOpeningHours(
        OpeningHours $openingHours,
        RestaurantClock $clock,
        ReservationRepository $reservations,
    ): void {
        $stragglers = [];

        $reservations->eachUpcoming(
            $clock->now(),
            function (CarbonImmutable $slot, int $partySize) use ($openingHours, $clock, &$stragglers): void {
                if ($openingHours->isBookable($slot)) {
                    return;
                }

                $date = $clock->localDate($slot);
                $stragglers[$date]['count'] = ($stragglers[$date]['count'] ?? 0) + 1;
                $stragglers[$date]['guests'] = ($stragglers[$date]['guests'] ?? 0) + $partySize;
            },
        );

        if ($stragglers === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('Confirmed bookings now fall outside the configured opening hours:');

        foreach ($stragglers as $date => $totals) {
            $this->line(sprintf('    %s — %d bookings, %d guests', $date, $totals['count'], $totals['guests']));
        }

        $this->line('    These remain valid. Honour them, or contact the customers to reschedule.');
    }
}
