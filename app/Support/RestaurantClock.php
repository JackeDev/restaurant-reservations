<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The single place where restaurant-local wall-clock time is converted to and
 * from UTC.
 *
 * The rule this class exists to enforce:
 *
 *   - Everything stored is UTC. The `reserved_for` column and the Redis slot
 *     keys both hold the same canonical UTC instant, so a row and its counter
 *     can never disagree about which moment they describe.
 *   - Everything a customer sees or sends is restaurant-local. Opening hours,
 *     the slot grid and the date/time fields of the MCP tool are all local
 *     wall-clock values, because that is what a diner means by "seven o'clock".
 *
 * Conversion happens only at the boundary, here. Nothing downstream should ever
 * call setTimezone() on its own.
 */
final class RestaurantClock
{
    public function timezone(): string
    {
        return config('restaurant.timezone');
    }

    /**
     * Turn a local wall-clock date and time into the canonical UTC instant.
     *
     * @param  string  $date  Local calendar date, "Y-m-d".
     * @param  string  $time  Local time of day, "H:i".
     */
    public function parseLocal(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            "{$date} {$time}",
            $this->timezone()
        )->utc();
    }

    /**
     * Render a UTC instant in the restaurant's timezone, for display only.
     */
    public function toLocal(CarbonImmutable $utc): CarbonImmutable
    {
        return $utc->setTimezone($this->timezone());
    }

    /**
     * The local calendar date of a UTC instant, "Y-m-d".
     */
    public function localDate(CarbonImmutable $utc): string
    {
        return $this->toLocal($utc)->format('Y-m-d');
    }

    /**
     * The local time of day of a UTC instant, "H:i".
     */
    public function localTime(CarbonImmutable $utc): string
    {
        return $this->toLocal($utc)->format('H:i');
    }

    /**
     * The current instant, in UTC.
     *
     * Resolved on every call rather than stored, because a cached "now" on a
     * long-lived object would freeze at worker boot under Octane.
     */
    public function now(): CarbonImmutable
    {
        return Date::now('UTC')->toImmutable();
    }

    /**
     * The local three-letter weekday key used by the opening-hours config.
     */
    public function localWeekday(CarbonImmutable $utc): string
    {
        return strtolower($this->toLocal($utc)->format('D'));
    }
}
