<?php

namespace App\Http\Middleware;

use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Protect track administration and its exact authenticated derivative responses. */
final class PrivateTrackReviewPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('admin/tracks', 'admin/tracks/*', 'admin/media/*/preview', 'admin/media/*/preview/*')
            || $request->attributes->get('_track_private_review') === true
            || self::hasSignedTrackUpdate($request);
    }

    /** CSRF/header middleware can fail before ManageTracks::boot marks an update. */
    private static function hasSignedTrackUpdate(Request $request): bool
    {
        $route = $request->route();
        if (! $request->isMethod('POST') || ! ($route instanceof Route) || ! $route->named('*livewire.update')
            || $route->getActionName() !== HandleRequests::class.'@handleUpdate') {
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
                    || ! is_string($snapshot['memo']['name'] ?? null) || ! is_string($snapshot['checksum'] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['checksum']) !== 1) {
                    continue;
                }
                $checksum = $snapshot['checksum'];
                unset($snapshot['checksum']);
                // Use the locked framework's signing canonicalization (including its children exception).
                // Do not run verification/failure hooks, hydrate state or instantiate a component here.
                if (! hash_equals($checksum, Checksum::generate($snapshot))) {
                    continue;
                }
                if ($snapshot['memo']['name'] === app('livewire.finder')->normalizeName(ManageTracks::class)) {
                    $request->attributes->set('_track_private_review', true);

                    return true;
                }
            } catch (Throwable) {
                // Unauthenticated or malformed client input cannot opt into this boundary.
            }
        }

        return false;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

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

    public static function error(int $status, Response $original): Response
    {
        $headers = [];
        foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
            if ($original->headers->has($name)) {
                $headers[$name] = $original->headers->get($name);
            }
        }

        return self::protect(response('Private track review is unavailable.', $status >= 500 ? 503 : $status, $headers));
    }
}
