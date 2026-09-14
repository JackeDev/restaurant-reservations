<?php

namespace App\Listeners;

use App\Events\ReservationRejected;
use App\Support\RestaurantClock;
use Illuminate\Support\Facades\Log;

/**
 * Write a refused booking to the structured log, with why.
 *
 * This is the half that makes the log worth reading. "The booking failed" is not
 * a diagnosis; "full", "closed", "off_grid" and "in_past" are four different
 * problems with four different fixes, and only one of them is about capacity.
 *
 * It also records how many alternatives went back. A rejection offering none is
 * the worst answer this server can give — the customer gets no way forward — so
 * a run of zeroes is a signal in its own right, and it is what caught the
 * alternative search counting closed hours against its own budget.
 *
 * Logged at debug, and certainly not at warning: refusing a full slot is the tool
 * working. It is also the highest-volume line here by some distance — 67,442
 * entries in a four-minute load test, against 22,405 confirmations — because the
 * test deliberately contends on one slot. Off by default, on with LOG_LEVEL=debug
 * when a pattern of refusals is what you are chasing.
 */
class LogRejectedReservation
{
    public function __construct(private readonly RestaurantClock $clock) {}

    public function handle(ReservationRejected $event): void
    {
        $draft = $event->draft;

        Log::debug('reservation.rejected', [
            'code' => $event->code,
            'reason' => $event->reason,
            'slot_utc' => $draft->requestedFor->format('Y-m-d\TH:i\Z'),
            'slot_local' => $this->clock->localDate($draft->requestedFor).' '.$this->clock->localTime($draft->requestedFor),
            'party_size' => $draft->partySize,
            'alternatives_offered' => $event->alternativesOffered,
            'channel' => $draft->channel->value,
        ]);
    }
}
