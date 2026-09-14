<?php

namespace App\Providers;

use App\Contracts\SlotAllocator;
use App\Redis\RedisSlotAllocator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Safe as a singleton under Octane: the allocator holds no request
         * state, only the Redis connection factory and the cached SHA of each
         * Lua script. See the class docblock.
         */
        $this->app->singleton(SlotAllocator::class, RedisSlotAllocator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * laravel/mcp loads routes/ai.php in an empty route group, so the MCP
     * endpoint gets no throttling at all unless we attach it ourselves.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('mcp', function (Request $request): Limit {
            $perMinute = (int) config('reservations.rate_limit');

            /*
             * The limit is per IP, not global, so it caps abuse by a single
             * caller without capping overall throughput. Set MCP_RATE_LIMIT=0
             * when load testing: k6 drives everything from one IP and would
             * otherwise measure the limiter rather than the application.
             */
            return $perMinute > 0
                ? Limit::perMinute($perMinute)->by($request->ip())
                : Limit::none();
        });
    }
}
