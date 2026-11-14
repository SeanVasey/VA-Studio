<?php

namespace App\Http\Membership;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Memberships\Production\MembershipException;
use App\Http\ProductionFree\ProductionFreeBody;
use Closure;
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
 * First root seam for the member HTTP mount (C4e), prepended in `bootstrap/app.php` like the family 256 seam. A stale
 * cached route table never overrides the literal runtime mount: unmounted is the same not-found response as every other
 * refusal, before any session, identity or SQL work. Then the raw request is bounded before the web stack reads it: GET
 * or POST only, no query string, no Range, same-origin, identity encoding, and small JSON bodies with unique keys.
 *
 * Refusals (review F7 and F8). Every domain refusal is one identical not-found body, so period, redemption, owner and
 * gate reasons are not an existence oracle. Two writer outcomes differ because they are not "nothing happened": a
 * committed write whose receipt was refused is `202 MEMBERSHIP_SUBMITTED` (refresh the index; never retry blindly), and
 * a writer's generic `operation_refused` (for example a commit whose result is unknown) is
 * `503 MEMBERSHIP_OUTCOME_UNKNOWN` (refresh, or repeat the exact same request: the writers are idempotent).
 */
final class MembershipPrivacy
{
    public const BODY = '_production_membership_body';

    public const JSON_LIMIT = 1024;

    public static function matches(Request $request): bool
    {
        return $request->is('production-memberships', 'production-memberships/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        if (! MembershipHttpMount::admitted()) {
            return self::notFound();
        }
        try {
            if (! in_array($request->getRealMethod(), ['GET', 'POST'], true)
                || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')) {
                return self::error(405, ['Allow' => 'GET, POST']);
            }
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
            if ($post && preg_match('~\Aapplication/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $request->header('Content-Type', '')) !== 1) {
                return self::error(415);
            }
            $maximum = $post ? self::JSON_LIMIT : 0;
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
                try {
                    // The reviewed family 256 syntax pass: duplicate object keys are refused before PHP decoding.
                    $decoded = ProductionFreeBody::json($body);
                } catch (ProductionFreeGrantException) {
                    return self::error(422);
                }
                $request->setJson(new InputBag($decoded));
                $request->attributes->set(self::BODY, $decoded);
            } else {
                $request->attributes->set(self::BODY, []);
            }

            return self::protect($next($request));
        } catch (Throwable $error) {
            return self::refusal($error);
        }
    }

    /**
     * Map a failure to the fixed responses. `$write` is true only for a failure raised by a membership writer itself:
     * only then can a generic refusal hide a committed effect.
     */
    public static function refusal(Throwable $error, bool $write = false): Response
    {
        if ($error instanceof MembershipException) {
            return match (true) {
                // The capsule refused before any domain call: no session, a changed session or a changed actor.
                $error->reason === 'identity_refused' => self::error(403),
                $write && $error->reason === 'committed_receipt_refused' => self::submitted(),
                $write && $error->reason === 'operation_refused' => self::outcomeUnknown(),
                default => self::notFound(),
            };
        }
        if ($write) {
            // A writer always classifies its own failures; anything else from it has an unknown outcome.
            self::report($error);

            return self::outcomeUnknown();
        }
        $status = match (true) {
            $error instanceof TokenMismatchException => 419,
            $error instanceof ValidationException => 422,
            $error instanceof LockTimeoutException => 503,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 503,
        };
        if ($status >= 500 && ! $error instanceof HttpExceptionInterface) {
            self::report($error);
        }

        return $status === 404 ? self::notFound() : self::error($status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
    }

    public static function report(Throwable $error): void
    {
        try {
            Log::error('Production membership request failed.', ['exception_class' => $error::class]);
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
    public static function json(array $data): JsonResponse
    {
        $response = (new JsonResponse)->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)->setData($data);
        self::protect($response);

        return $response;
    }

    /** F8: one identical body for every refusal, an unknown route and the unmounted family. */
    public static function notFound(): Response
    {
        return self::protect(new JsonResponse(['code' => 'MEMBERSHIP_NOT_FOUND'], 404));
    }

    /** F7: the write is durable; its receipt was refused. Refresh the index; do not treat it as a refusal. */
    public static function submitted(): Response
    {
        return self::protect(new JsonResponse(['code' => 'MEMBERSHIP_SUBMITTED', 'refresh' => true], 202));
    }

    /** F7: the writer cannot tell whether its commit happened. Refresh, or repeat the exact same request. */
    public static function outcomeUnknown(): Response
    {
        return self::protect(new JsonResponse(['code' => 'MEMBERSHIP_OUTCOME_UNKNOWN', 'refresh' => true, 'retry' => 'same_request'],
            503, ['Retry-After' => '1']));
    }

    /** Fixed private error shape for transport failures. Only allowlisted numeric/method headers survive. */
    public static function error(int $status, array $headers = []): Response
    {
        if ($status === 404) {
            return self::notFound();
        }
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

        return self::protect(new JsonResponse(['code' => 'MEMBERSHIP_UNAVAILABLE'], $status, $safe));
    }
}
