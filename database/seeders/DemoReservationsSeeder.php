<?php

namespace Database\Seeders;

use App\Data\ReservationDraft;
use App\Enums\BookingChannel;
use App\Models\Reservation;
use App\Services\OpeningHours;
use App\Services\ReservationService;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoReservationsSeeder extends Seeder
{
    /** The earliest demo booking, and therefore the one the date must clear. */
    private const FIRST_SITTING = '12:30';

    /** Where the demo clusters its parties, to make the dwell window visible. */
    private const BUSY_SITTING = '13:00';

    /**
     * Create a handful of bookings so a freshly started app has something to
     * show, rather than a completely empty restaurant.
     *
     * Bookings are created through ReservationService — the same path the MCP
     * tool uses — so the Redis seat counters and the database always agree.
     * Inserting rows directly would leave Redis believing every slot is still
     * empty, and the first real booking would be allocated against a count that
     * was wrong from the moment the app started.
     *
     * Runs on every `sail up`, so it does nothing once any booking exists.
     */
    public function run(): void
    {
        if (Reservation::query()->exists()) {
            return;
        }

        $clock = app(RestaurantClock::class);
        $reservations = app(ReservationService::class);

        foreach ($this->bookings($clock, app(OpeningHours::class)) as $booking) {
            $reservations->reserve(new ReservationDraft(
                customerName: $booking['name'],
                customerEmail: $booking['email'],
                customerPhone: null,
                partySize: $booking['party_size'],
                requestedFor: $booking['at'],
                notes: $booking['notes'],
                channel: BookingChannel::Console,
            ));
        }
    }

    /**
     * A day's worth of plausible bookings, on the next date the restaurant is
     * open for lunch.
     *
     * Deliberately clustered rather than spread out: several parties at 13:00
     * makes the dwell window visible in `check_availability`, since 13:30 comes
     * back with fewer seats than 12:00 even though nobody booked 13:30.
     *
     * @return list<array{name: string, email: string, party_size: int, at: CarbonImmutable, notes: string|null}>
     */
    private function bookings(RestaurantClock $clock, OpeningHours $hours): array
    {
        $date = $this->nextOpenLunchDate($clock, $hours);

        $people = [
            ['Ana Garcia', 'ana.garcia@example.com', 4, self::BUSY_SITTING, 'Gluten allergy'],
            ['Luis Perez', 'luis.perez@example.com', 2, self::BUSY_SITTING, null],
            ['Marta Rojas', 'marta.rojas@example.com', 6, self::BUSY_SITTING, 'Birthday, bringing a cake'],
            ['Carlos Mena', 'carlos.mena@example.com', 2, self::FIRST_SITTING, null],
            ['Sofia Duarte', 'sofia.duarte@example.com', 3, '14:00', 'Window table if possible'],
        ];

        return array_map(fn (array $person): array => [
            'name' => $person[0],
            'email' => $person[1],
            'party_size' => $person[2],
            'at' => $clock->parseLocal($date, $person[3]),
            'notes' => $person[4],
        ], $people);
    }

    /**
     * The next date whose lunch service is still ahead of us.
     *
     * Seeding into a service that has already started would silently create no
     * bookings at all: the service rejects anything in the past, and the demo
     * would quietly be empty for anyone who ran `sail up` in the afternoon.
     */
    private function nextOpenLunchDate(RestaurantClock $clock, OpeningHours $hours): string
    {
        $day = $clock->toLocal($clock->now());

        for ($offset = 0; $offset <= 7; $offset++) {
            $date = $day->addDays($offset)->format('Y-m-d');
            $firstSitting = $clock->parseLocal($date, self::FIRST_SITTING);

            /*
             * Both conditions are needed. isBookable() only answers "is the
             * restaurant open then", which is happily true of a lunch that
             * finished hours ago — and seeding into it would be rejected as in
             * the past, leaving the demo silently empty.
             */
            if ($hours->isBookable($firstSitting) && $firstSitting->greaterThan($clock->now())) {
                return $date;
            }
        }

        // Every weekday in the shipped config opens for lunch, so this is
        // unreachable unless someone closes the restaurant for a whole week.
        return $day->addDay()->format('Y-m-d');
    }
}
