<?php

namespace App\Console\Commands;

use App\Contracts\SlotAllocator;
use App\Data\DwellWindow;
use App\Support\RestaurantClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * The first thing to run when something does not work.
 *
 * Every check here earned its place by being a failure we actually hit, or one
 * that would fail silently rather than loudly — which is the worse kind. The
 * Redis prefix check in particular is a bug that shipped nothing but nearly did:
 * it does not read the configuration and agree with itself, it writes a key
 * through Lua and reads it back the ordinary way, because those are precisely
 * the two paths that can disagree.
 */
class DiagnoseReservations extends Command
{
    protected $signature = 'reservations:doctor';

    protected $description = 'Check that everything this server depends on is present and consistent';

    /** @var list<array{ok: bool, fatal: bool, label: string, detail: string}> */
    private array $results = [];

    public function handle(SlotAllocator $allocator, RestaurantClock $clock): int
    {
        $this->newLine();
        $this->line('  <options=bold>'.config('restaurant.name').'</> — diagnostics');
        $this->newLine();

        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkRedis();
        $this->checkRedisPrefix();
        $this->checkLuaScripts($allocator, $clock);
        $this->checkSlotGrid();
        $this->checkDwellTable();
        $this->checkRuntime();
        $this->checkAiProvider();

        return $this->report();
    }

    private function checkDatabase(): void
    {
        $this->attempt('PostgreSQL reachable', fatal: true, check: function (): string {
            $started = microtime(true);
            DB::connection()->getPdo();
            $ms = round((microtime(true) - $started) * 1000, 2);

            return sprintf('%s, %s ms', config('database.connections.'.config('database.default').'.host'), $ms);
        });
    }

    private function checkMigrations(): void
    {
        $this->attempt('Migrations up to date', fatal: true, check: function (): string {
            $migrator = $this->laravel->make('migrator');

            if (! $migrator->getRepository()->repositoryExists()) {
                throw new CheckFailed('nothing has been migrated yet — run `php artisan migrate`');
            }

            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = array_diff($files, $migrator->getRepository()->getRan());

            if ($pending !== []) {
                throw new CheckFailed(sprintf(
                    '%d pending: %s',
                    count($pending),
                    implode(', ', $pending),
                ));
            }

            return sprintf('%d applied', count($files));
        });
    }

    private function checkRedis(): void
    {
        $this->attempt('Redis reachable', fatal: true, check: function (): string {
            $started = microtime(true);
            Redis::connection('reservations')->ping();
            $ms = round((microtime(true) - $started) * 1000, 3);

            return sprintf(
                '%s db %s, %s ms',
                config('database.redis.reservations.host'),
                config('database.redis.reservations.database'),
                $ms,
            );
        });
    }

    /**
     * The allocator writes seat counters from inside a Lua script and reads them
     * back with a plain MGET. Those are two different code paths through the
     * client, and if they ever address different keys the counters split in two:
     * one that only ever goes down, one that always looks full. No error, no log
     * line, and a restaurant that can be booked without limit.
     *
     * Whether they agree is a property of the client, not of our code. phpredis
     * 6.3 applies the configured key prefix to eval and evalsha keys as well as
     * to ordinary commands, so the two paths line up — measured, not assumed.
     * But that is version- and client-specific behaviour we depend on rather
     * than control, so it is pinned here instead of trusted.
     *
     * Reading the config value would only prove the config agrees with itself,
     * so this writes through one path and reads back through the other.
     */
    private function checkRedisPrefix(): void
    {
        $this->attempt('Lua and plain commands address the same keys', fatal: true, check: function (): string {
            $connection = Redis::connection('reservations');
            $key = 'res:doctor:'.Str::ulid();

            try {
                /*
                 * Loaded and called by SHA rather than sent inline, because that
                 * is exactly what the allocator does on the hot path — a check
                 * that exercised a different path would be testing something we
                 * do not ship.
                 */
                $client = $connection->client();
                $sha = $client->script('load', "redis.call('SET', KEYS[1], '1', 'EX', 30) return 1");
                $client->evalsha($sha, [$key], 1);

                if ($connection->get($key) !== '1') {
                    throw new CheckFailed(
                        'a key written from Lua cannot be read back by GET — seat counters would silently split in two'
                    );
                }
            } finally {
                $connection->del($key);
            }

            return sprintf('verified round trip on phpredis %s', phpversion('redis'));
        });
    }

