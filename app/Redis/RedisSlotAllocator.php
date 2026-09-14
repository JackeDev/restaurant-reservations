<?php

namespace App\Redis;

use App\Contracts\SlotAllocator;
use App\Data\DwellWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Date;
use RedisException;

/**
 * Seat allocation backed by atomic Redis Lua scripts.
 *
 * Registered as a singleton. That is safe under Octane because nothing here is
 * request scoped: the only state is the connection factory and the cached SHA
 * of each script, which is the hash of a fixed string and therefore identical
 * for every request. Booking details arrive as method arguments, never as
 * properties.
 */
class RedisSlotAllocator implements SlotAllocator
{
    /**
     * Slot keys are namespaced with a hash tag so that, on a Redis Cluster, all
     * of a restaurant's slots live on one node. That is a requirement rather
     * than a nicety: allocate touches several keys at once, and Redis Cluster
     * refuses a script whose keys are spread across nodes.
     */
    private const KEY_PREFIX = 'res:seats:{main}:';

    /**
     * How long a slot counter outlives the service it belongs to. Keeps the
     * counter available during and just after the sitting, then lets Redis
     * expire it so dead slots cannot accumulate forever.
     */
    private const TTL_GRACE_SECONDS = 86400;

    /**
     * Cached SHA-1 of each loaded script, keyed by script name.
     *
     * @var array<string, string>
     */
    private array $scriptHashes = [];

    public function __construct(private readonly RedisFactory $redis) {}

    public function allocate(DwellWindow $window, int $partySize): int
    {
        $keys = $this->keysFor($window->slots);

        return (int) $this->run('allocate', $keys, [
            $this->capacity(),
            $partySize,
            $this->ttlFor($window->startsAt),
        ]);
    }

    public function release(DwellWindow $window, int $partySize): void
    {
        $this->run('release', $this->keysFor($window->slots), [
            $partySize,
            $this->capacity(),
        ]);
    }

    public function remainingFor(array $slots): array
    {
        if ($slots === []) {
            return [];
        }

        $capacity = $this->capacity();
        $values = $this->connection()->mget($this->keysFor($slots));

        $remaining = [];

        foreach (array_values($slots) as $index => $slot) {
            $value = $values[$index] ?? null;

            // A missing key means the slot has never been touched, so it is
            // still completely empty.
            $remaining[$this->slotId($slot)] = $value === null || $value === false
                ? $capacity
                : (int) $value;
        }

        return $remaining;
    }

    public function reconcile(CarbonImmutable $slot, int $bookedSeats): bool
    {
        return (int) $this->run('reconcile', [$this->keyFor($slot)], [
            $this->capacity(),
            $bookedSeats,
            $this->ttlFor($slot),
        ]) === 1;
    }

    /**
     * Run a Lua script by its cached SHA, loading it on first use.
     *
     * Deliberately talks to the raw phpredis client rather than Laravel's
     * wrapper: the wrapper's evalsha() re-runs SCRIPT LOAD on every call, which
     * would double the round trips on the hottest path in the application.
     *
     * Redis forgets loaded scripts when it restarts, so NOSCRIPT is an expected
     * condition rather than a failure: reload and retry once.
     *
     * @param  list<string>  $keys
     * @param  list<int|string>  $arguments
     */
    private function run(string $script, array $keys, array $arguments): mixed
    {
        $client = $this->client();

        try {
            return $client->evalsha($this->hashFor($script), [...$keys, ...$arguments], count($keys));
        } catch (RedisException $e) {
            if (! str_contains($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }

            unset($this->scriptHashes[$script]);

            return $client->evalsha($this->hashFor($script), [...$keys, ...$arguments], count($keys));
        }
    }

    private function hashFor(string $script): string
    {
        return $this->scriptHashes[$script] ??= $this->client()->script(
            'load',
            $this->source($script)
        );
    }

    private function source(string $script): string
    {
        return file_get_contents(__DIR__."/Scripts/{$script}.lua");
    }

    /**
     * The allocator's own connection, configured without a key prefix so that
     * Lua scripts and ordinary commands address the same keys.
     */
    private function connection(): mixed
    {
        return $this->redis->connection('reservations');
    }

    /**
     * The underlying phpredis client.
     */
    private function client(): mixed
    {
        return $this->connection()->client();
    }

    /**
     * @param  list<CarbonImmutable>  $slots
     * @return list<string>
     */
    private function keysFor(array $slots): array
    {
        return array_map(fn (CarbonImmutable $slot): string => $this->keyFor($slot), array_values($slots));
    }

    private function keyFor(CarbonImmutable $slot): string
    {
        return self::KEY_PREFIX.$this->slotId($slot);
    }

    /**
     * Canonical identifier for a slot: the UTC instant, to the minute.
     *
     * Using UTC rather than local wall-clock time means a Redis counter and its
     * database row can never disagree about which moment they describe.
     */
    private function slotId(CarbonImmutable $slot): string
    {
        return $slot->utc()->format('Y-m-d\TH:i');
    }

    private function capacity(): int
    {
        return (int) config('restaurant.seats_per_slot');
    }

    /**
     * Seconds until the slot has been and gone, plus a day of grace.
     *
     * Past slots still get a positive TTL so that reconciliation and late
     * cancellations have something to work with.
     */
    private function ttlFor(CarbonImmutable $slot): int
    {
        $secondsUntil = Date::now('UTC')->diffInSeconds($slot->utc(), false);

        return (int) max(60, $secondsUntil + self::TTL_GRACE_SECONDS);
    }
}
