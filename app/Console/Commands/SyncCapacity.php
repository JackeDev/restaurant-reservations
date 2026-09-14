<?php

namespace App\Console\Commands;

use App\Contracts\SlotAllocator;
use App\Repositories\ReservationRepository;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give back seats that are held in Redis with no booking behind them.
 *
 * Redis is the source of truth for what is left to sell; PostgreSQL is the source
 * of truth for what was actually sold. Those two can drift in exactly one way: a
 * worker that dies between taking seats in Redis and writing the row leaves the
 * seats taken and no reservation to show for it.
 *
 * That failure is deliberately the pessimistic one — we undersell rather than
 * oversell, because a customer turned away at the door with a confirmation in
 * hand is far worse than an empty table. This command is what stops the
 * pessimism accumulating.
 *
 * It can only ever hand seats back. `reconcile.lua` refuses to lower a counter,
 * and that refusal is the safety property: a booking made between reading
 * PostgreSQL here and writing to Redis would be counted twice, so a command that
 * lowered counters could cause the overselling it exists to prevent. Everything
 * here is therefore safe to run at any time, including mid-service.
 */
class SyncCapacity extends Command
{
    protected $signature = 'reservations:sync-capacity
        {--dry-run : Report what would be repaired without touching Redis}';

    protected $description = 'Return seats held in Redis that no reservation accounts for';

    public function handle(
        SlotAllocator $allocator,
        ReservationRepository $reservations,
        RestaurantClock $clock,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $now = $clock->now();

        /*
         * Only slots still to come. A past slot's counter has expired by design,
         * so "repairing" it would recreate a key for a service that is over.
         */
        $occupancy = array_filter(
            $reservations->occupancyBySlot($now),
            fn (string $id): bool => $this->slotFrom($id)->greaterThanOrEqualTo($now),
            ARRAY_FILTER_USE_KEY,
        );

        if ($occupancy === []) {
            $this->components->info('No upcoming reservations, so there is nothing to reconcile.');

            return self::SUCCESS;
        }

        $capacity = (int) config('restaurant.seats_per_slot');

        /*
         * One MGET for every slot, so the read costs a single round trip however
         * many slots there are. Only the slots that actually disagree are written
         * back, one at a time.
         */
        $remaining = $allocator->remainingFor(
            array_map(fn (string $id): CarbonImmutable => $this->slotFrom($id), array_keys($occupancy))
        );

        $repaired = 0;
        $seatsRecovered = 0;
        $rows = [];

        foreach ($occupancy as $id => $booked) {
            $shouldBe = max(0, $capacity - $booked);
            $isNow = $remaining[$id] ?? $capacity;

            if ($isNow >= $shouldBe) {
                continue;
            }

            $slot = $this->slotFrom($id);
            $recovered = $shouldBe - $isNow;

            if (! $dryRun) {
                $allocator->reconcile($slot, $booked);
            }

            $repaired++;
            $seatsRecovered += $recovered;

            $rows[] = [
                $clock->localDate($slot).' '.$clock->localTime($slot),
                (string) $booked,
                (string) $isNow,
                (string) $shouldBe,
                '<fg=green>+'.$recovered.'</>',
            ];
        }

        return $this->report($rows, count($occupancy), $repaired, $seatsRecovered, $dryRun);
    }

    private function slotFrom(string $id): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $id, 'UTC');
    }

    /**
     * @param  list<array<int, string>>  $rows
     */
    private function report(array $rows, int $checked, int $repaired, int $seats, bool $dryRun): int
    {
        $this->newLine();

        if ($rows !== []) {
            $this->table(['Slot (local)', 'Booked', 'Redis had', 'Should be', 'Recovered'], $rows);
        }

        $this->line(sprintf('  %d upcoming slots checked.', $checked));

        if ($repaired === 0) {
            $this->components->info('Redis and the database agree on every slot. Nothing to do.');

            return self::SUCCESS;
        }

        /*
         * A warning rather than an error: finding leaked seats means the safety
         * net worked, not that something is broken. A run that repairs slots
         * every time, though, says a worker is dying regularly — and that is
         * worth chasing, which is why this is logged rather than only printed.
         */
        Log::warning('capacity.reconciled', [
            'slots_repaired' => $repaired,
            'seats_recovered' => $seats,
            'dry_run' => $dryRun,
        ]);

        $this->components->warn(sprintf(
            '%s %d seats across %d slots that no reservation accounted for.%s',
            $dryRun ? 'Would recover' : 'Recovered',
            $seats,
            $repaired,
            $dryRun ? ' Nothing was written.' : '',
        ));

        return self::SUCCESS;
    }
}
