<?php

use App\Services\AlternativeSlotFinder;
use App\Services\OpeningHours;
use App\Services\ReservationService;
use App\Support\RestaurantClock;

/*
 * The frozen clock is a Monday 10:00. Monday opens 12:00-15:00 and 19:00-23:00,
 * so there is a four-hour hole in the middle of the day for these to walk over.
 */

function times(array $alternatives): array
{
    $clock = app(RestaurantClock::class);

    return array_map(
        fn ($alternative): string => $clock->localDate($alternative->startsAt).' '.$clock->localTime($alternative->startsAt),
        $alternatives,
    );
}

it('offers times either side of the one that was full', function () {
    $finder = app(AlternativeSlotFinder::class);

    $alternatives = $finder->find(localSlot('20:00'), 2);

    expect(times($alternatives))->not->toBeEmpty()
        // Closest first, so the first suggestion is the smallest change of plan.
        ->and($alternatives[0]->startsAt->diffInMinutes(localSlot('20:00'), true))
        ->toBeLessThanOrEqual(30);
});

it('steps over closed hours instead of giving up in them', function () {
    $finder = app(AlternativeSlotFinder::class);

    /*
     * 17:00 sits in the gap between lunch and dinner, with nothing bookable for
     * two hours in either direction. Counting the search in slots rather than in
     * open times returned nothing at all here — the closed hours ate the budget.
     */
    $alternatives = $finder->find(localSlot('17:00'), 2);

    expect($alternatives)->not->toBeEmpty();

    $offered = times($alternatives);

    // Something from the lunch service before, and something from dinner after.
    expect(collect($offered)->contains(fn (string $t): bool => str_contains($t, '14:')))->toBeTrue()
        ->and(collect($offered)->contains(fn (string $t): bool => str_contains($t, '19:')))->toBeTrue();
});

it('only offers times that can seat the party for their whole stay', function () {
    $service = app(ReservationService::class);

    // Fill 20:00 outright, which also blocks the two slots after it.
    $service->reserve(draft(localSlot('20:00'), partySize: 20, email: 'a@example.com'));
    $service->reserve(draft(localSlot('20:00'), partySize: 20, email: 'b@example.com'));

    $alternatives = app(AlternativeSlotFinder::class)->find(localSlot('20:00'), 4);

    /*
     * 19:30 looks free on its own, but a party arriving then would still be at
     * the table through 20:00. Offering it would be worse than offering nothing,
     * because the customer would accept a time we cannot serve.
     */
    expect(times($alternatives))->not->toContain(date('Y-m-d', strtotime('+0 day')).' 19:30');

    foreach ($alternatives as $alternative) {
        expect($alternative->seatsAvailable)->toBeGreaterThanOrEqual(4);
    }
});

it('looks forward from now when the request is already in the past', function () {
    $finder = app(AlternativeSlotFinder::class);

    // Anchored on the request itself, a date from last week would spend its whole
    // horizon still in the past and come back with nothing.
    $alternatives = $finder->find(localSlot('13:00', daysAhead: -7), 2);

    expect($alternatives)->not->toBeEmpty();

    foreach ($alternatives as $alternative) {
        expect($alternative->startsAt->greaterThan(app(RestaurantClock::class)->now()))->toBeTrue();
    }
});

it('never offers a time the restaurant could not honour', function (string $requested) {
    $hours = app(OpeningHours::class);
    $now = app(RestaurantClock::class)->now();

    $alternatives = app(AlternativeSlotFinder::class)->find(localSlot($requested), 2);

    // The invariant that matters more than any particular suggestion: whatever
    // comes back is open, on the grid, and still ahead of us.
    foreach ($alternatives as $alternative) {
        expect($hours->isBookable($alternative->startsAt))->toBeTrue()
            ->and($alternative->startsAt->greaterThan($now))->toBeTrue()
            ->and($alternative->seatsAvailable)->toBeGreaterThanOrEqual(2);
    }

    expect($alternatives)->not->toBeEmpty();
})->with([
    'the small hours' => ['03:00'],
    'the gap between services' => ['17:00'],
    'right on closing' => ['23:00'],
]);
