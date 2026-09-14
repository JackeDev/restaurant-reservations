<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * The set of slots a booking occupies.
 *
 * A table booked at 19:00 is not free again at 19:30, so a reservation consumes
 * every slot its stay spans rather than only the one it starts in. This object
 * is what turns "19:00 for four people" into "19:00, 19:30 and 20:00".
 *
 * Every instant here is UTC: windows are built from an already-converted start
 * time, and the slots they produce become Redis keys and database values
 * directly. Converting to restaurant-local time is RestaurantClock's job.
 *
 * Immutable, and built per call: it must never be cached on a long-lived object
 * under Octane.
 */
final readonly class DwellWindow
{
    /**
     * @param  CarbonImmutable  $startsAt  UTC.
     * @param  CarbonImmutable  $endsAt  UTC.
     * @param  list<CarbonImmutable>  $slots  Every slot the booking occupies, in order, UTC.
     */
    private function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $durationMinutes,
        public array $slots,
    ) {}

    /**
     * Build the window for a new booking, deriving the stay length from the
     * party size.
     *
     * @param  CarbonImmutable  $startsAt  UTC instant the booking starts at.
     */
    public static function forParty(CarbonImmutable $startsAt, int $partySize): self
    {
        return self::of($startsAt, self::minutesForParty($partySize));
    }

    /**
     * Build the window from an already-stored duration.
     *
     * Existing reservations keep the duration they were created with, so that
     * changing the configured dwell times never rewrites history.
     *
     * @param  CarbonImmutable  $startsAt  UTC instant the booking starts at.
     */
    public static function of(CarbonImmutable $startsAt, int $durationMinutes): self
    {
        $slotMinutes = (int) config('restaurant.slot_minutes');

        // Round up: half a slot cannot be sold to anyone else.
        $slotCount = max(1, (int) ceil($durationMinutes / $slotMinutes));

        $slots = [];

        for ($i = 0; $i < $slotCount; $i++) {
            $slots[] = $startsAt->addMinutes($i * $slotMinutes);
        }

        return new self(
            startsAt: $startsAt,
            endsAt: $startsAt->addMinutes($slotCount * $slotMinutes),
            durationMinutes: $durationMinutes,
            slots: $slots,
        );
    }

    /**
     * How long a party of this size occupies the table.
     *
     * The configured table is keyed by the largest party size each duration
     * applies to, with key 0 as the fallback for anything bigger.
     */
    public static function minutesForParty(int $partySize): int
    {
        /** @var array<int, int> $table */
        $table = config('restaurant.dwell_minutes');

        $tiers = array_filter($table, fn (int $maxSize): bool => $maxSize > 0, ARRAY_FILTER_USE_KEY);
        ksort($tiers);

        foreach ($tiers as $maxSize => $minutes) {
            if ($partySize <= $maxSize) {
                return $minutes;
            }
        }

        return $table[0];
    }

    /**
     * The number of slots this booking blocks.
     */
    public function slotCount(): int
    {
        return count($this->slots);
    }
}
