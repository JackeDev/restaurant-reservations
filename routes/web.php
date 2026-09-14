<?php

use App\Http\Controllers\VapiWebhookController;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\VerifyIntegrationSecret;
use Illuminate\Support\Facades\Route;

/*
 * This project has no UI. The root route only advertises where the MCP server
 * lives so that anyone opening the app in a browser is not met with a 404.
 *
 * The actual MCP endpoint is registered in routes/ai.php by laravel/mcp.
 */
Route::get('/', fn (): array => [
    'name' => config('restaurant.name'),
    'mcp' => url('/mcp'),
    'health' => url('/up'),
]);

/*
 * The second transport. A voice platform calls this when its agent has agreed a
 * booking out loud, and it reaches the same ReservationService the MCP tool uses.
 *
 * Middleware order is deliberate:
 *
 *   - the correlation id comes first, so a rejected request is as traceable as
 *     an accepted one — the requests worth investigating are usually the ones
 *     that were turned away;
 *   - the rate limit comes before the secret check, so guessing the secret is
 *     throttled rather than free;
 *   - the secret check comes last, being the only one that needs the body to
 *     have survived this far.
 *
 * The endpoint is exempt from CSRF in bootstrap/app.php, since it is called by a
 * machine with a shared secret and no session to protect.
 */
Route::post('/webhooks/vapi', VapiWebhookController::class)
    ->middleware([AssignCorrelationId::class, 'throttle:mcp', VerifyIntegrationSecret::class])
    ->name('webhooks.vapi');
