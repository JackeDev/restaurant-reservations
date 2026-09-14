<?php

use App\Contracts\SlotAllocator;
use App\Data\DwellWindow;
use App\Services\ReservationService;

/*
 * The test this whole design exists for.
 *
 * A table booked at 13:00 is not free again at 13:30. Before dwell windows the
 * counters were atomic and internally consistent, and still described a
 * restaurant that could seat forty people every half hour forever. These cases
 * fail loudly against that earlier design, which is what makes them worth having.
 */

it('blocks the following slots for the length of the stay', function () {
    $service = app(ReservationService::class);
    $allocator = app(SlotAllocator::class);

    // Four guests occupy the table for 90 minutes: three 30-minute slots.
    $result = $service->reserve(draft(localSlot('13:00'), partySize: 4));

    expect($result->isConfirmed())->toBeTrue();

    $remaining = $allocator->remainingFor([
        localSlot('12:30'),
        localSlot('13:00'),
        localSlot('13:30'),
        localSlot('14:00'),
        localSlot('14:30'),
    ]);

    $seats = fn (string $time): int => $remaining[localSlot($time)->format('Y-m-d\TH:i')];

    expect($seats('12:30'))->toBe(40)   // before the booking, untouched
        ->and($seats('13:00'))->toBe(36)
        ->and($seats('13:30'))->toBe(36) // nobody booked this, the stay covers it
        ->and($seats('14:00'))->toBe(36)
        ->and($seats('14:30'))->toBe(40); // the table is back
});

it('refuses a later time whose stay overlaps a full slot', function () {
    $service = app(ReservationService::class);

    // Fill 13:00 completely: two parties of twenty.
    $service->reserve(draft(localSlot('13:00'), partySize: 20, email: 'a@example.com'));
    $service->reserve(draft(localSlot('13:00'), partySize: 20, email: 'b@example.com'));

    // 13:30 was never booked directly, but a party arriving then would still be
    // sitting through a slot that has no seats left.
    $result = $service->reserve(draft(localSlot('13:30'), partySize: 2, email: 'c@example.com'));

    expect($result->isConfirmed())->toBeFalse()
        ->and($result->reason)->toContain('13:30');
});

it('lets a booking through once the stay has cleared the full slot', function () {
    $service = app(ReservationService::class);

    // Ten parties of four fill 13:00. Each stays 90 minutes, so the block
    // covers 13:00, 13:30 and 14:00 — and stops there.
    foreach (range(1, 10) as $party) {
        $service->reserve(draft(localSlot('13:00'), partySize: 4, email: "full{$party}@example.com"));
    }

    // 14:00 still starts inside the block.
    $tooEarly = $service->reserve(draft(localSlot('14:00'), partySize: 2, email: 'early@example.com'));

    // 14:30 is the first start whose whole window is clear.
    $justRight = $service->reserve(draft(localSlot('14:30'), partySize: 2, email: 'ok@example.com'));

    expect($tooEarly->isConfirmed())->toBeFalse()
        ->and($justRight->isConfirmed())->toBeTrue();
});

it('never sells more seats than exist, however the parties are arranged', function () {
    $service = app(ReservationService::class);

    $confirmed = 0;

    // Twenty attempts at four seats each against a forty-seat slot.
    foreach (range(1, 20) as $attempt) {
        $result = $service->reserve(draft(
            localSlot('19:00'),
            partySize: 4,
            email: "diner{$attempt}@example.com",
        ));

        if ($result->isConfirmed()) {
            $confirmed++;
        }
    }

    expect($confirmed)->toBe(10);

    $window = DwellWindow::forParty(localSlot('19:00'), 4);
    $remaining = app(SlotAllocator::class)->remainingFor($window->slots);

    expect(array_values($remaining))->each->toBe(0);
});
