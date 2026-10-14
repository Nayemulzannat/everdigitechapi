<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\BlockWhileImpersonating;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureClientEmailVerified;
use App\Http\Middleware\EnsureClientIsActive;
use App\Http\Middleware\RequireAccountOwner;
use App\Http\Middleware\RequireAccountPermission;
use App\Http\Middleware\ResolveAccountContext;
use App\Http\Middleware\TrackClientSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api/health.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Each area is a folder of route files: routes/api/admin/*.php, routes/api/client/*.php.
            foreach (['admin', 'client'] as $area) {
                Route::middleware('api')->prefix("api/{$area}")->name("{$area}.")->group(function () use ($area) {
                    foreach (glob(base_path("routes/api/{$area}/*.php")) as $file) {
                        require $file;
                    }
                });
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA auth: requests from SANCTUM_STATEFUL_DOMAINS get the session + CSRF middleware.
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->alias([
            'admin.active' => EnsureAdminIsActive::class,
            'permission' => PermissionMiddleware::class,
            // Client panel
            'client.active' => EnsureClientIsActive::class,
            'client.session' => TrackClientSession::class,
            'client.not_impersonating' => BlockWhileImpersonating::class,
            'client.verified' => EnsureClientEmailVerified::class,
            'account.context' => ResolveAccountContext::class,
            'account.permission' => RequireAccountPermission::class,
            'account.owner' => RequireAccountOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This app only speaks JSON: never render an HTML error page.
        $exceptions->shouldRenderJsonWhen(fn () => true);
        // One error shape for every failure: { message, code, errors? }.
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
