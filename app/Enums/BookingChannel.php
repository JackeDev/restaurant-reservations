<?php

namespace App\Enums;

/**
 * Where a reservation came from. Recorded on every row so the reservations
 * table doubles as an audit trail of which entry point created what.
 */
enum BookingChannel: string
{
    case Mcp = 'mcp';
    case Vapi = 'vapi';
    case Console = 'console';
}
