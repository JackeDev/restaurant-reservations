<?php

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
