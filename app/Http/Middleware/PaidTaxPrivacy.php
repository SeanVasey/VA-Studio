<?php

namespace App\Http\Middleware;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxForm;
use App\Domain\Grants\PaidTax\PaidTaxJson;
use App\Http\Tax\PaidTaxStreamResponse;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class PaidTaxPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('paid-tax-grants', 'paid-tax-grants/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! in_array($request->getRealMethod(), ['GET', 'POST'], true)
                || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')) {
                return self::error(405);
            }
            if ($request->query->count() || $request->server->get('QUERY_STRING', '') !== ''
                || $request->headers->has('Range') || $request->headers->has('If-Range')) {
                return self::error(422);
            }
            if (($request->hasHeader('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost())
                || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
                return self::error(403);
            }
            if (! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
                return self::error(415);
            }
            $post = $request->getRealMethod() === 'POST';
            $native = $post && $request->is('paid-tax-grants/origins/*/authorizations/*/redeem')
                && preg_match('~\Aapplication/x-www-form-urlencoded(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $request->header('Content-Type', '')) === 1;
            if ($post && ! $native && preg_match('~\Aapplication/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $request->header('Content-Type', '')) !== 1) {
                return self::error(415);
            }
            $maximum = ! $post ? 0 : ($native ? 4096 : 65536);
            $length = $request->header('Content-Length');
            if ($length !== null && (! ctype_digit($length) || (float) $length > $maximum)) {
                return self::error($post ? 413 : 422);
            }
            $stream = $request->getContent(true);
            $body = is_resource($stream) ? stream_get_contents($stream, $maximum + 1) : false;
            PaidTaxException::require(is_string($body));
            PaidTaxException::require(strlen($body) <= $maximum, $post ? 413 : 422);
            if ($post) {
                if ($native) {
                    $fields = PaidTaxForm::body($body);
                    $request->request->replace($fields);
                    $body = json_encode(['token' => $fields['token']], JSON_THROW_ON_ERROR);
                } else {
                    $request->setJson(new InputBag(PaidTaxJson::body($body)));
                }
                $request->attributes->set('_paid_tax_body', $body);
            }
            $response = $next($request);
            try {
                return self::protect($response);
            } catch (Throwable $error) {
                if ($response instanceof PaidTaxStreamResponse) {
                    $response->close();
                }
                throw $error;
            }
        } catch (Throwable $error) {
            return self::refusal($error);
        }
    }

    public static function refusal(Throwable $error): Response
    {
        $status = match (true) {
            $error instanceof PaidTaxException => $error->status,
            $error instanceof TokenMismatchException => 419,
            $error instanceof IdentityException, $error instanceof AuthorizationException => 403,
            $error instanceof ValidationException => 422,
            $error instanceof LockTimeoutException => 503,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 503,
        };
        if ($status >= 500 && ! $error instanceof PaidTaxException && ! $error instanceof HttpExceptionInterface) {
            self::report($error);
        }

        return self::error($status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
    }

    public static function report(Throwable $error): void
    {
        try {
            Log::error('Private Tax request failed.', ['exception_class' => $error::class]);
        } catch (Throwable) {
        }
    }

    public static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Cookie'])));

        return $response;
    }

    public static function error(int $status, array $headers = []): Response
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            if (is_array($value) && count($value) === 1) {
                $value = reset($value);
            }
            if (! is_string($value) || strlen($value) > 80) {
                continue;
            }
            $lower = strtolower($name);
            if (($lower === 'allow' && preg_match('/\A(?:GET|HEAD|POST|OPTIONS)(?:, ?(?:GET|HEAD|POST|OPTIONS))*\z/D', $value))
                || (in_array($lower, ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining'], true) && preg_match('/\A[0-9]{1,10}\z/D', $value))) {
                $safe[$name] = $value;
            }
        }

        return self::protect(new JsonResponse(['code' => 'PAID_TAX_UNAVAILABLE', 'message' => 'This Tax request is unavailable or changed. Refresh before trying again.'],
            $status >= 400 && $status <= 599 ? $status : 503, $safe));
    }
}
