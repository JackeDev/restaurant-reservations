<?php

namespace App\Services;

use App\Ai\Agents\TimeParser;
use App\Data\ResolvedTime;
use App\Enums\TimeSource;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns whatever the caller sent into the UTC instant of a booking.
 *
 * Three routes, in order of preference:
 *
 *   1. Explicit date and time. The only route the load test ever takes, and the
 *      only one with no parsing at all.
 *   2. Carbon, for phrases it already understands like "next friday 8pm".
 *      Deterministic, offline, free.
 *   3. An AI agent, for anything Carbon cannot make sense of.
 *
 * Route 3 is an enhancement, never a dependency: with no API key configured, or
 * if the provider is having a bad day, resolution simply falls back. A booking
 * is never lost because of it.
 *
 * Stateless by design — the answer comes back as a value, so nothing about one
 * request survives on this object to be read by the next one under Octane.
 */
class TimeResolver
{
    /**
     * Confidence levels we are willing to book on. Anything else, including a
     * value we do not recognise, is treated as too unsure to act on.
     */
    private const TRUSTED_CONFIDENCE = ['medium', 'high'];

    public function __construct(private readonly RestaurantClock $clock) {}

    /**
     * @return ResolvedTime|null Null when nothing could be resolved.
     */
    public function resolve(?string $date, ?string $time, ?string $phrase): ?ResolvedTime
    {
        if ($date !== null && $time !== null) {
            return new ResolvedTime($this->clock->parseLocal($date, $time), TimeSource::Explicit);
        }

        if ($phrase === null) {
            return null;
        }

        return $this->fromCarbon($phrase) ?? $this->fromAgent($phrase);
    }

    /**
     * Deterministic parsing. Handles a surprising amount on its own.
     */
    private function fromCarbon(string $phrase): ?ResolvedTime
    {
        try {
            $parsed = CarbonImmutable::parse($phrase, $this->clock->timezone());
        } catch (InvalidFormatException) {
            return null;
        }

        return new ResolvedTime($this->snapToGrid($parsed->utc()), TimeSource::Carbon);
    }

    /**
     * The AI route, used only when a provider is configured.
     */
    private function fromAgent(string $phrase): ?ResolvedTime
    {
        if (blank(config('ai.providers.anthropic.key'))) {
            return null;
        }

        try {
            $parsed = (new TimeParser)->forDate(
                $this->clock->toLocal($this->clock->now())->format('Y-m-d H:i'),
                $this->clock->timezone(),
            )->prompt($phrase);

            $confidence = (string) $parsed['confidence'];

            /*
             * A model asked for a date will always produce one, even from
             * "hello" or from an attempt to hijack the prompt — in both cases it
             * answers with the current time and says it is unsure. Acting on
             * that would turn a clear "I did not understand you" into a booking
             * for a nonsense moment, so an unsure answer is no answer.
             */
            if (! in_array($confidence, self::TRUSTED_CONFIDENCE, true)) {
                return null;
            }

            $resolved = $this->clock->parseLocal($parsed['date'], $parsed['time']);
        } catch (Throwable $e) {
            // Never let the AI provider break a reservation.
            Log::warning('time.ai_parse_failed', [
                'phrase' => $phrase,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return new ResolvedTime($this->snapToGrid($resolved), TimeSource::Ai, $confidence);
    }

    /**
     * Round down to the booking grid, since a table cannot be taken mid-slot.
     */
    private function snapToGrid(CarbonImmutable $moment): CarbonImmutable
    {
        $slotMinutes = (int) config('restaurant.slot_minutes');
        $local = $this->clock->toLocal($moment);

        return $local
            ->setTime($local->hour, intdiv($local->minute, $slotMinutes) * $slotMinutes)
            ->utc();
    }
}
