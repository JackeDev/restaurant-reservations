import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

/*
 * Load test for the MCP reservation tool.
 *
 *   docker compose --profile perf run --rm k6
 *
 * Set MCP_RATE_LIMIT=0 in .env and restart first. k6 drives every request from
 * one IP, so to the limiter it is indistinguishable from a single abusive
 * client; leaving it on measures the limiter returning 429, not the application
 * taking bookings.
 *
 * Run `php artisan reservations:verify` afterwards. This script proves the
 * server survives the traffic; that command proves the bookings it accepted can
 * actually be served. Neither claim means much without the other.
 */

const MCP_URL = `${__ENV.BASE_URL || 'http://laravel.test'}/mcp`;

const HEADERS = {
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
};

/*
 * Bookable start times, taken from config/restaurant.php. A booking that starts
 * here may still run past closing — that is how restaurants work, and the
 * allocator only requires the starting slot to be bookable.
 *
 * Lunch is open every day; the restaurant does not serve dinner on Sundays.
 * Asking for a closed time would still be answered correctly, but it would
 * measure the rejection path rather than the booking one.
 */
const LUNCH = ['12:00', '12:30', '13:00', '13:30', '14:00', '14:30'];
const DINNER = ['19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '22:00', '22:30'];

/*
 * Far enough ahead that ordinary traffic never runs out of tables. Bookings
 * consume every slot of their stay, so a fortnight of lunches is only a few
 * hundred covers — the first run saturated it within seconds and then spent four
 * minutes measuring a full restaurant, which is nobody's idea of a booking
 * system under load.
 */
const SPREAD_DAYS = 365;

/* Share of traffic aimed at one contended slot. See hotSlot(). */
const HOT_SHARE = 0.7;

/* How long every virtual user agrees on the same hot slot, in milliseconds. */
const HOT_ROTATION_MS = 20000;

/* Sequential requests fired before measuring, to boot every worker. See warmUp(). */
const WARMUP_REQUESTS = Number(__ENV.WARMUP_REQUESTS || 200);

const confirmed = new Counter('reservations_confirmed');
const unavailable = new Counter('reservations_unavailable');
const toolErrors = new Counter('tool_errors');

/*
 * Overridable so the same script can smoke-test in 20 seconds and so a reviewer
 * on a smaller machine can dial it down rather than measure their own laptop
 * running out of cores:
 *
 *   docker compose --profile perf run --rm -e VUS=20 -e HOLD_SECONDS=15 k6
 */
const VUS = Number(__ENV.VUS || 200);
const RAMP_UP = Number(__ENV.RAMP_UP_SECONDS || 30);
const HOLD = Number(__ENV.HOLD_SECONDS || 60);
const RAMP_DOWN = Number(__ENV.RAMP_DOWN_SECONDS || 30);

const RAMP = [
    { duration: `${RAMP_UP}s`, target: VUS },
    { duration: `${HOLD}s`, target: VUS },
    { duration: `${RAMP_DOWN}s`, target: 0 },
];

/* A gap after the first profile, so the two never overlap and share a number. */
const COLD_STARTS_AT = RAMP_UP + HOLD + RAMP_DOWN + 15;

export const options = {
    /*
     * k6 allows setup() 60 seconds by default, and that is not enough to warm the
     * baseline configuration. The warm-up is deliberately sequential, so its
     * duration is 200 × whatever one request costs: a few seconds against Octane,
     * but around 85 against `artisan serve`, which rebuilds the framework every
     * time. At the default the baseline run aborts in setup() having measured
     * nothing at all — so the one configuration the comparison exists to measure
     * is the one that cannot be measured.
     *
     * Both configurations are still warmed identically; only the allowance
     * changes.
     */
    setupTimeout: '180s',

    scenarios: {
        /*
         * An agent that is already connected, booking repeatedly. This is the
         * reservation path in its pure form.
         */
        established: {
            executor: 'ramping-vus',
            stages: RAMP,
            exec: 'reserveOnly',
            tags: { profile: 'established' },
        },

        /*
         * 200 different clients opening a session at once: handshake and booking
         * on every iteration. The worst case, and a real one — measuring only
         * the first profile would quietly leave out work the server does.
         */
        coldStart: {
            executor: 'ramping-vus',
            stages: RAMP,
            startTime: `${COLD_STARTS_AT}s`,
            exec: 'handshakeAndReserve',
            tags: { profile: 'cold' },
        },
    },

    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{profile:established}': ['p(95)<500'],
        'http_req_duration{profile:cold}': ['p(95)<500'],

        /*
         * "Unavailable" is a successful call, so it is not counted here. Only a
         * malformed request or a server fault is a failure.
         */
        tool_errors: ['count==0'],
    },

    summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max'],
};

