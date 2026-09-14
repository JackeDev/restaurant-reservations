<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared secret that stands where a session would.
 *
 * The voice webhook creates reservations, and it is called by a platform rather
 * than by a browser: there is no login, no cookie and no CSRF token to check. A
 * secret both sides know is what is left, and it is enough here because the only
 * thing the endpoint will do is book a table.
 *
 * One secret, two envelopes, because callers do not agree on how to send one.
 * Both are compared against the same configured value, so there is still only
 * one thing to rotate.
 */
class VerifyIntegrationSecret
{
    /**
     * For callers that drive this directly — curl, a test, another service.
     *
     * Not the one to configure in Vapi, which was measured the hard way: Vapi
     * attaches a header of this exact name to every server request itself, and
     * sends it EMPTY when no server secret is set on their side. A custom header
     * of the same name never arrives, because theirs wins. The failure is
     * particularly unhelpful — the request connects, the header is present, and
     * the value is blank — so from Vapi the secret travels as a Bearer token
     * instead, which is a name the platform does not own.
     */
    public const HEADER = 'X-Vapi-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('reservations.integration_secret'));
        $provided = $this->presented($request);

        /*
         * An unconfigured secret closes the door instead of opening it. The
         * other default — nothing configured, so let everyone through — is how
         * a writable endpoint ends up public, and it is the kind of hole that
         * leaves no trace in the logs because every request looks legitimate.
         *
         * hash_equals compares in constant time, so a wrong secret cannot be
         * discovered a character at a time by measuring the reply.
         */
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            $this->recordRejection($request, $expected, $provided);

            return response()->json([
                'error' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * The secret this caller presented, in whichever envelope it used.
     *
     * Trimmed because a secret is copied between a dashboard and a .env by
     * hand, and a trailing newline is invisible in both.
     */
    private function presented(Request $request): string
    {
        $header = trim((string) $request->header(self::HEADER, ''));

        if ($header !== '') {
            return $header;
        }

        return trim((string) ($request->bearerToken() ?? ''));
    }

    /**
     * Say enough about a rejection to fix it, without writing down a secret.
     *
     * "Wrong secret" alone cannot be acted on: a platform that silently drops a
     * header in its own reserved namespace, a tool still on an unpublished
     * draft, and a genuinely mistyped value all arrive here looking identical.
     * The length and the names of whatever credential headers did turn up
     * separate them in one line — a pasted trailing newline shows as a length
     * one too long, and a header that never arrived shows as nothing at all.
     *
     * Names and lengths only. The value itself is never logged, and neither is
     * the expected one.
     */
    private function recordRejection(Request $request, string $expected, string $provided): void
    {
        Log::warning('webhook.rejected', [
            'path' => $request->path(),
            'reason' => match (true) {
                $expected === '' => 'no INTEGRATION_SECRET is configured',
                $provided === '' => 'no credential presented',
                default => 'credential does not match',
            },
            'presented_length' => strlen($provided),
            'expected_length' => strlen($expected),
            'credential_headers' => $this->credentialHeaders($request),
        ]);
    }

    /**
     * The names of any headers that look like they were meant to carry a
     * credential. Symfony lowercases header keys, so these compare in lowercase.
     *
     * @return list<string>
     */
    private function credentialHeaders(Request $request): array
    {
        $looksLikeCredential = fn (string $name): bool => (bool) preg_match(
            '/secret|auth|token|signature|api[-_]?key/',
            $name,
        );

        return array_values(array_filter(array_keys($request->headers->all()), $looksLikeCredential));
    }
}
