<?php

namespace App\Services;

use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;

/**
 * Answers whether a given moment is a slot the restaurant can be booked for.
 *
 * Opening hours are a local, human concept, so everything here converts the
 * incoming UTC instant to restaurant-local time before reasoning about it.
 */
class OpeningHours
{
    public function __construct(private readonly RestaurantClock $clock) {}

    /**
     * Whether a booking may start at this instant.
     *
     * @param  CarbonImmutable  $slot  UTC.
     */
    public function isBookable(CarbonImmutable $slot): bool
    {
        return $this->isOnGrid($slot) && $this->isWithinOpeningHours($slot);
    }

    /**
     * Whether the instant falls on the booking grid, for example on the hour
     * and the half hour for 30-minute slots.
     *
     * @param  CarbonImmutable  $slot  UTC.
     */
    public function isOnGrid(CarbonImmutable $slot): bool
    {
        $local = $this->clock->toLocal($slot);
        $minutesIntoDay = ($local->hour * 60) + $local->minute;

        return $local->second === 0
            && $minutesIntoDay % (int) config('restaurant.slot_minutes') === 0;
    }

    /**
     * Whether the restaurant is taking bookings at this local time.
     *
     * The closing time is the last moment a booking may start, exclusive. A
     * table may still be occupied after closing, which is how restaurants
     * actually work.
     *
     * @param  CarbonImmutable  $slot  UTC.
     */
    public function isWithinOpeningHours(CarbonImmutable $slot): bool
    {
        $local = $this->clock->toLocal($slot);
        $minutesIntoDay = ($local->hour * 60) + $local->minute;

        foreach ($this->rangesFor($slot) as [$opens, $closes]) {
            if ($minutesIntoDay >= $this->toMinutes($opens) && $minutesIntoDay < $this->toMinutes($closes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The opening ranges that apply on the local day of this instant.
     *
     * @param  CarbonImmutable  $slot  UTC.
     * @return list<array{string, string}>
     */
    public function rangesFor(CarbonImmutable $slot): array
    {
        return config('restaurant.opening_hours.'.$this->clock->localWeekday($slot), []);
    }

    /**
     * Human-readable opening hours for the local day of this instant.
     *
     * @param  CarbonImmutable  $slot  UTC.
     * @return list<string>
     */
    public function describeFor(CarbonImmutable $slot): array
    {
        return array_map(
            fn (array $range): string => "{$range[0]}-{$range[1]}",
            $this->rangesFor($slot)
        );
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
