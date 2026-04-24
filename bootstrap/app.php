<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Router;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (Application $app) {
            if ($app->environment('testing')) {
                $app->make(Router::class)
                    ->middleware('web')
                    ->group(base_path('routes/testing.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\EnsureSiteOpen::class,
        ]);
        // Exclude test-only helper routes from CSRF verification so the
        // Playwright APIRequestContext (which has no session/token) can hit them.
        $middleware->validateCsrfTokens(except: ['testing/*']);

        // We sit behind Cloudflare → Forge Nginx (TLS terminator) → PHP-FPM.
        // Trust forwarded headers so the app sees the original scheme (https),
        // client IP, and host — needed for url() helpers, $request->secure(),
        // secure cookies, and rate limiting by real client IP.
        $middleware->trustProxies(at: '*');

        // Aliases used by route definitions.
        $middleware->alias([
            'signups' => \App\Http\Middleware\EnsureSignupsEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
