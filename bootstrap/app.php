<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'quotes', 'quotes/*') || $request->expectsJson(),
        );
        // Middleware failures occur before the controller. Keep those private and
        // generic too, including in debug mode, without changing other routes.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
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
