<?php

namespace App\Mcp\Concerns;

use App\Support\FailureReference;
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
 *   ./vendor/bin/sail logs laravel.test | grep 01K7B8ZQ4X
 *
 * The recording itself is FailureReference's job, because the voice webhook
 * needs the identical treatment and only words the answer differently.
 */
trait ReportsFailures
{
    protected function failed(Throwable $e): Response
    {
        $reference = FailureReference::for($e, 'tool.failed', ['tool' => $this->name()]);

        return Response::error(sprintf(
            'Something went wrong while handling that request, and no reservation was made. Quote reference %s to have it looked up.',
            $reference,
        ));
    }
}
