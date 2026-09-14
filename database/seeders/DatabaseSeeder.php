<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * This runs on every `sail up` via the app-init service, so everything here
     * must be idempotent.
     */
    public function run(): void
    {
        $this->call(DemoReservationsSeeder::class);
    }
}
