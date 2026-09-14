<?php

namespace App\Data;

use App\Enums\TimeSource;
use Carbon\CarbonImmutable;

/**
 * A booking time, together with how it was arrived at.
 *
 * The provenance travels with the value rather than being left on the resolver
 * as mutable state. Under Octane a service can outlive the request, and "how did
 * the last call resolve its time" is precisely the kind of leftover that would
 * then answer for the wrong request.
 */
final readonly class ResolvedTime
{
    /**
     * @param  CarbonImmutable  $at  UTC.
     * @param  string|null  $confidence  Reported by the agent; null for every
     *                                   other source, which cannot be unsure.
     */
    public function __construct(
        public CarbonImmutable $at,
        public TimeSource $by,
        public ?string $confidence = null,
    ) {}

    /**
     * Whether we had to interpret a phrase to get here, rather than being told.
     */
    public function wasInterpreted(): bool
    {
        return $this->by !== TimeSource::Explicit;
    }
}
