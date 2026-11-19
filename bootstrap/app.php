<?php

use App\Http\Middleware\AllowOnlyKnownHosts;
use App\Http\Middleware\AllowOnlyMpesaIps;
use App\Http\Middleware\PreventDuplicateRequests;
use App\Http\Middleware\RequireApiKey;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxy trust is set in AppServiceProvider, where configuration is
        // loaded: this callback runs before that.
        $middleware->alias([
            'mpesa.ips' => AllowOnlyMpesaIps::class,
            'known.hosts' => AllowOnlyKnownHosts::class,
            'api.key' => RequireApiKey::class,
            'idempotent' => PreventDuplicateRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API failure answers in the same envelope as every success.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The request is not valid.',
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
                errorCode: 'validation.failed',
                errors: $e->errors(),
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage() ?: 'The request could not be completed.',
                status: $e->getStatusCode(),
                errorCode: 'http.'.$e->getStatusCode(),
            );
        });
    })->create();
