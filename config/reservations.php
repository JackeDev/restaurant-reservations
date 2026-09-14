<?php

return [

    /*
     * Per-minute, per-IP limit on the MCP endpoint. Set MCP_RATE_LIMIT=0 to
     * disable throttling entirely, which the load test requires: k6 drives all
     * traffic from a single IP and would otherwise just measure the limiter.
     */
    'rate_limit' => (int) env('MCP_RATE_LIMIT', 600),

    /*
     * How many bookable times to consider on each side of the requested one.
     * These are counted in times the restaurant is actually open for, not in
     * raw slots: closed hours are stepped over rather than counted, so an
     * enquiry for an evening the restaurant shuts still reaches that day's
     * lunch and the next day's service.
     */
    'alternative_candidates_per_direction' => (int) env('RESERVATIONS_ALTERNATIVE_CANDIDATES', 6),

    /*
     * How far either side of the requested time to keep looking before giving
     * up. A day is enough to step over a closed evening and reach the next
     * service, without offering a table so far away that it stops being an
     * answer to what was asked.
     */
    'alternative_horizon_hours' => (int) env('RESERVATIONS_ALTERNATIVE_HORIZON_HOURS', 24),

    /*
     * How many alternatives to offer when the requested slot is unavailable.
     */
    'alternative_limit' => (int) env('RESERVATIONS_ALTERNATIVE_LIMIT', 3),

    /*
     * A booking slower than this many milliseconds is logged as a warning, with
     * the allocator and database timings broken out so it is immediately clear
     * whether Redis or Postgres is the culprit.
     */
    'slow_threshold_ms' => (int) env('RESERVATIONS_SLOW_THRESHOLD_MS', 250),

    /*
     * Shared secret required by non-MCP entry points such as the Vapi webhook.
     */
    'integration_secret' => env('INTEGRATION_SECRET'),

];
