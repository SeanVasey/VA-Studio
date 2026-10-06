<?php

namespace App\Http\Middleware;

use App\Filament\Resources\SoundKitDraftResource\Pages\UploadSoundKit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Private admission and response boundary, including failures before controller execution. */
final class ResumableUploadPrivacy
{
    public static function endpoint(Request $request): bool
    {
        return $request->is('admin/resumable-uploads', 'admin/resumable-uploads/*', 'admin/sound-kit-uploads', 'admin/sound-kit-uploads/*');
    }

    public static function matches(Request $request): bool
    {
        return self::endpoint($request) || $request->is('admin/media-assets/resumable-upload', 'admin/sound-kit-drafts/*/resumable-upload')
            || $request->attributes->get('_kit_resumable_page') === true || self::signedKitPageUpdate($request);
    }

    /** Keep CSRF and hydration errors private before the kit page can recheck its actor. */
    private static function signedKitPageUpdate(Request $request): bool
    {
        $route = $request->route();
        if (! ($route instanceof Route) || ! $route->named('*livewire.update')
            || $route->getActionName() !== HandleRequests::class.'@handleUpdate' || ! $request->isMethod('POST')) {
            return false;
        }
        $components = $request->input('components');
        if (! is_array($components)) {
            return false;
        }
        foreach ($components as $component) {
            if (! is_array($component) || ! is_string($component['snapshot'] ?? null)) {
                continue;
            }
            try {
                $snapshot = json_decode($component['snapshot'], associative: true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($snapshot) || ! is_array($snapshot['data'] ?? null) || ! is_array($snapshot['memo'] ?? null)
                    || ! is_string($snapshot['memo']['name'] ?? null) || ! is_string($snapshot['checksum'] ?? null)) {
                    continue;
                }
                $checksum = $snapshot['checksum'];
                unset($snapshot['checksum']);
                if ($snapshot['memo']['name'] === app('livewire.finder')->normalizeName(UploadSoundKit::class)
                    && hash_equals($checksum, Checksum::generate($snapshot))) {
                    $request->attributes->set('_kit_resumable_page', true);

                    return true;
                }
            } catch (Throwable) {
                // Client input alone cannot select this private response boundary.
            }
        }

        return false;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (self::endpoint($request)) {
            if (! in_array($request->getRealMethod(), ['GET', 'POST'], true)
                || $request->headers->has('X-HTTP-Method-Override') || $request->request->has('_method')
                || $request->getRealMethod() !== $request->method()) {
                return self::error(405);
            }
            if ($request->query->count() || $request->server->get('QUERY_STRING', '') !== ''
                || $request->headers->has('Range') || $request->headers->has('If-Range')) {
                return self::error(422);
            }
            $origin = $request->header('Origin');
            if (($origin !== null && $origin !== $request->getSchemeAndHttpHost())
                || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
                return self::error(403);
            }
            if (! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
                return self::error(415);
            }
            if ($request->isMethod('POST')) {
                $chunk = $request->is('admin/resumable-uploads/*/chunks', 'admin/sound-kit-uploads/*/chunks');
                $type = $request->header('Content-Type', '');
                if (($chunk && preg_match('~\Amultipart/form-data\s*;~i', $type) !== 1)
                    || (! $chunk && preg_match('~\Aapplication/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $type) !== 1)) {
                    return self::error(415);
                }
                // The proxy/PHP request ceiling must allow this bounded multipart envelope.
                // The controller independently bounds the actual uploaded chunk and form fields.
                $limit = $chunk ? 8 * 1024 * 1024 + 65536 : 4096;
                $length = $request->header('Content-Length');
                if ($length !== null && (! ctype_digit($length) || (float) $length > $limit)) {
                    return self::error(413);
                }
                if (! $chunk) {
                    $stream = $request->getContent(true);
                    $body = is_resource($stream) ? stream_get_contents($stream, $limit + 1) : false;
                    if ($body === false) {
                        return self::error(503);
                    }
                    if (strlen($body) > $limit) {
                        return self::error(413);
                    }
                    $request->attributes->set('_resumable_body', $body);
                }
            }
        }
        $response = $next($request);
        if (self::endpoint($request) && $response->isRedirection()) {
            return self::error(403);
        }

        return self::matches($request) ? self::protect($response) : $response;
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

    public static function error(int $status, ?Response $original = null): Response
    {
        $headers = [];
        foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
            if ($original?->headers->has($name)) {
                $headers[$name] = $original->headers->get($name);
            }
        }
        $message = match ($status) {
            401, 403 => 'This upload is unavailable. Sign in with an authorized operator account.',
            419 => 'Your session expired. Reload this page, then inspect the upload before continuing.',
            413 => 'The upload request is too large. Send one permitted chunk at a time.',
            429 => 'Too many upload requests. Wait, then inspect the upload before continuing.',
            400, 404, 405, 415, 422 => 'The upload request could not be accepted. Check the file and inspect the upload before continuing.',
            default => 'The upload result could not be confirmed. Inspect its current state before retrying.',
        };

        return self::protect(response()->json(['message' => $message], $status >= 500 ? 503 : $status, $headers));
    }
}
