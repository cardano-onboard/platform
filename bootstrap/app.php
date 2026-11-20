<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Opt-in short claim subdomain (e.g. claim.onbd.io/v1/{campaign}). Registered
            // without the /api prefix so the URL — and thus the QR payload — stays short.
            if ($claimDomain = config('cardano.claim_domain')) {
                Route::middleware('api')
                    ->domain($claimDomain)
                    ->group(__DIR__.'/../routes/claim.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Which proxies may supply the client address is read from config on each request.
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustProxies::class);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Global, so the web, API and claim-subdomain routes all count as traffic. Its work
        // is done in terminate(), after the response has been sent.
        $middleware->append(\App\Http\Middleware\RunOverdueHousekeeping::class);

        $middleware->web(append: [
            // Appended rather than prepended because it needs the session, which
            // StartSession opens earlier in this group.
            \App\Http\Middleware\CaptureSourceCode::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Every web route checks the session against the account's current password, so a
        // password change ends the other sessions wherever they are used. The alias points
        // at the application's subclass, which also refuses a session with no stored hash.
        $middleware->authenticateSessions();

        $middleware->alias([
            'auth.session' => \App\Http\Middleware\AuthenticateSession::class,
            'proxy.quota' => \App\Http\Middleware\CheckProxyQuota::class,
            'proxy.log' => \App\Http\Middleware\LogProxyUsage::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
