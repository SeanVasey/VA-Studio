<?php

namespace App\Http\Middleware;

use App\Http\Responses\SupportAttachmentResponse as PrivateResponse;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Root prepends this before CSRF and maps its exception responses through protect(). */
final class SupportAttachmentPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('private-support', 'private-support/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        if (! in_array($request->getRealMethod(), ['GET', 'POST'], true) || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')) {
            return PrivateResponse::error(405);
        }
        if ($request->query->count() || $request->server->get('QUERY_STRING', '') !== '' || $request->headers->has('Range') || $request->headers->has('If-Range')) {
            return PrivateResponse::error(422);
        }
        $origin = $request->header('Origin');
        if (($origin !== null && $origin !== $request->getSchemeAndHttpHost()) || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
            return PrivateResponse::error(403);
        }
        if ($request->getRealMethod() === 'GET') {
            $stream = $request->getContent(true);
            if (! is_resource($stream) || stream_get_contents($stream, 1) !== '') {
                return PrivateResponse::error(422);
            }
        } else {
            if (! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
                return PrivateResponse::error(415);
            }
            $upload = str_ends_with($request->path(), '/upload');
            $wanted = $upload ? '~\Aapplication/octet-stream\z~iD' : '~\Aapplication/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD';
            if (preg_match($wanted, $request->header('Content-Type', '')) !== 1) {
                return PrivateResponse::error(415);
            }
            $length = $request->header('Content-Length');
            $max = $upload ? 5242880 : 512;
            if ($length !== null && (! ctype_digit($length) || (float) $length > $max)) {
                return PrivateResponse::error(413);
            }
            if (! $upload) {
                $stream = $request->getContent(true);
                $body = is_resource($stream) ? stream_get_contents($stream, 513) : false;
                if (! is_string($body)) {
                    return PrivateResponse::error(503);
                } if (strlen($body) > 512) {
                    return PrivateResponse::error(413);
                }
                $request->attributes->set('_support_attachment_body', $body);
            }
        }
        try {
            $response = $next($request);

            return PrivateResponse::protect($response, $request->attributes->get('_support_attachment_page') === true);
        } catch (AuthorizationException) {
            return PrivateResponse::error(403);
        } catch (TokenMismatchException) {
            return PrivateResponse::error(419);
        } catch (HttpExceptionInterface $error) {
            return PrivateResponse::error($error->getStatusCode(), $error->getHeaders());
        } catch (\Throwable $error) {
            return PrivateResponse::failure($error);
        }
    }
}
