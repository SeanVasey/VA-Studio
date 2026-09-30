<?php

namespace App\Domain\SiteBuilder;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The published site content cannot be read safely.
 *
 * Public pages fail closed with a generic 503. Rendering it as a validation redirect would bounce a visitor to
 * the site root in a loop or back to another site, and code defaults must never replace the active release.
 */
final class SiteContentUnavailable extends ValidationException
{
    private const PAGE = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>Temporarily unavailable</title></head><body><p>This site is temporarily unavailable. Please try again shortly.</p></body></html>';

    /** @param  array<string, array<int, string>>  $errors */
    public static function because(array $errors): self
    {
        return self::withMessages($errors);
    }

    public function render(Request $request): Response
    {
        return response(self::PAGE, 503, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Retry-After' => '60',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
