<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureActiveCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Services\ErrorMonitoringService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company.active' => EnsureActiveCompany::class,
            'apikey' => AuthenticateApiKey::class,
        ]);
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Forwards unexpected (5xx-class) exceptions to a configured external error-monitoring
        // service, in addition to (not instead of) Laravel's normal logging — see
        // ErrorMonitoringService for why routine validation/404/403 rejections never reach it.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorMonitoringService::class)->report($e);
        });
    })->create();
