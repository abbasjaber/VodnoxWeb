<?php

use App\Http\Middleware\EnsureActiveOrganization;
use App\Http\Middleware\EnsureFeatureSubscription;
use App\Http\Middleware\EnsureTechnicalSession;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
        ]);

        $middleware->api(prepend: [
            SetLocale::class,
        ]);

        $middleware->alias([
            'set.locale' => SetLocale::class,
            'role' => RoleMiddleware::class,
            'tenant' => EnsureActiveOrganization::class,
            'feature' => EnsureFeatureSubscription::class,
            'technical.session' => EnsureTechnicalSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Force JSON responses for API requests on errors.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return true;
            }

            return $request->expectsJson();
        });

        // Custom JSON structure for validation errors.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => __('validation.generic_failed'),
                    'data' => null,
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Custom JSON structure for HTTP exceptions (404, 403, 401, etc.).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = $e->getStatusCode();
                $message = match ($status) {
                    401 => __('auth.unauthenticated'),
                    403 => __('auth.forbidden'),
                    404 => __('api.not_found'),
                    405 => __('api.method_not_allowed'),
                    429 => __('api.too_many_requests'),
                    default => $e->getMessage() ?: __('api.error'),
                };

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'data' => null,
                    'errors' => null,
                ], $status);
            }
        });
    })->create();
