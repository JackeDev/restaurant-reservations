-- Recover seats that leaked out of a slot counter.
--
-- If a worker dies between taking seats in Redis and writing the reservation to
-- Postgres, those seats stay taken with no booking behind them. Postgres is the
-- record of what was actually sold, so this recomputes what the counter should
-- be and repairs the difference.
--
-- KEYS[1] = the slot key
-- ARGV[1] = capacity per slot
-- ARGV[2] = seats genuinely booked for this slot, counted in Postgres
-- ARGV[3] = ttl in seconds, used when the key does not exist yet
--
-- Returns 1 if the counter was repaired, 0 if it was already correct or higher.

local capacity  = tonumber(ARGV[1])
local booked    = tonumber(ARGV[2])
local ttl       = tonumber(ARGV[3])
local should_be = capacity - booked

local current = redis.call('GET', KEYS[1])

if current == false then
  redis.call('SET', KEYS[1], should_be, 'EX', ttl)
  return 1
end

-- Only ever raise the counter. Lowering it would be unsafe: a booking made
-- between counting in Postgres and running this script would be counted twice,
-- and the slot could then be oversold by the very job meant to keep it honest.
if should_be > tonumber(current) then
  redis.call('SET', KEYS[1], should_be, 'KEEPTTL')
  return 1
end

return 0
