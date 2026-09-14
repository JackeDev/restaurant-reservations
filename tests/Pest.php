<?php

use App\Data\ReservationDraft;
use App\Enums\BookingChannel;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 * Unit tests boot the framework too, without the database. The rules being
 * tested — how long a party occupies a table, how a stay maps onto slots — are
 * read from config/restaurant.php, so they are only "unit" in the sense that
 * they touch neither storage nor HTTP.
 */
pest()->extend(TestCase::class)->in('Unit');

uses()->beforeEach(function (): void {
    /*
     * Freeze the clock. Nearly every rule here is time-relative — opening hours,
     * "is that in the past", how far the alternative search may look — so a test
     * suite on a live clock passes at noon and fails at midnight.
     *
     * A Monday, before service, so both lunch and dinner are still ahead.
     */
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 10:00', config('restaurant.timezone')));

    /*
     * The seat allocator talks to a real Redis rather than a fake, because the
     * thing worth testing is the Lua script itself. phpunit.xml points it at its
     * own database; this empties it so no test inherits another's counters.
     */
    Redis::connection('reservations')->flushdb();
})->in('Feature', 'Unit');

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A UTC instant for a local wall-clock time, relative to the frozen "now".
 *
 * Tests read better saying "lunch on the day after tomorrow" than carrying
 * literal dates that have to be re-checked against the opening hours by hand.
 */
function localSlot(string $time, int $daysAhead = 0): CarbonImmutable
{
    $clock = app(RestaurantClock::class);

    return $clock->parseLocal(
        $clock->toLocal($clock->now())->addDays($daysAhead)->format('Y-m-d'),
        $time,
    );
}

/**
 * A validated booking, with only the parts a test cares about spelled out.
 */
function draft(CarbonImmutable $at, int $partySize = 2, ?string $email = null, ?string $notes = null): ReservationDraft
{
    return new ReservationDraft(
        customerName: 'Test Diner',
        customerEmail: $email ?? 'diner'.$partySize.'@example.com',
        customerPhone: null,
        partySize: $partySize,
        requestedFor: $at,
        notes: $notes,
        channel: BookingChannel::Mcp,
    );
}
