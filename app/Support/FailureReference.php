<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One diagnosis path, however the request arrived.
 *
 * An unexpected failure has to be masked on the way out — a caller must never
 * receive a stack trace — and complete on the way in, or a reported problem is
 * unfindable among the day's requests. Both transports need exactly that, so the
 * recording lives here and each of them decides only how to word the answer.
 *
 * Masked outward, complete inward.
 */
final class FailureReference
{
    /**
     * Record a failure, and return the reference it was filed under.
     *
     * @param  array<string, mixed>  $context
     */
    public static function for(Throwable $e, string $event, array $context = []): string
    {
        /*
         * Normally set by AssignCorrelationId. The fallback covers the stdio
         * transport, which never passes through HTTP middleware — a reference
         * nobody can correlate still beats telling the caller nothing.
         */
        $reference = (string) (Context::get('correlation_id') ?? Str::ulid());

        Log::error($event, $context + [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ]);

        // Hands the exception, with its trace, to the configured handler.
        report($e);

        return $reference;
    }
}
