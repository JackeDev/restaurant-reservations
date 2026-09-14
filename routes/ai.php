<?php

use App\Mcp\Servers\ReservationServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * laravel/mcp loads this file in an empty route group: no middleware, no
 * throttling, no session. The throttle below is therefore not a nicety, it is
 * the only rate limiting this endpoint gets.
 *
 * Do not register this file in bootstrap/app.php — the package already loads it.
 */
Mcp::web('/mcp', ReservationServer::class)->middleware(['throttle:mcp']);

/*
 * Lets an MCP client launch the server over stdio:
 *   php artisan mcp:start reservations
 */
Mcp::local('reservations', ReservationServer::class);
