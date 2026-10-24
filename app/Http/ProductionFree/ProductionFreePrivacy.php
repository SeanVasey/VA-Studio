<?php

namespace App\Http\ProductionFree;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
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

/**
 * First root seam for the family 256 mount, prepended in `bootstrap/app.php` like the Tax and account-feature privacy
 * middleware. A stale cached route table never overrides the literal runtime mount: unmounted is a plain 404 before
 * any session, identity or SQL work. Then the raw request is bounded before the web stack reads it: GET or POST only,
 * no query string, no Range, same-origin, identity encoding, JSON bodies with unique keys and one native form for the
 * redemption. Every response is private and never cached; every failure is one fixed JSON shape.
 */
final class ProductionFreePrivacy
{
    public const BODY = '_production_free_body';

    public static function matches(Request $request): bool
    {
        return $request->is('production-free-grants', 'production-free-grants/*');
    }

    public static function redemption(Request $request): bool
    {
        return $request->is('production-free-grants/authorizations/*/redeem');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        if (! ProductionFreeHttpMount::admitted()) {
            return self::error(404);
        }
        try {
            if (! in_array($request->getRealMethod(), ['GET', 'POST'], true)
                || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')) {
                return self::error(405, ['Allow' => 'GET, POST']);
            }
            // Whole-file delivery only, and nothing private in a URL.
            if ($request->query->count() !== 0 || $request->server->get('QUERY_STRING', '') !== ''
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
            $type = $request->header('Content-Type', '');
            $form = $post && self::redemption($request)
                && preg_match('~\Aapplication/x-www-form-urlencoded(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $type) === 1;
            if ($post && ! $form && preg_match('~\Aapplication/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $type) !== 1) {
                return self::error(415);
            }
            $maximum = ! $post ? 0 : ($form ? ProductionFreeBody::FORM_LIMIT : ProductionFreeBody::JSON_LIMIT);
            $length = $request->header('Content-Length');
            if ($length !== null && (! ctype_digit($length) || (float) $length > $maximum)) {
                return self::error($post ? 413 : 422);
            }
            $stream = $request->getContent(true);
            $body = is_resource($stream) ? stream_get_contents($stream, $maximum + 1) : false;
            if (! is_string($body)) {
                return self::error(422);
            }
            if (strlen($body) > $maximum) {
                return self::error($post ? 413 : 422);
            }
            if ($post) {
                if ($form) {
                    $fields = ProductionFreeBody::form($body);
                    // The framework's CSRF check reads `_token` from here; the controller reads only the token.
                    $request->request->replace($fields);
                    $request->attributes->set(self::BODY, ['token' => $fields['token']]);
                } else {
                    $decoded = ProductionFreeBody::json($body);
                    $request->setJson(new InputBag($decoded));
                    $request->attributes->set(self::BODY, $decoded);
                }
            } else {
                $request->attributes->set(self::BODY, []);
            }
            $response = $next($request);
            try {
                return self::protect($response);
            } catch (Throwable $error) {
                if ($response instanceof ProductionFreeDownloadResponse) {
                    $response->close();
                }
                throw $error;
            }
        } catch (Throwable $error) {
            return self::refusal($error);
        }
    }

    /** Fixed refusal reasons to HTTP. `contention` is retryable (A1-6); `outcome_unknown` means a committed write may exist. */
    public static function status(string $reason): int
    {
        return match ($reason) {
            'not_found', 'not_open', 'not_entitled', 'disabled', 'environment', 'capability_absent', 'not_mounted' => 404,
            'identity_refused', 'token_refused', 'provenance' => 403,
            'invalid_input', 'assent_required', 'unsupported_text' => 422,
            'expired', 'revoked' => 410,
            'rate_limited' => 429,
            'contention', 'outcome_unknown', 'artifact_unavailable', 'artifact_drift', 'spool_busy', 'spool_space', 'key_absent',
            'changed_policy', 'changed_connection', 'changed_transaction', 'commit_not_proved', 'transaction_required',
            'transaction_forbidden', 'schema', 'schema_namespace', 'schema_prefix', 'parent_floor', 'shadowed_schema', 'tampered',
            'external_dependent', 'retained_unguarded_schema', 'storage_failed', 'payload_too_large' => 503,
            // Stale input, a used key, a cap, a pending original or a changed entitlement: refresh and decide again.
            default => 409,
        };
    }

    public static function refusal(Throwable $error): Response
    {
        if ($error instanceof ProductionFreeGrantException) {
            $status = self::status($error->reason);
            $headers = match ($error->reason) {
                'contention' => ['Retry-After' => '1'],
                'rate_limited' => ['Retry-After' => '60'],
                default => [],
            };

            return self::error($status, $headers, $error->reason);
        }
        $status = match (true) {
            $error instanceof TokenMismatchException => 419,
            $error instanceof IdentityException, $error instanceof AuthorizationException => 403,
            $error instanceof ValidationException => 422,
            $error instanceof LockTimeoutException => 503,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 503,
        };
        if ($status >= 500 && ! $error instanceof HttpExceptionInterface) {
            self::report($error);
        }

        return self::error($status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
    }

    public static function report(Throwable $error): void
    {
        try {
            Log::error('Production free grant request failed.', ['exception_class' => $error::class]);
        } catch (Throwable) {
            // Preserve the private generic response if reporting fails.
        }
    }

    public static function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
    }

    public static function protect(Response $response): Response
    {
        foreach (self::headers() as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Cookie'])));

        return $response;
    }

    /** The success projection: plain JSON, private headers, never a page or history prop. */
    public static function json(array $data): Response
    {
        return self::protect((new JsonResponse)->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)->setData($data));
    }

    public static function code(int $status, ?string $reason): string
    {
        return match (true) {
            $reason === 'outcome_unknown' => 'PRODUCTION_FREE_GRANT_OUTCOME_UNKNOWN',
            $reason === 'contention' => 'PRODUCTION_FREE_GRANT_CONTENTION',
            default => 'PRODUCTION_FREE_GRANT_UNAVAILABLE',
        };
    }

    /** One fixed private error shape. Only allowlisted numeric/method headers survive; the reason is a fixed internal code. */
    public static function error(int $status, array $headers = [], ?string $reason = null): Response
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            if (is_array($value) && count($value) === 1) {
                $value = reset($value);
            }
            if (! is_string($value) || strlen($value) > 80) {
                continue;
            }
            $lower = strtolower((string) $name);
            if (($lower === 'allow' && preg_match('/\A(?:GET|HEAD|POST|OPTIONS)(?:, ?(?:GET|HEAD|POST|OPTIONS))*\z/D', $value))
                || (in_array($lower, ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining'], true) && preg_match('/\A[0-9]{1,10}\z/D', $value))) {
                $safe[$name] = $value;
            }
        }
        $status = $status >= 400 && $status <= 599 ? $status : 503;
        $body = ['code' => self::code($status, $reason)];
        if ($reason !== null && preg_match('/\A[a-z_]{1,40}\z/D', $reason) === 1) {
            $body['reason'] = $reason;
        }

        return self::protect(new JsonResponse($body, $status, $safe));
    }
}
