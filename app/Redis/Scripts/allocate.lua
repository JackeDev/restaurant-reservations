-- Atomically reserve seats across every slot a booking occupies.
--
-- Redis is single threaded and runs a script to completion before serving
-- anyone else, so the read-decide-write sequence below is indivisible. That is
-- what makes overselling impossible no matter how many application workers hit
-- this at the same moment.
--
-- KEYS    = every slot key in the booking's dwell window
-- ARGV[1] = capacity per slot
-- ARGV[2] = party size
-- ARGV[3] = ttl in seconds
--
-- Returns the tightest number of seats left in the window on success, or -1 if
-- any slot in the window is too full to take the party.

local capacity = tonumber(ARGV[1])
local party    = tonumber(ARGV[2])
local ttl      = tonumber(ARGV[3])

-- Pass 1: check every slot first. A single full slot vetoes the whole booking,
-- so that we never have to undo a partial write.
for i = 1, #KEYS do
  local remaining = redis.call('GET', KEYS[i])

  if remaining == false then
    remaining = capacity          -- never touched, so the slot is still empty
  else
    remaining = tonumber(remaining)
  end

  if remaining < party then
    return -1
  end
end

-- Pass 2: everything checked out, so committing cannot fail part way through.
local worst = capacity

for i = 1, #KEYS do
  if redis.call('EXISTS', KEYS[i]) == 0 then
    redis.call('SET', KEYS[i], capacity, 'EX', ttl)
  end

  local left = redis.call('DECRBY', KEYS[i], party)

  if left < worst then
    worst = left
  end
end

return worst