/** Runs once, so every virtual user works from the same dates. */
export function setup() {
    warmUp();

    return {
        hotDate: isoDate(7),
        spreadFrom: 8,
    };
}

/**
 * Give every worker a request before the clock starts.
 *
 * A freshly booted PHP worker pays for its first request — autoloading, opcache,
 * the container graph — and with the pool booting at once, arrivals queue behind
 * all of them. Measuring through that put a 19-second outlier in every run while
 * the median stayed under 100ms. Warming first drops the worst request to under
 * half a second and changes nothing else, which is how we know it was the boot
 * and not the application.
 */
function warmUp() {
    const body = JSON.stringify({ jsonrpc: '2.0', id: 0, method: 'tools/list' });

    for (let i = 0; i < WARMUP_REQUESTS; i++) {
        http.post(MCP_URL, body, { headers: HEADERS, tags: { step: 'warmup' } });
    }
}

/** Profile 1: shake hands once per virtual user, then only book. */
let session = null;

export function reserveOnly(data) {
    if (session === null) {
        session = handshake();
    }

    book(data, session, 'established');
}

/** Profile 2: a brand new client every iteration. */
export function handshakeAndReserve(data) {
    book(data, handshake(), 'cold');
}

function handshake() {
    const response = http.post(
        MCP_URL,
        JSON.stringify({
            jsonrpc: '2.0',
            id: 0,
            method: 'initialize',
            params: {
                protocolVersion: '2025-06-18',
                capabilities: {},
                clientInfo: { name: 'k6', version: '1.0' },
            },
        }),
        { headers: HEADERS, tags: { step: 'initialize' } },
    );

    check(response, { 'initialize returned 200': (r) => r.status === 200 });

    return response.headers['Mcp-Session-Id'] || '';
}

function book(data, sessionId, profile) {
    const response = http.post(MCP_URL, JSON.stringify(bookingRequest(data)), {
        headers: { ...HEADERS, 'Mcp-Session-Id': sessionId },
        tags: { step: 'tools/call', profile },
    });

    const status = outcomeOf(response);

    if (status === 'confirmed') {
        confirmed.add(1);
    } else if (status === 'unavailable') {
        unavailable.add(1);
    } else {
        toolErrors.add(1);
    }

    check(response, {
        'http 200': (r) => r.status === 200,
        /*
         * Both outcomes count as success, and that is the point: a full slot
         * answered with alternatives is the tool working, not failing.
         */
        'tool answered': () => status === 'confirmed' || status === 'unavailable',
    });
}

function outcomeOf(response) {
    if (response.status !== 200) {
        return null;
    }

    try {
        return response.json()?.result?.structuredContent?.status ?? null;
    } catch {
        // A body that will not parse is a failure whatever the reason, and the
        // caller already records it as one. Nothing here to recover from.
        return null;
    }
}

function bookingRequest(data) {
    const hot = Math.random() < HOT_SHARE;
    const slot = hot ? hotSlot(data) : spreadSlot(data);

    return {
        jsonrpc: '2.0',
        id: 1,
        method: 'tools/call',
        params: {
            name: 'make_reservation',
            arguments: {
                customer_name: `Load Test VU ${__VU}`,
                /*
                 * One address per virtual user, so the customer upsert is
                 * exercised on both paths — inserting the first time, updating
                 * afterwards — without 200 users contending over one row.
                 */
                customer_email: `vu-${__VU}@loadtest.invalid`,
                party_size: 1 + Math.floor(Math.random() * 4),
                date: slot.date,
                time: slot.time,
            },
        },
    };
}

/*
 * One slot that every virtual user piles onto at the same moment, which is what
 * makes overselling possible at all: the seats run out while hundreds of
 * requests are mid-flight. Rotating it means the test crosses that boundary
 * again every few seconds instead of once.
 */
function hotSlot(data) {
    const bucket = Math.floor(Date.now() / HOT_ROTATION_MS) % LUNCH.length;

    return { date: data.hotDate, time: LUNCH[bucket] };
}

/* Ordinary traffic, spread widely enough to keep confirming real bookings. */
function spreadSlot(data) {
    const date = isoDate(data.spreadFrom + Math.floor(Math.random() * SPREAD_DAYS));
    const times = bookableTimesOn(date);

    return { date, time: times[Math.floor(Math.random() * times.length)] };
}

/**
 * Parsed as UTC so the weekday is that of the calendar date itself, which is
 * exactly what the server reads it as — the restaurant's local date.
 */
function bookableTimesOn(date) {
    const sunday = new Date(`${date}T00:00:00Z`).getUTCDay() === 0;

    return sunday ? LUNCH : [...LUNCH, ...DINNER];
}

function isoDate(daysFromNow) {
    return new Date(Date.now() + daysFromNow * 86400000).toISOString().slice(0, 10);
}
