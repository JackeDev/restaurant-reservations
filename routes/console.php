<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Hand back seats that Redis is holding with no booking behind them.
 *
 * Every five minutes because the leak is rare and the repair is cheap: one MGET
 * plus a write for each slot that actually disagrees. It only ever raises a
 * counter, so it is safe to run mid-service and safe to overlap with bookings.
 *
 * withoutOverlapping() because a run on a large table can outlast five minutes,
 * and two of them at once would read the same drift twice.
 *
 * ⚠ Nothing runs this on its own. The scheduler needs `php artisan schedule:work`
 * — there is no worker container in compose.yaml, because this project is
 * delivered as a benchmarkable MCP server rather than a running service. Run it
 * by hand, or add the container, depending on what you are doing:
 *
 *   ./vendor/bin/sail artisan reservations:sync-capacity --dry-run
 */
Schedule::command('reservations:sync-capacity')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
