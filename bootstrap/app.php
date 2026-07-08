<?php

use App\Http\Middleware\AuthenticateApiRequest;
use App\Http\Middleware\RequireAppKey;
use App\Http\Middleware\RequireWorkspaceRole;
use App\Http\Middleware\ResolveWorkspaceContext;
use App\Http\Middleware\SecurityHeaders;
use App\Services\ObservabilityService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__ . '/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'app.key' => RequireAppKey::class,
            'api.auth' => AuthenticateApiRequest::class,
            'workspace.context' => ResolveWorkspaceContext::class,
            'workspace.role' => RequireWorkspaceRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception) {
            app(ObservabilityService::class)->reportException($exception, [
                'path' => request()?->path(),
                'method' => request()?->method(),
                'workspace_id' => request()?->attributes->get('workspace_id'),
                'user_id' => request()?->attributes->get('supabase_user_id'),
            ]);
        });
    })->create();
