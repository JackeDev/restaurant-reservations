<?php

namespace App\Models;

use App\Data\DwellWindow;
use App\Enums\BookingChannel;
use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Reservations are only ever inserted, never updated, which makes this table
 * its own append-only audit trail.
 */
#[Fillable([
    'reference',
    'customer_id',
    'party_size',
    'reserved_for',
    'duration_minutes',
    'status',
    'created_via',
    'notes',
])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            /*
             * Always UTC. app.timezone is UTC, so this cast both stores and
             * returns UTC instants. Converting to the restaurant's timezone is
             * the job of RestaurantClock, at the edge of the application.
             */
            'reserved_for' => 'immutable_datetime',
            'party_size' => 'integer',
            'duration_minutes' => 'integer',
            'status' => ReservationStatus::class,
            'created_via' => BookingChannel::class,
        ];
    }

    /**
     * Generate a customer-facing booking code.
     *
     * ULIDs are time-ordered and generated locally, so creating a reservation
     * never needs a round trip to read the code back.
     */
    public static function newReference(): string
    {
        return 'RSV-'.Str::upper((string) Str::ulid());
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Every slot this booking occupies.
     *
     * Built from the stored duration rather than from current config, so past
     * reservations keep the stay length they were created with.
     */
    public function dwellWindow(): DwellWindow
    {
        return DwellWindow::of($this->reserved_for, $this->duration_minutes);
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', ReservationStatus::Confirmed);
    }
}
