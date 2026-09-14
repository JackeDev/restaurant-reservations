<?php

use App\Contracts\SlotAllocator;
use App\Data\DwellWindow;
use Illuminate\Support\Facades\Redis;

/*
 * The allocator is where correctness under concurrency actually lives, so these
 * run against a real Redis rather than a fake. A fake would only prove our
 * understanding of the Lua script, which is the thing in doubt.
 */

beforeEach(function (): void {
    $this->allocator = app(SlotAllocator::class);
    $this->window = DwellWindow::forParty(localSlot('19:00'), 4);
});

it('seeds a slot to full capacity the first time it is touched', function () {
    // No counter exists yet; the script must treat that as an empty slot rather
    // than as zero seats, without any pre-warming step.
    expect($this->allocator->allocate($this->window, 4))->toBe(36);
});

it('reports the tightest slot in the window, not the first', function () {
    // Take seats from the middle slot only, so the window is uneven.
    $middle = DwellWindow::of($this->window->slots[1], 30);
    $this->allocator->allocate($middle, 10);

    // 19:00 and 20:00 have 40, 19:30 has 30. Booking 4 leaves 26 at the tightest.
    expect($this->allocator->allocate($this->window, 4))->toBe(26);
});

it('refuses the whole booking when a single slot is short', function () {
    $middle = DwellWindow::of($this->window->slots[1], 30);
    $this->allocator->allocate($middle, 39);

    expect($this->allocator->allocate($this->window, 4))->toBe(-1);
});

it('leaves every slot untouched when it refuses', function () {
    $middle = DwellWindow::of($this->window->slots[1], 30);
    $this->allocator->allocate($middle, 39);

    $before = $this->allocator->remainingFor($this->window->slots);
    $this->allocator->allocate($this->window, 4);
    $after = $this->allocator->remainingFor($this->window->slots);

    /*
     * The two-pass script exists for this. Checking and decrementing in one pass
     * would leave the earlier slots already spent when a later one vetoes.
     */
    expect($after)->toBe($before);
});

it('gives the seats back when a booking is released', function () {
    $this->allocator->allocate($this->window, 10);
    $this->allocator->release($this->window, 10);

    expect(array_values($this->allocator->remainingFor($this->window->slots)))->each->toBe(40);
});

it('never restores more seats than the room has', function () {
    $this->allocator->allocate($this->window, 10);

    // A double release must not invent capacity out of nothing.
    $this->allocator->release($this->window, 10);
    $this->allocator->release($this->window, 10);

    expect(array_values($this->allocator->remainingFor($this->window->slots)))->each->toBe(40);
});

it('treats a slot nobody has touched as completely free', function () {
    $remaining = $this->allocator->remainingFor([localSlot('21:00')]);

    expect($remaining[localSlot('21:00')->format('Y-m-d\TH:i')])->toBe(40);
});

it('gives every counter an expiry so dead slots cannot pile up forever', function () {
    $this->allocator->allocate($this->window, 4);

    $key = 'res:seats:{main}:'.$this->window->slots[0]->format('Y-m-d\TH:i');

    expect(Redis::connection('reservations')->ttl($key))->toBeGreaterThan(0);
});

it('only ever recovers leaked seats, never lowers a counter', function () {
    $slot = $this->window->slots[0];

    $this->allocator->allocate(DwellWindow::of($slot, 30), 10);   // 30 left

    // Postgres says only 4 seats are really booked, so 36 should be free.
    expect($this->allocator->reconcile($slot, 4))->toBeTrue()
        ->and($this->allocator->remainingFor([$slot])[$slot->format('Y-m-d\TH:i')])->toBe(36);

    /*
     * Now the opposite direction. Lowering would be unsafe: a booking made
     * between reading Postgres and writing here would be counted twice, and the
     * reconciliation would cause the overselling it exists to prevent.
     */
    expect($this->allocator->reconcile($slot, 20))->toBeFalse()
        ->and($this->allocator->remainingFor([$slot])[$slot->format('Y-m-d\TH:i')])->toBe(36);
});
