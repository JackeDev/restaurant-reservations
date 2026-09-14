<?php

namespace App\Listeners;

use App\Events\ReservationConfirmed;
use App\Support\RestaurantClock;
use Illuminate\Support\Facades\Log;

/**
 * Write a booking to the structured log.
 *
 * Deliberately no customer name, email or phone. A log is copied into tickets,
 * pasted into chats and shipped to third parties, so it holds the reference and
 * nothing that identifies a person — the reference is enough to find the row in
 * PostgreSQL, which is where identifying data belongs.
 *
 * Runs synchronously rather than queued: it is a single line on the request that
 * just wrote to Redis and Postgres, so a queue would cost more than the work it
 * deferred.
 *
 * Logged at debug, because a confirmed booking is the tool working, not an event
 * anyone needs told about. At 500 requests a second the trail is not free — it
 * measured about 9% of throughput — so it is off by default and switched on when
 * there is something to investigate:
 *
 *   LOG_LEVEL=debug in .env, then `sail restart`
 *
 * Problems keep announcing themselves regardless: reservation.slow is a warning
 * and tool.failed an error, so neither is silenced by the default level.
 */
class LogConfirmedReservation
{
    public function __construct(private readonly RestaurantClock $clock) {}

    public function handle(ReservationConfirmed $event): void
    {
        $booking = $event->reservation;

        Log::debug('reservation.confirmed', [
            'reference' => $booking->reference,
            // UTC is the canonical form everything is stored in; the local time
            // is what anyone reading the log is actually thinking in.
            'slot_utc' => $booking->reservedFor->format('Y-m-d\TH:i\Z'),
            'slot_local' => $this->clock->localDate($booking->reservedFor).' '.$this->clock->localTime($booking->reservedFor),
            'party_size' => $booking->partySize,
            // How many slots the stay consumed. A booking that unexpectedly
            // spans four slots instead of three is a dwell-time bug, and this is
            // where it would show up first.
            'slots_occupied' => count($booking->window->slots),
            'seats_left_in_window' => $event->seatsLeftInWindow,
        ]);
    }
}
