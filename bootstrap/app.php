<?php

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\ProviderSpendLimitException;
use App\Http\Middleware\AuthenticateApiRequest;
use App\Http\Middleware\RequireAppKey;
use App\Http\Middleware\RequirePlatformOperator;
use App\Http\Middleware\RequireWorkspaceRole;
use App\Http\Middleware\ResolveWorkspaceContext;
use App\Http\Middleware\SecurityHeaders;
use App\Services\ObservabilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'app.key' => RequireAppKey::class,
            'api.auth' => AuthenticateApiRequest::class,
            'workspace.context' => ResolveWorkspaceContext::class,
            'workspace.role' => RequireWorkspaceRole::class,
            'platform.operator' => RequirePlatformOperator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            InsufficientCreditsException::class,
            ProviderSpendLimitException::class,
        ]);

        $exceptions->report(function (Throwable $exception) {
            $errorReference = request()?->attributes->get('error_reference');
            if (! is_string($errorReference) || $errorReference === '') {
                $errorReference = 'ERR-'.Str::upper(Str::random(10));
                request()?->attributes->set('error_reference', $errorReference);
            }

            app(ObservabilityService::class)->reportException($exception, [
                'error_reference' => $errorReference,
                'path' => request()?->path(),
                'method' => request()?->method(),
                'workspace_id' => request()?->attributes->get('workspace_id'),
                'user_id' => request()?->attributes->get('supabase_user_id'),
            ]);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ProviderSpendLimitException) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'provider_safety_pause',
                ], 503);
            }

            if ($exception instanceof InsufficientCreditsException) {
                $context = $exception->context();
                $subscriptionInactive = array_key_exists('subscriptionStatus', $context);

                return response()->json(array_filter([
                    'message' => $exception->getMessage(),
                    'code' => $subscriptionInactive ? 'subscription_inactive' : 'insufficient_credits',
                    'creditBucket' => $context['bucket'] ?? null,
                    'requiredCredits' => $context['required'] ?? null,
                    'availableCredits' => $context['available'] ?? null,
                ], fn ($value) => $value !== null), 402);
            }

            if ($exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof HttpResponseException) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            if ($status < 500) {
                return null;
            }

            $errorReference = $request->attributes->get('error_reference');
            if (! is_string($errorReference) || $errorReference === '') {
                $errorReference = 'ERR-'.Str::upper(Str::random(10));
                $request->attributes->set('error_reference', $errorReference);
            }

            return response()->json([
                'message' => 'Something went wrong. Please try again. If it continues, contact support with this reference.',
                'errorReference' => $errorReference,
            ], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
        });
    })->create();
