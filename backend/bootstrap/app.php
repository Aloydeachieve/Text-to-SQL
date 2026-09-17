<?php

use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', RequestIdMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $requestId = $request->attributes->get('request_id') ?? 'req_' . Str::uuid()->toString();
                $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;

                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please slow down and try again.',
                    'error_code' => 'RATE_LIMITED',
                    'retry_after' => (int) $retryAfter,
                    'request_id' => $requestId,
                ], 429, array_merge($e->getHeaders(), ['X-Request-ID' => $requestId]));
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $requestId = $request->attributes->get('request_id') ?? 'req_' . Str::uuid()->toString();
                $statusCode = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

                if ($statusCode >= 400 && $statusCode < 500) {
                    $errorCode = match ($statusCode) {
                        401 => 'AUTHENTICATION_ERROR',
                        403 => 'AUTHORIZATION_ERROR',
                        404 => 'NOT_FOUND',
                        422 => 'VALIDATION_ERROR',
                        429 => 'RATE_LIMITED',
                        default => 'CLIENT_ERROR',
                    };

                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Request validation or authorization failed.',
                        'error_code' => $errorCode,
                        'request_id' => $requestId,
                    ], $statusCode, ['X-Request-ID' => $requestId]);
                }

                if (!config('app.debug')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'An unexpected internal error occurred. Please contact support with the request ID.',
                        'error_code' => 'INTERNAL_ERROR',
                        'request_id' => $requestId,
                    ], 500, ['X-Request-ID' => $requestId]);
                }
            }
        });
    })->create();
