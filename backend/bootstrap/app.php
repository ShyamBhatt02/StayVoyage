<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie authentication (+ CSRF via XSRF-TOKEN cookie) for first-party React app.
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        $middleware->api(append: [EnsureAccountIsActive::class]);
        $middleware->alias([
            'permission' => RequirePermission::class,
            'role' => RequireRole::class,
            'admin.2fa' => EnsureAdminTwoFactor::class,
        ]);
        // Payment gateway webhooks are server-to-server and verified by signature instead of CSRF.
        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/*']);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (DomainException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error($e->getMessage(), $e->status, $e->context ? ['context' => $e->context] : [], $e->errorCode);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error($e->validator->errors()->first() ?: 'The given data was invalid.', 422, $e->errors(), 'validation_failed');
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error('Please log in to continue.', 401, [], 'unauthenticated');
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error($e->getMessage() && $e->getMessage() !== 'This action is unauthorized.' ? $e->getMessage() : 'You are not allowed to perform this action.', 403, [], 'forbidden');
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                $message = $e->getPrevious() instanceof ModelNotFoundException ? 'The requested resource was not found.' : 'Endpoint not found.';

                return ApiResponse::error($message, 404, [], 'not_found');
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error('Too many attempts. Please wait a moment and try again.', 429, [], 'rate_limited');
            }
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::error('Your session expired. Please refresh the page and try again.', 419, [], 'csrf_mismatch');
            }
        });

        // Catch-all: never leak stack traces or SQL to customers. Full detail goes to the log.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return ApiResponse::error($status >= 500 ? 'Something went wrong. Please try again.' : ($e->getMessage() ?: 'Request could not be completed.'), $status, [], 'http_error');
            }
            $payload = config('app.debug') ? ['debug' => ['exception' => get_class($e), 'message' => $e->getMessage()]] : [];

            return ApiResponse::error('Something went wrong on our side. Our team has been notified — please try again.', 500, $payload, 'server_error');
        });

        $exceptions->dontReport([DomainException::class]);
    })->create();
