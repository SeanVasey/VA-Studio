<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\StripeWebhookBodyLimit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::group([], base_path('routes/webhooks.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(StripeWebhookBodyLimit::class);
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'quotes', 'quotes/*', 'webhooks/stripe') || $request->expectsJson(),
        );
        $exceptions->report(function (Throwable $exception) {
            if (request()->is('webhooks/stripe')) {
                // SQL bindings and provider exception traces can contain private bodies.
                Log::error('Stripe webhook request failed.', ['exception_class' => $exception::class]);

                return false;
            }
        });
        // Middleware failures occur before the controller. Keep those private and
        // generic too, including in debug mode, without changing other routes.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->is('webhooks/stripe')) {
                $status = $response->getStatusCode();
                $code = match ($status) {
                    400, 405, 419, 422 => 'STRIPE_WEBHOOK_INVALID',
                    413 => 'STRIPE_WEBHOOK_TOO_LARGE',
                    415 => 'STRIPE_WEBHOOK_MEDIA_TYPE',
                    default => 'STRIPE_WEBHOOK_UNAVAILABLE',
                };

                $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
                foreach (['Allow', 'Retry-After'] as $name) {
                    if ($response->headers->has($name)) {
                        $headers[$name] = $response->headers->get($name);
                    }
                }

                return response()->json(['code' => $code], $status >= 500 ? 503 : $status, $headers);
            }
            if (! $request->is('quotes', 'quotes/*')) {
                return $response;
            }
            $status = $exception instanceof LockTimeoutException ? 503 : $response->getStatusCode();
            [$code, $message] = match ($status) {
                404 => ['QUOTE_NOT_FOUND', 'This selection review is unavailable.'],
                419 => ['SESSION_EXPIRED', 'Your session has expired. Refresh the page and try again.'],
                429 => ['RATE_LIMITED', 'Too many requests. Wait a moment and try again.'],
                400, 405, 413, 415, 422 => ['INVALID_QUOTE_REQUEST', 'Choose a valid selection and try again.'],
                default => ['QUOTE_UNAVAILABLE', 'Selection review is temporarily unavailable. Try again later.'],
            };
            $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff'];
            foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
                if ($response->headers->has($name)) {
                    $headers[$name] = $response->headers->get($name);
                }
            }

            return response()->json(['code' => $code, 'message' => $message], $status, $headers);
        });
    })->create();
