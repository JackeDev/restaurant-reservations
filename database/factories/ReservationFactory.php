<?php

namespace Database\Factories;

use App\Data\DwellWindow;
use App\Enums\BookingChannel;
use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $partySize = fake()->numberBetween(1, 8);

        return [
            'reference' => Reservation::newReference(),
            'customer_id' => Customer::factory(),
            'party_size' => $partySize,
            // Stored in UTC, like every reserved_for value.
            'reserved_for' => CarbonImmutable::tomorrow(config('restaurant.timezone'))->setTime(19, 0)->utc(),
            'duration_minutes' => DwellWindow::minutesForParty($partySize),
            'status' => ReservationStatus::Confirmed,
            'created_via' => BookingChannel::Mcp,
            'notes' => null,
        ];
    }

    /**
     * Book the reservation at a specific moment.
     *
     * @param  CarbonImmutable  $moment  Converted to UTC before storage.
     */
    public function at(CarbonImmutable $moment): static
    {
        return $this->state(fn (): array => ['reserved_for' => $moment->utc()]);
    }

    /**
     * Book for a given number of guests, keeping the stay length consistent.
     */
    public function forParty(int $partySize): static
    {
        return $this->state(fn (): array => [
            'party_size' => $partySize,
            'duration_minutes' => DwellWindow::minutesForParty($partySize),
        ]);
    }
}
