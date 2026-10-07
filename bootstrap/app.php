<?php

use App\Http\Middleware\CustomerPrivacy;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InquiryPrivacy;
use App\Http\Middleware\PrivateTrackReviewPrivacy;
use App\Http\Middleware\ProductionIdentity\IdentityPrivacy;
use App\Http\Middleware\ResumableUploadPrivacy;
use App\Http\Middleware\ServiceProjectPrivacy;
use App\Http\Middleware\SupportAttachmentPrivacy;
use App\Http\Middleware\SitePreviewPrivacy;
use App\Http\Middleware\StripeWebhookBodyLimit;
use App\Http\Middleware\TestDeliveryPrivacy;
use App\Http\Middleware\TestExceptionResolutionPrivacy;
use App\Http\Responses\InquiryResponse;
use App\Http\Responses\PublicTrackEmbedResponse;
use App\Http\Responses\SupportAttachmentResponse;
use App\Http\Responses\TestDeliveryResponse;
use App\Http\Responses\TestExceptionResolutionResponse;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/webhooks.php'));
            Route::group([], base_path('routes/embeds.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(StripeWebhookBodyLimit::class);
        $middleware->prepend(TestDeliveryPrivacy::class);
        $middleware->prepend(TestExceptionResolutionPrivacy::class);
        $middleware->prepend(SitePreviewPrivacy::class);
        $middleware->prepend(InquiryPrivacy::class);
        $middleware->prepend(PrivateTrackReviewPrivacy::class);
        $middleware->prepend(ResumableUploadPrivacy::class);
        $middleware->prepend(CustomerPrivacy::class);
        $middleware->prepend(ServiceProjectPrivacy::class);
        $middleware->prepend(IdentityPrivacy::class);
        $middleware->prepend(SupportAttachmentPrivacy::class);
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'proof', 'requestKey']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ((CustomerPrivacy::matches($request) || ServiceProjectPrivacy::matches($request)) && $request->isMethod('POST')) || ResumableUploadPrivacy::endpoint($request) || $request->is('api/*', 'quotes', 'quotes/*', 'orders', 'orders/*', 'webhooks/stripe') || $request->expectsJson(),
        );
        $exceptions->report(function (Throwable $exception) {
            if (SupportAttachmentPrivacy::matches(request())) {
                try {
                    Log::error('Private attachment request failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Preserve the private response. */
                }

                return false;
            }
            if (IdentityPrivacy::matches(request())) {
                try {
                    Log::error('Customer identity request failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Preserve the private response. */
                }

                return false;
            }
            if (ServiceProjectPrivacy::matches(request())) {
                try {
                    Log::error('Service project request failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Preserve the private response. */
                }

                return false;
            }
            if (TestExceptionResolutionResponse::matches(request())) {
                try {
                    Log::error('Test resolution request failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Keep private failures generic if the logger fails. */
                }

                return false;
            }
            if (CustomerPrivacy::matches(request())) {
                try {
                    Log::error('Customer request failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Preserve the private response. */
                }

                return false;
            }
            if (ResumableUploadPrivacy::matches(request())) {
                try {
                    Log::error('Resumable upload failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Preserve the private response when reporting fails. */
                }

                return false;
            }
            if (PrivateTrackReviewPrivacy::matches(request())) {
                try {
                    Log::error('Private track review failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Keep private failures generic if reporting fails. */
                }

                return false;
            }
            if (PublicTrackEmbedResponse::matches(request())) {
                try {
                    Log::error('Public track preview failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Preserve the generic public response if reporting fails. */
                }

                return false;
            }
            if (InquiryResponse::matches(request())) {
                try {
                    Log::error('Inquiry request failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Keep reporting generic even if the logger fails. */
                }

                return false;
            }
            if (SitePreviewPrivacy::matches(request())) {
                try {
                    Log::error('Site preview request failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Preserve a generic private response if reporting fails. */
                }

                return false;
            }
            if (TestDeliveryResponse::matches(request())) {
                try {
                    Log::error('Test delivery middleware failed.', ['exception_class' => $exception::class]);
                } catch (Throwable) { /* Reporting failure must preserve the generic private response. */
                }

                return false;
            }
            if (request()->is('orders', 'orders/*', 'quotes/*/order', 'quotes/*/order-review')) {
                Log::error('Order request failed.', ['exception_class' => $exception::class]);

                return false;
            }
            if (request()->is('webhooks/stripe')) {
                // SQL bindings and provider exception traces can contain private bodies.
                Log::error('Stripe webhook request failed.', ['exception_class' => $exception::class]);

                return false;
            }
        });
        // Middleware failures occur before the controller. Keep those private and
        // generic too, including in debug mode, without changing other routes.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (SupportAttachmentPrivacy::matches($request)) {
                return $response->getStatusCode() >= 400 ? SupportAttachmentResponse::error($response->getStatusCode()) : SupportAttachmentResponse::protect($response);
            }
            if (IdentityPrivacy::matches($request)) {
                return $response->getStatusCode() >= 400 ? IdentityPrivacy::error($response->getStatusCode()) : IdentityPrivacy::protect($response);
            }
            if (ServiceProjectPrivacy::matches($request)) {
                return $response->getStatusCode() >= 400 ? ServiceProjectPrivacy::error($response->getStatusCode()) : ServiceProjectPrivacy::protect($response);
            }
            if (TestExceptionResolutionResponse::matches($request)) {
                $status = $exception instanceof LockTimeoutException ? 503 : $response->getStatusCode();
                $headers = [];
                foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
                    if ($response->headers->has($name)) {
                        $headers[$name] = $response->headers->get($name);
                    }
                }

                return TestExceptionResolutionResponse::error($status, $headers);
            }
            if (CustomerPrivacy::matches($request)) {
                return $response->getStatusCode() >= 400 ? CustomerPrivacy::error($response->getStatusCode()) : CustomerPrivacy::protect($response);
            }
            if (ResumableUploadPrivacy::matches($request)) {
                $status = $exception instanceof ValidationException ? $exception->status : $response->getStatusCode();

                return $status >= 400 ? ResumableUploadPrivacy::error($status, $response) : ResumableUploadPrivacy::protect($response);
            }
            if (PrivateTrackReviewPrivacy::matches($request)) {
                $status = $exception instanceof ValidationException ? $exception->status : $response->getStatusCode();

                return $status >= 400 ? PrivateTrackReviewPrivacy::error($status, $response) : PrivateTrackReviewPrivacy::protect($response);
            }
            if (PublicTrackEmbedResponse::matches($request)) {
                return PublicTrackEmbedResponse::error($response->getStatusCode(), $response);
            }
            if (InquiryResponse::matches($request)) {
                $status = $exception instanceof LockTimeoutException ? 503 : $response->getStatusCode();
                if ($status < 400) {
                    return InquiryResponse::protect($response);
                }
                $headers = [];
                foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
                    if ($response->headers->has($name)) {
                        $headers[$name] = $response->headers->get($name);
                    }
                }
                if ($request->is('contact/inquiries', 'contact/inquiries/*')) {
                    return InquiryResponse::error($status, headers: $headers);
                }

                return InquiryResponse::protect(response('Inquiry inbox is unavailable.', $status >= 500 ? 503 : $status, $headers));
            }
            if (SitePreviewPrivacy::matches($request)) {
                $status = $exception instanceof ValidationException ? $exception->status : $response->getStatusCode();

                return $status >= 400 ? SitePreviewPrivacy::error($status, $response) : SitePreviewPrivacy::protect($response);
            }
            if (TestDeliveryResponse::matches($request)) {
                $status = $exception instanceof LockTimeoutException ? 503 : $response->getStatusCode();
                $headers = [];
                foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
                    if ($response->headers->has($name)) {
                        $headers[$name] = $response->headers->get($name);
                    }
                }

                return TestDeliveryResponse::error($status, headers: $headers);
            }
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
            if (! $request->is('quotes', 'quotes/*', 'orders', 'orders/*')) {
                return $response;
            }
            $isOrder = $request->is('orders', 'orders/*', 'quotes/*/order', 'quotes/*/order-review');
            $status = $exception instanceof LockTimeoutException ? 503 : $response->getStatusCode();
            [$code, $message] = match ($status) {
                404 => [$isOrder ? 'ORDER_NOT_FOUND' : 'QUOTE_NOT_FOUND', 'This selection review is unavailable.'],
                419 => ['SESSION_EXPIRED', 'Your session has expired. Refresh the page and try again.'],
                429 => ['RATE_LIMITED', 'Too many requests. Wait a moment and try again.'],
                400, 405, 413, 415, 422 => [$isOrder ? 'INVALID_ORDER_REQUEST' : 'INVALID_QUOTE_REQUEST', 'Choose a valid selection and try again.'],
                default => [$isOrder ? 'ORDER_UNAVAILABLE' : 'QUOTE_UNAVAILABLE', 'Selection review is temporarily unavailable. Try again later.'],
            };
            $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff'];
            if ($request->is('orders/*/checkout', 'orders/*/checkout/*')) {
                $headers['Referrer-Policy'] = 'no-referrer';
                $headers['X-Robots-Tag'] = 'noindex, nofollow';
            }
            foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
                if ($response->headers->has($name)) {
                    $headers[$name] = $response->headers->get($name);
                }
            }

            return response()->json(['code' => $code, 'message' => $message], $status, $headers);
        });
    })->create();
