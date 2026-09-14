#!/usr/bin/env bash
#
# Prove that nothing leaks between requests sharing an Octane worker.
#
# Under Octane the framework boots once and the worker stays alive, so anything
# left in a long-lived place is visible to the NEXT request — which belongs to
# someone else. That is a data-disclosure bug, not just a correctness one.
#
# A grep can show the four known hiding places are empty (mutable statics,
# singletons holding request data, env() outside config, mutable DTOs). It cannot
# show there is no fifth. This runs the actual runtime instead.
#
# Why one worker: with the usual pool, two consecutive requests almost certainly
# land on different processes, so a leak would not show and the check would pass
# without testing anything. --workers=1 forces both onto the same PHP process,
# which is the only arrangement where residue is visible.
#
#   ./perf/octane-leak-check.sh
#
# Restores OCTANE_WORKERS on the way out, including on Ctrl-C.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

URL="${MCP_URL:-http://localhost:8080/mcp}"
HDRS=(-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream')
FAILURES=0

ORIGINAL_WORKERS="$(grep -E '^OCTANE_WORKERS=' .env | cut -d= -f2- || echo auto)"

restore() {
    echo
    echo "Restoring OCTANE_WORKERS=${ORIGINAL_WORKERS:-auto} ..."
    sed -i "s/^OCTANE_WORKERS=.*/OCTANE_WORKERS=${ORIGINAL_WORKERS:-auto}/" .env
    docker compose up -d laravel.test --force-recreate >/dev/null 2>&1
    echo "Done."
}
trap restore EXIT INT TERM

say()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; FAILURES=$((FAILURES + 1)); }

# Book a table and print the response body, with the correlation id appended on
# its own last line so the caller can read both from one request.
book() {
    local name="$1" email="$2" party="$3" time="$4"
    local headers body

    headers="$(mktemp)"
    body="$(curl -sS -D "$headers" -m 30 "$URL" "${HDRS[@]}" -d "{
        \"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",
        \"params\":{\"name\":\"make_reservation\",\"arguments\":{
            \"customer_name\":\"${name}\",\"customer_email\":\"${email}\",
            \"party_size\":${party},\"date\":\"${DATE}\",\"time\":\"${time}\"}}}" 2>/dev/null)"

    printf '%s\n' "$body"
    grep -i '^x-correlation-id:' "$headers" | tr -d '\r' | awk '{print $2}'
    rm -f "$headers"
}

say "Forcing a single Octane worker"
sed -i 's/^OCTANE_WORKERS=.*/OCTANE_WORKERS=1/' .env
docker compose up -d laravel.test --force-recreate >/dev/null 2>&1

for _ in $(seq 1 30); do
    curl -sS -o /dev/null -m 5 "$URL" "${HDRS[@]}" \
        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' 2>/dev/null && break
    sleep 2
done

RUNNING="$(docker compose exec -T laravel.test sh -c 'ps -o args= -C php 2>/dev/null | head -1' | tr -d '\r')"
echo "  $RUNNING"

case "$RUNNING" in
    *--workers=1*) pass "one worker, so both requests share a process" ;;
    *) fail "expected --workers=1; a pass here would prove nothing"; exit 1 ;;
esac

# Tomorrow, so the slot is always bookable whenever this is run.
DATE="$(docker compose exec -T laravel.test php -r \
    'echo (new DateTimeImmutable("tomorrow", new DateTimeZone(getenv("RESTAURANT_TIMEZONE") ?: "America/Bogota")))->format("Y-m-d");' | tr -d '\r')"

say "Two bookings, two customers, one worker  (date: $DATE)"

FIRST="$(book 'Ana Garcia' "ana-leak-$$@example.com" 2 '19:00')"
FIRST_ID="$(printf '%s' "$FIRST" | tail -1)"
FIRST_BODY="$(printf '%s' "$FIRST" | head -n -1)"

SECOND="$(book 'Juan Perez' "juan-leak-$$@example.com" 4 '20:30')"
SECOND_ID="$(printf '%s' "$SECOND" | tail -1)"
SECOND_BODY="$(printf '%s' "$SECOND" | head -n -1)"

say "Did anything from the first request survive into the second?"

case "$SECOND_BODY" in
    *'Juan Perez'*) pass "the second response names its own customer" ;;
    *) fail "the second response does not name Juan Perez at all" ;;
esac

case "$SECOND_BODY" in
    *'Ana Garcia'*) fail "LEAK: the first customer's name appears in the second response" ;;
    *) pass "no trace of the first customer" ;;
esac

case "$SECOND_BODY" in
    *'"party_size":4'*) pass "party size is the second request's, not the first's" ;;
    *) fail "LEAK or wrong answer: expected party_size 4 in the second response" ;;
esac

say "Request identity"

if [ -z "$FIRST_ID" ] || [ -z "$SECOND_ID" ]; then
    fail "no X-Correlation-Id header returned"
elif [ "$FIRST_ID" = "$SECOND_ID" ]; then
    fail "LEAK: both requests share correlation id $FIRST_ID"
else
    pass "distinct correlation ids ($FIRST_ID, $SECOND_ID)"
fi

say "Result"

if [ "$FAILURES" -eq 0 ]; then
    printf '  \033[32mNo state crossed between requests on a shared worker.\033[0m\n'
    exit 0
fi

printf '  \033[31m%d check(s) failed — state is leaking between requests.\033[0m\n' "$FAILURES"
exit 1
