-- Give seats back across a booking's dwell window.
--
-- Used to compensate when persisting a reservation fails after the seats were
-- already taken, and when a booking is cancelled.
--
-- KEYS    = every slot key in the booking's dwell window
-- ARGV[1] = party size
-- ARGV[2] = capacity per slot
--
-- Returns 1.

local party    = tonumber(ARGV[1])
local capacity = tonumber(ARGV[2])

for i = 1, #KEYS do
  if redis.call('EXISTS', KEYS[i]) == 1 then
    local restored = redis.call('INCRBY', KEYS[i], party)

    -- Clamp. Without this, releasing the same booking twice would inflate the
    -- slot above the number of seats the restaurant actually has.
    if restored > capacity then
      redis.call('SET', KEYS[i], capacity, 'KEEPTTL')
    end
  end
end

return 1
