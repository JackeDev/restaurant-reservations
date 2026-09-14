<?php

namespace App\Data;

/**
 * Both outcomes are successful calls. Unavailable is a business answer, not a
 * failure, and is reported with alternatives rather than as an error.
 */
enum ReservationOutcome: string
{
    case Confirmed = 'confirmed';
    case Unavailable = 'unavailable';
}
