<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoReservationsSeeder extends Seeder
{
    /**
     * Create a handful of bookings so a freshly started app has something to
     * show, rather than a completely empty restaurant.
     *
     * Bookings are created through ReservationService — the same path the MCP
     * tool uses — so the Redis seat counters and the database always agree.
     * Seeding rows directly would leave Redis believing every slot is empty.
     *
     * Runs on every `sail up`, so it is idempotent: it does nothing once demo
     * bookings already exist.
     */
    public function run(): void
    {
        // Filled in once ReservationService exists (phase 3).
    }
}
