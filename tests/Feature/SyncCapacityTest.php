<?php

use App\Contracts\SlotAllocator;
use App\Data\DwellWindow;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;

/*
 * The command exists for one failure: a worker dying between taking seats in
 * Redis and writing the reservation row. These simulate exactly that — take
 * seats through the allocator with no booking behind them — because the leak
 * cannot be produced any other way without killing a process mid-request.
 */

function leakSeats(CarbonImmutable $slot, int $seats): void
{
    app(SlotAllocator::class)->allocate(
        DwellWindow::of($slot, (int) config('restaurant.slot_minutes')),
        $seats,
    );
}

function seatsLeftAt(CarbonImmutable $slot): int
{
    return app(SlotAllocator::class)->remainingFor([$slot])[$slot->format('Y-m-d\TH:i')];
}

it('hands back seats that no reservation accounts for', function () {
    $slot = localSlot('19:00');

    app(ReservationService::class)->reserve(draft($slot, partySize: 4));
    leakSeats($slot, 7);

    expect(seatsLeftAt($slot))->toBe(29);   // 40 − 4 booked − 7 leaked

    $this->artisan('reservations:sync-capacity')->assertSuccessful();

    // Only the 7 phantom seats come back. The real booking keeps its 4.
    expect(seatsLeftAt($slot))->toBe(36);
});

it('writes nothing on a dry run', function () {
    $slot = localSlot('19:00');

    app(ReservationService::class)->reserve(draft($slot, partySize: 4));
    leakSeats($slot, 7);

    $this->artisan('reservations:sync-capacity --dry-run')->assertSuccessful();

    expect(seatsLeftAt($slot))->toBe(29);
});

it('never lowers a counter, even when Redis offers more than the database sold', function () {
    $slot = localSlot('19:00');

    app(ReservationService::class)->reserve(draft($slot, partySize: 10));

    /*
     * Force the dangerous direction: Redis believing the slot is emptier than it
     * is. Lowering it back would be the obvious "fix" and is exactly what must
     * not happen — a booking landing between reading Postgres and writing here
     * would be counted twice, and the job meant to keep the ledger honest would
     * cause the overselling it exists to prevent. Undersell, never oversell.
     */
    app(SlotAllocator::class)->release(
        DwellWindow::of($slot, (int) config('restaurant.slot_minutes')),
        10,
    );

    expect(seatsLeftAt($slot))->toBe(40);

    $this->artisan('reservations:sync-capacity')->assertSuccessful();

    expect(seatsLeftAt($slot))->toBe(40);
});

it('leaves slots that have already passed alone', function () {
    /*
     * A past slot's counter has expired by design. Repairing it would recreate a
     * key for a service that is over, and then keep doing so forever.
     */
    $past = localSlot('19:00', daysAhead: -3);

    leakSeats($past, 5);
    $before = seatsLeftAt($past);

    $this->artisan('reservations:sync-capacity')->assertSuccessful();

    expect(seatsLeftAt($past))->toBe($before);
});

it('says so plainly when there is nothing to repair', function () {
    app(ReservationService::class)->reserve(draft(localSlot('19:00'), partySize: 4));

    $this->artisan('reservations:sync-capacity')
        ->expectsOutputToContain('agree on every slot')
        ->assertSuccessful();
});

it('is safe to run twice', function () {
    $slot = localSlot('19:00');

    app(ReservationService::class)->reserve(draft($slot, partySize: 4));
    leakSeats($slot, 7);

    $this->artisan('reservations:sync-capacity')->assertSuccessful();
    $this->artisan('reservations:sync-capacity')->assertSuccessful();

    // Not 43: the repair is a recalculation from the database, not an increment,
    // so running it again cannot invent capacity that does not exist.
    expect(seatsLeftAt($slot))->toBe(36);
});
