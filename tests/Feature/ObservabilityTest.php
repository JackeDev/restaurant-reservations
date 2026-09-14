<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Mcp\Servers\ReservationServer;
use App\Mcp\Tools\MakeReservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/*
 * These cover the part of the system that only matters when something has
 * already gone wrong, which is exactly why it is worth testing: nobody notices
 * observability is broken until the day they need it.
 */

function callTool(array $arguments = []): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'check_availability',
            'arguments' => $arguments + ['date' => localDate(), 'party_size' => 2],
        ],
    ];
}

it('returns a correlation id on every response', function () {
    $response = $this->postJson('/mcp', callTool());

    $response->assertOk()->assertHeader(AssignCorrelationId::HEADER);

    // A ULID, so it sorts by time — two ids tell you which request came first.
    expect($response->headers->get(AssignCorrelationId::HEADER))->toHaveLength(26);
});

it('gives two consecutive requests different identities', function () {
    /*
     * The Octane leak test in miniature. A worker outlives the request, so
     * anything the framework carries per-request has to be replaced rather than
     * inherited — if these two ever matched, every log line in a busy server
     * would point at the same request.
     */
    $first = $this->postJson('/mcp', callTool())->headers->get(AssignCorrelationId::HEADER);
    $second = $this->postJson('/mcp', callTool())->headers->get(AssignCorrelationId::HEADER);

    expect($first)->not->toBe($second);
});

/**
 * Run the middleware and report what it put into Context, which is the thing
 * every log line of that request will carry.
 *
 * @return array{correlation_id: ?string, mcp_session: ?string}
 */
function contextFor(?string $session): array
{
    $request = Request::create('/mcp', 'POST');

    if ($session !== null) {
        $request->headers->set('Mcp-Session-Id', $session);
    }

    $seen = [];

    (new AssignCorrelationId)->handle($request, function () use (&$seen): SymfonyResponse {
        $seen = [
            'correlation_id' => Context::get('correlation_id'),
            'mcp_session' => Context::get('mcp_session'),
        ];

        return new SymfonyResponse;
    });

    return $seen;
}

it('carries the caller session through so one conversation can be followed', function () {
    // An agent checks availability, is offered a time, then books: three
    // unrelated HTTP requests that only this header reveals as one story.
    expect(contextFor('session-abc')['mcp_session'])->toBe('session-abc');
});

it('does not let one request inherit the session of the last one', function () {
    contextFor('session-abc');

    /*
     * The Octane hazard, isolated. Context lives on a worker that outlives the
     * request, so a second caller with no session of its own must not pick up
     * the first one's — that would file two strangers' requests under one
     * conversation, which is worse than having no session at all.
     */
    expect(contextFor(null)['mcp_session'])->toBeNull();
});

it('logs why a booking was refused, not merely that it was', function () {
    Log::spy();

    // 09:00 is before service: open that day, but not then.
    ReservationServer::tool(MakeReservation::class, [
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 2,
        'date' => localDate(1),
        'time' => '09:00',
    ]);

    /*
     * "full", "closed", "off_grid" and "in_past" are four different problems
     * with four different answers for the customer. A log that only said the
     * booking failed would not be worth keeping.
     */
    Log::shouldHaveReceived('debug')
        ->withArgs(fn (string $message, array $context): bool => $message === 'reservation.rejected'
            && $context['code'] === 'closed'
            && $context['party_size'] === 2)
        ->once();
});

it('logs a confirmed booking without any personal data in it', function () {
    Log::spy();

    ReservationServer::tool(MakeReservation::class, [
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 2,
        'date' => localDate(1),
        'time' => '13:00',
        'notes' => 'Gluten allergy',
    ])->assertHasNoErrors();

    Log::shouldHaveReceived('debug')
        ->withArgs(function (string $message, array $context): bool {
            if ($message !== 'reservation.confirmed') {
                return false;
            }

            /*
             * Logs get copied into tickets, pasted into chats and shipped to
             * third parties. The reference is enough to find the row in
             * Postgres, which is where identifying data belongs.
             */
            $flattened = json_encode($context);

            return ! str_contains($flattened, 'Ana Garcia')
                && ! str_contains($flattened, 'ana@example.com')
                && ! str_contains($flattened, 'Gluten allergy')
                && str_starts_with($context['reference'], 'RSV-');
        })
        ->once();
});

it('hands back a reference the caller can quote when something breaks', function () {
    $this->mock(ReservationService::class)
        ->shouldReceive('reserve')
        ->andThrow(new RuntimeException('redis went away mid-booking'));

    $response = ReservationServer::tool(MakeReservation::class, [
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 2,
        'date' => localDate(1),
        'time' => '13:00',
    ]);

    /*
     * The package would otherwise answer "An internal server error occurred.",
     * which identifies nothing: the customer cannot report it usefully and we
     * cannot find which of the day's requests was theirs.
     */
    $response->assertHasErrors()
        ->assertDontSee('redis went away mid-booking')
        ->assertSee('Quote reference');
});

it('never leaks the underlying failure to the caller', function () {
    $this->mock(ReservationService::class)
        ->shouldReceive('reserve')
        ->andThrow(new RuntimeException('SQLSTATE[08006] could not connect to server at 10.0.0.4'));

    ReservationServer::tool(MakeReservation::class, [
        'customer_name' => 'Ana Garcia',
        'customer_email' => 'ana@example.com',
        'party_size' => 2,
        'date' => localDate(1),
        'time' => '13:00',
    ])
        // Masked outward, complete inward: the full exception goes to the log
        // under the same reference the caller was given.
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('10.0.0.4');
});
