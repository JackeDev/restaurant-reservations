<?php

/*
 * The brief describes a single restaurant, so its details live in config rather
 * than in a database table. Under Octane this file is loaded once per worker and
 * stays in memory, which means reading opening hours costs zero queries and zero
 * cache lookups on every request.
 *
 * Changing these values requires `php artisan octane:reload`, since each worker
 * read them at boot. Existing reservations are never re-validated against them:
 * a confirmed booking is a promise, so config only governs what may be sold from
 * now on, never what has already been sold.
 */
return [

    'name' => env('RESTAURANT_NAME', "Jackeline's Food House"),

    'timezone' => env('RESTAURANT_TIMEZONE', 'America/Bogota'),

    /*
     * Length of a booking slot in minutes. Every opening-hours range must be an
     * exact multiple of this value; `reservations:doctor` verifies that.
     */
    'slot_minutes' => (int) env('RESERVATIONS_SLOT_MINUTES', 30),

    /*
     * Seats available in each slot. We model capacity as seats rather than
     * individual tables: it keeps the allocator to a single integer per slot.
     */
    'seats_per_slot' => (int) env('RESERVATIONS_SEATS_PER_SLOT', 40),

    /*
     * How long a party occupies the table, by party size. A booking consumes
     * every slot its stay spans, not just the one it starts in, so that a table
     * booked at 19:00 is not sold again at 19:30.
     *
     * Keys are the maximum party size for that duration. Key 0 is the fallback
     * used by any party larger than the biggest key.
     */
    'dwell_minutes' => [
        2 => 75,
        4 => 90,
        6 => 105,
        0 => 120,
    ],

    /*
     * Opening hours per weekday, as [open, close] ranges. A closing time is
     * exclusive: the last bookable slot starts one `slot_minutes` before it.
     */
    'opening_hours' => [
        'mon' => [['12:00', '15:00'], ['19:00', '23:00']],
        'tue' => [['12:00', '15:00'], ['19:00', '23:00']],
        'wed' => [['12:00', '15:00'], ['19:00', '23:00']],
        'thu' => [['12:00', '15:00'], ['19:00', '23:30']],
        'fri' => [['12:00', '16:00'], ['19:00', '23:30']],
        'sat' => [['12:00', '16:00'], ['19:00', '23:30']],
        'sun' => [['12:00', '16:00']],
    ],

];
