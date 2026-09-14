<?php

namespace App\Enums;

/**
 * How a booking time was arrived at.
 *
 * Reported back to the caller whenever it is not Explicit, so that a time we
 * interpreted from a phrase is never mistaken for one the customer stated. An
 * agent that knows the time was inferred can read it back before confirming.
 */
enum TimeSource: string
{
    /** The caller sent a date and a time. No interpretation at all. */
    case Explicit = 'explicit';

    /** Carbon understood the phrase on its own: deterministic, offline, free. */
    case Carbon = 'carbon';

    /** The AI agent resolved it, for phrasing Carbon could not parse. */
    case Ai = 'ai';
}
