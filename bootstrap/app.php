<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The voice webhook is a machine-to-machine POST from a platform we do
         * not host: no browser, no session, and therefore no token it could
         * possibly send. It is authenticated by a shared secret header instead
         * — see App\Http\Middleware\VerifyIntegrationSecret — which is what CSRF
         * protection stands in for on a form.
         *
         * Left in place, this would answer every real call with a 419 while
         * every test that posted directly to the controller still passed.
         */
        $middleware->validateCsrfTokens(except: ['webhooks/vapi']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
