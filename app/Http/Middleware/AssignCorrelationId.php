<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give every request an identity that every log line it produces will carry.
 *
 * Without this the log of a busy server is a column of identical "200 POST /mcp"
 * lines: a call that booked, a call that came back unavailable, and a call that
 * never arrived are indistinguishable. We hit exactly that twice while building
 * this — once diagnosing a client approval prompt nobody had answered, once a
 * client that was not reaching the server at all — and both times the answer had
 * to be inferred instead of read.
 *
 * Context is the mechanism: anything put here is attached to every log entry for
 * the rest of the request, with nothing having to pass it down the call stack.
 *
 * @see https://laravel.com/docs/13.x/context
 */
class AssignCorrelationId
{
    /**
     * Returned on every response so a caller can quote it when reporting a
     * problem, including the ones that never reach a tool.
     */
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = (string) Str::ulid();

        /*
         * add() overwrites rather than appends, and the session key is cleared
         * before being conditionally set. Both matter under Octane: a worker
         * outlives the request, so the safe pattern is for each request to state
         * its own values outright rather than trust that the last one tidied up.
         */
        Context::add('correlation_id', $correlationId);
        Context::forget('mcp_session');

        /*
         * The thread that ties several calls of one conversation together. An
         * agent checks availability, is offered 19:30, then books — three
         * unrelated HTTP requests that only this header reveals as one story.
         */
        if (($session = $request->header('Mcp-Session-Id')) !== null) {
            Context::add('mcp_session', $session);
        }

        $response = $next($request);

        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
