<?php

use App\Exceptions\Api\ApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Must run first so preflight OPTIONS and error responses get CORS headers
        // (needed for Vercel frontend → Hostinger API).
        $middleware->prepend(HandleCors::class);

        $middleware->alias([
            'merchant' => \App\Http\Middleware\EnsureMerchantAccount::class,
            'customer' => \App\Http\Middleware\EnsureCustomerAccount::class,
            'platform-admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'marketing' => \App\Http\Middleware\EnsureMarketingAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ApiException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $e->render();
            }

            return null;
        });
    })->create();
