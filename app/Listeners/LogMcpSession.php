<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Events\SessionInitialized;

/**
 * Record which client connected, and which protocol version it speaks.
 *
 * This is the only event laravel/mcp emits, and it answers a question the
 * request log cannot: "200 POST /mcp" is identical whether it came from Claude
 * Desktop, ChatGPT, the Inspector or k6. When a client misbehaves, knowing which
 * client it was — and which version of the protocol it negotiated — is usually
 * the whole diagnosis.
 *
 * Logged at debug rather than info, because "once per conversation" turns out to
 * be wrong. The transport is stateless and the handshake optional, so nothing
 * stops a client from re-initialising on every single call — and some do. Under
 * load that made this the second-noisiest line in the file at 40,431 entries in
 * a four-minute run, for an event that carries no business meaning. It is
 * connection noise: valuable when you go looking for it, not worth paying for on
 * every request.
 *
 * A caller that skips the handshake never appears here at all. That is not a gap
 * to fix: it is what the correlation id is for.
 */
class LogMcpSession
{
    public function handle(SessionInitialized $event): void
    {
        Log::debug('mcp.session.initialized', [
            'session_id' => $event->sessionId,
            'client' => $event->clientName(),
            'client_version' => $event->clientVersion(),
            'protocol_version' => $event->protocolVersion,
        ]);
    }
}