    private function checkLuaScripts(SlotAllocator $allocator, RestaurantClock $clock): void
    {
        $this->attempt('Lua scripts load and run', fatal: true, check: function () use ($allocator, $clock): string {
            /*
             * Exercised against a slot far enough in the past that no real
             * booking can live there, then released, so running the doctor never
             * consumes a seat someone could have had.
             */
            $slot = $clock->now()->subYears(5)->startOfHour();
            $window = DwellWindow::of($slot, (int) config('restaurant.slot_minutes'));
            $capacity = (int) config('restaurant.seats_per_slot');

            $remaining = $allocator->allocate($window, 1);

            if ($remaining < 0) {
                throw new CheckFailed('allocate refused a seat in an empty slot');
            }

            /*
             * reconcile is checked here rather than taken on trust because it is
             * the one script nothing exercises during a normal request: it runs
             * only from `reservations:sync-capacity`, on a schedule, long after
             * anyone would notice it had stopped working.
             *
             * Its defining property is that it only ever hands seats back. So:
             * ask it to raise the counter (it must), then ask it to lower it
             * (it must refuse). A reconcile that agreed to lower could oversell
             * the very slots it is meant to keep honest.
             */
            if (! $allocator->reconcile($slot, 0)) {
                throw new CheckFailed('reconcile would not return seats to a slot with no bookings');
            }

            if ($allocator->reconcile($slot, $capacity)) {
                throw new CheckFailed('reconcile lowered a counter — it must only ever raise one');
            }

            $allocator->release($window, 1);

            return 'allocate, release, reconcile';
        });
    }

    /**
     * A silent misconfiguration: if a range is not an exact multiple of the slot
     * length, the last part of that service is simply never bookable and nothing
     * anywhere says so.
     */
    private function checkSlotGrid(): void
    {
        $this->attempt('Opening hours divide evenly into slots', fatal: false, check: function (): string {
            $slotMinutes = (int) config('restaurant.slot_minutes');
            $problems = [];
            $ranges = 0;

            foreach ((array) config('restaurant.opening_hours') as $day => $dayRanges) {
                foreach ($dayRanges as [$opens, $closes]) {
                    $ranges++;
                    $minutes = $this->minutesOfDay($closes) - $this->minutesOfDay($opens);

                    if ($minutes <= 0) {
                        $problems[] = "{$day} {$opens}-{$closes} does not move forward";
                    } elseif ($minutes % $slotMinutes !== 0) {
                        $problems[] = "{$day} {$opens}-{$closes} is {$minutes} min, not a multiple of {$slotMinutes}";
                    }
                }
            }

            if ($problems !== []) {
                throw new CheckFailed(implode('; ', $problems));
            }

            return sprintf('%d ranges, %d-minute grid', $ranges, $slotMinutes);
        });
    }

    /**
     * Key 0 is the fallback for parties larger than every listed size. Without
     * it, a party of 30 would get no duration at all.
     */
    private function checkDwellTable(): void
    {
        $this->attempt('Dwell times cover every party size', fatal: false, check: function (): string {
            $table = (array) config('restaurant.dwell_minutes');

            if (! array_key_exists(0, $table)) {
                throw new CheckFailed('no key 0 — parties above the largest listed size have no duration');
            }

            return sprintf('%d bands, %d min for the largest parties', count($table) - 1, $table[0]);
        });
    }

    private function checkRuntime(): void
    {
        $this->attempt('Application server', fatal: false, check: function (): string {
            if (! extension_loaded('swoole')) {
                throw new CheckFailed('Swoole is not loaded — Octane cannot run, expect roughly 1/37th of the throughput');
            }

            return sprintf('Swoole %s available, %d CPUs visible', phpversion('swoole'), swoole_cpu_num());
        });
    }

    /**
     * Optional by design: without a key the natural-language level simply never
     * runs, and Carbon handles what it can. Reported so that a reviewer who
     * added a key and saw no change learns why here rather than by guessing.
     */
    private function checkAiProvider(): void
    {
        $this->attempt('AI date parsing (optional)', fatal: false, check: function (): string {
            if (blank(config('ai.providers.anthropic.key'))) {
                throw new CheckFailed('no ANTHROPIC_API_KEY — natural language falls back to Carbon only');
            }

            return 'Anthropic key configured';
        });
    }

    private function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * @param  callable(): string  $check
     */
    private function attempt(string $label, bool $fatal, callable $check): void
    {
        try {
            $this->record(true, $fatal, $label, $check());
        } catch (Throwable $e) {
            $this->record(false, $fatal, $label, $e->getMessage());
        }
    }

    private function record(bool $ok, bool $fatal, string $label, string $detail): void
    {
        $this->results[] = compact('ok', 'fatal', 'label', 'detail');

        $mark = match (true) {
            $ok => '<fg=green>✓</>',
            $fatal => '<fg=red>✗</>',
            default => '<fg=yellow>!</>',
        };

        $this->line(sprintf('  %s %-46s <fg=gray>%s</>', $mark, $label, $detail));
    }

    private function report(): int
    {
        $broken = array_filter($this->results, fn (array $r): bool => ! $r['ok'] && $r['fatal']);
        $warnings = array_filter($this->results, fn (array $r): bool => ! $r['ok'] && ! $r['fatal']);

        $this->newLine();

        if ($broken !== []) {
            $this->components->error(sprintf(
                '%d check%s failed. The server will not work correctly until fixed.',
                count($broken),
                count($broken) === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        if ($warnings !== []) {
            $this->components->warn(sprintf(
                '%d optional check%s not satisfied. The server works; some features are reduced.',
                count($warnings),
                count($warnings) === 1 ? '' : 's',
            ));

            return self::SUCCESS;
        }

        $this->components->info('Everything this server depends on is present and consistent.');

        return self::SUCCESS;
    }
}
