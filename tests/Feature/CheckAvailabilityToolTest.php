<?php

use App\Mcp\Servers\ReservationServer;
use App\Mcp\Tools\CheckAvailability;
use App\Services\ReservationService;
use Illuminate\Testing\Fluent\AssertableJson;

function availability(string $date, int $partySize = 2)
{
    return ReservationServer::tool(CheckAvailability::class, [
        'date' => $date,
        'party_size' => $partySize,
    ]);
}

it('lists the times that can seat the whole party', function () {
    availability(localDate())
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('timezone', 'America/Bogota')
            ->where('slot_minutes', 30)
            ->where('opening_hours', ['12:00-15:00', '19:00-23:00'])
            ->has('available')
            ->etc());
});

it('tells the caller what day it is here, not where they are', function () {
    /*
     * An MCP client works from its own clock, usually UTC, and a restaurant five
     * hours behind spends part of every day on a different calendar date. This
     * is the authoritative answer it can ask for instead of guessing.
     */
    availability(localDate(2))->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('today', localDate())
        ->where('date_is', 'Wednesday, in 2 days')
        ->etc());
});

it('leaves out times that have already passed today', function () {
    // The clock is frozen at 10:00, so lunch is ahead but nothing earlier is.
    availability(localDate())->assertStructuredContent(function (AssertableJson $json) {
        $times = array_column($json->toArray()['available'], 'time');

        expect($times)->not->toBeEmpty()
            ->and(min($times))->toBeGreaterThanOrEqual('12:00');

        return $json->etc();
    });
});

it('explains an empty list rather than leaving the caller guessing', function () {
    availability(localDate(-3))->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('available', [])
        /*
         * Three very different situations collapse into the same empty list —
         * a date that has passed, a day the restaurant closes, a service that is
         * simply over — and the caller needs to tell them apart to say anything
         * useful to the customer.
         */
        ->where('reason', fn (string $reason): bool => str_contains($reason, 'passed'))
        ->etc());
});

it('still reports the opening hours on a date with nothing free', function () {
    // A week back, so it is the same weekday and therefore the same hours.
    availability(localDate(-7))->assertStructuredContent(fn (AssertableJson $json) => $json
        // "We are closed that day" and "we are open but full" are different
        // answers, and an empty list alone cannot tell them apart.
        ->where('opening_hours', ['12:00-15:00', '19:00-23:00'])
        ->etc());
});

it('says so when the restaurant is open but every table is taken', function () {
    $service = app(ReservationService::class);

    // Fill every bookable start on the day, lunch and dinner alike.
    foreach (['12:00', '12:30', '13:00', '13:30', '14:00', '14:30',
        '19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '22:00', '22:30'] as $index => $time) {
        foreach ([1, 2] as $half) {
            $service->reserve(draft(localSlot($time), partySize: 20, email: "fill{$index}{$half}@example.com"));
        }
    }

    availability(localDate(), partySize: 2)->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('available', [])
        ->where('reason', fn (string $reason): bool => str_contains($reason, 'fully booked'))
        ->has('opening_hours')
        ->etc());
});

it('accounts for the whole stay, not just the starting slot', function () {
    $service = app(ReservationService::class);

    /*
     * Fill 13:00 with parties of four, who stay 90 minutes. The block therefore
     * covers 13:00, 13:30 and 14:00 — and stops before 14:30, which is what
     * makes this test able to tell the difference.
     */
    foreach (range(1, 10) as $party) {
        $service->reserve(draft(localSlot('13:00'), partySize: 4, email: "full{$party}@example.com"));
    }

    availability(localDate())->assertStructuredContent(function (AssertableJson $json) {
        $times = array_column($json->toArray()['available'], 'time');

        expect($times)->not->toContain('12:00')
            ->and($times)->not->toContain('12:30')
            ->and($times)->not->toContain('13:00')
            ->and($times)->toContain('14:30');

        return $json->etc();
    });
});
