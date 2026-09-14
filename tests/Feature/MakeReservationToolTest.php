<?php

use App\Mcp\Servers\ReservationServer;
use App\Mcp\Tools\MakeReservation;
use App\Models\Customer;
use App\Models\Reservation;
use App\Services\ReservationService;
use App\Support\RestaurantClock;
use Illuminate\Testing\Fluent\AssertableJson;

function book(array $arguments)
{
    return ReservationServer::tool(MakeReservation::class, $arguments);
}

function localDate(int $daysAhead = 0): string
{
    $clock = app(RestaurantClock::class);

    return $clock->toLocal($clock->now())->addDays($daysAhead)->format('Y-m-d');
}

it('confirms a booking and returns a reference', function () {
    $response = book([
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 4,
        'date' => localDate(),
        'time' => '13:00',
        'notes' => 'Gluten allergy',
    ]);

    $response->assertOk()->assertHasNoErrors();

    $reservation = Reservation::sole();

    expect($reservation->party_size)->toBe(4)
        ->and($reservation->notes)->toBe('Gluten allergy')
        ->and($reservation->reference)->toStartWith('RSV-')
        ->and($reservation->duration_minutes)->toBe(90);
});

it('treats a full slot as a successful answer, not an error', function () {
    $service = app(ReservationService::class);

    // Fill 13:00 outright.
    $service->reserve(draft(localSlot('13:00'), partySize: 20, email: 'a@example.com'));
    $service->reserve(draft(localSlot('13:00'), partySize: 20, email: 'b@example.com'));

    $response = book([
        'customer_name' => 'Late Comer',
        'customer_email' => 'late@example.com',
        'party_size' => 2,
        'date' => localDate(),
        'time' => '13:00',
    ]);

    /*
     * The whole contract in one assertion: an agent has to be able to act on
     * the suggestion, which it cannot do if the call simply failed.
     */
    $response->assertOk()->assertHasNoErrors();

    $response->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('status', 'unavailable')
        ->has('alternatives', fn (AssertableJson $alternatives) => $alternatives->count(3)->etc())
        ->missing('reservation')
        ->etc());
});

it('omits notes entirely when the customer left none', function () {
    book([
        'customer_name' => 'Quiet Diner',
        'customer_email' => 'quiet@example.com',
        'party_size' => 2,
        'date' => localDate(),
        'time' => '13:00',
    ])->assertStructuredContent(fn (AssertableJson $json) => $json
        // An empty field is context a model has to read and reason about for
        // nothing, so it is left out rather than sent as null.
        ->has('reservation', fn (AssertableJson $reservation) => $reservation->missing('notes')->etc())
        ->etc());
});

it('reports a date in words as well as in digits', function () {
    book([
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 2,
        'date' => localDate(2),
        'time' => '13:00',
    ])->assertStructuredContent(fn (AssertableJson $json) => $json
        // The client's idea of "today" is often a day off ours, and comparing
        // two dates is arithmetic. Reading "in 2 days" is not.
        ->has('reservation', fn (AssertableJson $reservation) => $reservation
            ->where('date_is', 'Wednesday, in 2 days')->etc())
        ->etc());
});

it('rejects input the schema would not allow', function (array $arguments) {
    book([
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 2,
        'date' => localDate(),
        'time' => '13:00',
        ...$arguments,
    ])->assertHasErrors();

    expect(Reservation::count())->toBe(0);
})->with([
    'a name too short to be one' => [['customer_name' => 'A']],
    'a malformed email' => [['customer_email' => 'not-an-email']],
    'nobody in the party' => [['party_size' => 0]],
    'more guests than we ever seat' => [['party_size' => 21]],
    'a date that is not a date' => [['date' => '14/09/2026']],
]);

it('answers an off-grid time with a reason rather than a validation error', function () {
    /*
     * 13:07 is a well-formed time, so neither the schema nor the validator has
     * any quarrel with it — the booking grid is a house rule, not a format.
     * Answering with a reason an agent can act on beats rejecting the call.
     */
    book([
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 2,
        'date' => localDate(),
        'time' => '13:07',
    ])->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('status', 'unavailable')
            ->where('reason', fn (string $reason): bool => str_contains($reason, '30 minutes'))
            ->etc());

    expect(Reservation::count())->toBe(0);
});

it('will not book a time that has already passed', function () {
    // The frozen clock sits at 10:00, so this morning's 09:00 is gone.
    book([
        'customer_name' => 'Time Traveller',
        'customer_email' => 'past@example.com',
        'party_size' => 2,
        'date' => localDate(),
        'time' => '09:00',
    ])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('status', 'unavailable')
        ->where('reason', fn (string $reason): bool => str_contains($reason, 'passed'))
        ->etc());

    expect(Reservation::count())->toBe(0);
});

it('reuses one customer record across their bookings', function () {
    foreach (['13:00', '19:00', '20:00'] as $time) {
        book([
            'customer_name' => 'Ana Garcia',
            'customer_email' => 'ana@example.com',
            'party_size' => 2,
            'date' => localDate(),
            'time' => $time,
        ])->assertHasNoErrors();
    }

    expect(Customer::count())->toBe(1)
        ->and(Reservation::count())->toBe(3);
});
