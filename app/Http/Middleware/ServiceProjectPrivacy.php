<?php

namespace App\Http\Middleware;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Services\Projects\ServiceProjectException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ServiceProjectPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('services/projects', 'services/projects/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        if ($request->attributes->get('_service_project_checked') === true) {
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
            if (! $request->isJson()) {
                return self::error(415);
            }
            $stream = $request->getContent(true);
            $body = is_resource($stream) ? stream_get_contents($stream, 65537) : false;
            if ($body === false || strlen($body) > 65536) {
                return self::error(413);
            }
            $request->attributes->set('_service_project_body', $body);
        } else {
            $stream = $request->getContent(true);
            if (! $request->isMethod('GET') || ! is_resource($stream) || stream_get_contents($stream, 1) !== '') {
                return self::error(422);
            }
        }
        $request->attributes->set('_service_project_checked', true);
        try {
            return self::protect($next($request));
        } catch (TokenMismatchException) {
            return self::error(419);
        } catch (ServiceProjectException $error) {
            return self::error($error->status);
        } catch (AuthorizationException|CustomerAccessException) {
            return self::error(403);
        } catch (ValidationException) {
            return self::error(422);
        } catch (Throwable $error) {
            return self::failure($error);
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
        return self::protect(response()->json(['code' => 'SERVICE_PROJECT_UNAVAILABLE',
            'message' => 'This service project is unavailable or changed. Refresh before trying again.'], $status));
    }

    public static function failure(Throwable $error): Response
    {
        try {
            Log::error('Service project request failed.', ['exception_class' => $error::class]);
        } catch (Throwable) {
            // Logging failure must preserve the same private response.
        }

        return self::error(503);
    }
}
