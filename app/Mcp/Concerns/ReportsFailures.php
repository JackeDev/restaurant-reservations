<?php

namespace App\Mcp\Concerns;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Throwable;

/**
 * Turn an unexpected failure into something the caller can report back.
 *
 * laravel/mcp already masks non-validation exceptions as "An internal server
 * error occurred." unless APP_DEBUG is on, which is right — the caller must not
 * receive a stack trace. But it leaves the customer with a sentence that
 * identifies nothing, and us with no way to find which of the day's requests
 * theirs was.
 *
 * So we catch first and hand back the correlation id. The customer says "it
 * failed, reference 01K7B8ZQ4X" and that is a grep away from the full exception:
 *
 *   ./vendor/bin/sail artisan pail --filter="01K7B8ZQ4X"
 *   grep 01K7B8ZQ4X storage/logs/laravel.log | jq .
 *
 * Masked outward, complete inward.
 */
trait ReportsFailures
{
    protected function failed(Throwable $e): Response
    {
        /*
         * Normally set by AssignCorrelationId. The fallback covers the stdio
         * transport, which never passes through HTTP middleware — a reference
         * nobody can correlate still beats telling the customer nothing.
         */
        $reference = (string) (Context::get('correlation_id') ?? Str::ulid());

        Log::error('tool.failed', [
            'tool' => $this->name(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ]);

        // Hands the exception, with its trace, to the configured handler.
        report($e);

        return Response::error(sprintf(
            'Something went wrong while handling that request, and no reservation was made. Quote reference %s to have it looked up.',
            $reference,
        ));
    }
}
