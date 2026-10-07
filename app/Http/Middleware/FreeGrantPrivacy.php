<?php

namespace App\Http\Middleware;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantForm;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class FreeGrantPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('free-grants', 'free-grants/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        if ($request->attributes->get('_free_grant_checked') === true) {
            return self::protect($next($request));
        }
        if ($request->query->count() || $request->server->get('QUERY_STRING', '') !== ''
            || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')
            || $request->headers->has('Range') || $request->headers->has('If-Range')
            || ! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
            return self::error(422);
        }
        if (($request->hasHeader('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost())
            || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
            return self::error(403);
        }
        if ($request->isMethod('POST')) {
            $native = $request->is('free-grants/authorizations/*/redeem') && preg_match('~\Aapplication/x-www-form-urlencoded(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $request->header('Content-Type', '')) === 1;
            if (! $request->isJson() && ! $native) {
                return self::error(415);
            }
            $stream = $request->getContent(true);
            $body = is_resource($stream) ? stream_get_contents($stream, 65537) : false;
            if ($body === false || strlen($body) > 65536) {
                return self::error(413);
            }
            if ($native) {
                try {
                    $fields = FreeGrantForm::body($body);
                    // CSRF receives the same validated native form bytes; no input merging.
                    $request->request->replace($fields);
                    $body = json_encode(['token' => $fields['token']], JSON_THROW_ON_ERROR);
                } catch (FreeGrantException $error) {
                    return self::error($error->status);
                }
            }
            $request->attributes->set('_free_grant_body', $body);
        } else {
            $stream = $request->getContent(true);
            if (! $request->isMethod('GET') || ! is_resource($stream) || stream_get_contents($stream, 1) !== '') {
                return self::error(422);
            }
        }
        $request->attributes->set('_free_grant_checked', true);
        try {
            return self::protect($next($request));
        } catch (HttpExceptionInterface $error) {
            return self::error($error->getStatusCode());
        } catch (TokenMismatchException) {
            return self::error(419);
        } catch (FreeGrantException $error) {
            return self::error($error->status);
        } catch (AuthorizationException|CustomerAccessException) {
            return self::error(403);
        } catch (ValidationException) {
            return self::error(422);
        } catch (Throwable $error) {
            self::report($error);

            return self::error(503);
        }
    }

    public static function report(Throwable $error): void
    {
        try {
            Log::error('Free grant request failed.', ['exception_class' => $error::class]);
        } catch (Throwable) { /* Reporting failure cannot disclose the private request. */
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

    public static function error(int $status): Response
    {
        return self::protect(response()->json(['code' => 'FREE_GRANT_UNAVAILABLE',
            'message' => 'This free grant is unavailable or changed. Refresh before trying again.'], $status));
    }
}
