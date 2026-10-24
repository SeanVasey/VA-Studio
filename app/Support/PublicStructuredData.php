<?php

namespace App\Support;

use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use LogicException;

/**
 * schema.org JSON-LD for public pages (FP-014), built only from the already-filtered public projections and the page's
 * own metadata. It follows the sitemap's exclusion rule: nothing outside production, where the synthetic rehearsal
 * catalog is refused, and the staff preview controller never asks for it. Only public controllers call this.
 *
 * No Offer or price is emitted: public checkout is disabled, so availability for purchase would not be true.
 */
final class PublicStructuredData
{
    private const BRAND = 'VASEY.AUDIO';

    /** Owning brand per the repository's project notes; never VASEY/AI. */
    private const OWNER = 'Sean Vasey Productions';

    private const LOGO_PATH = '/brand/vasey-audio-logo.png';

    public function forHome(array $metadata): ?string
    {
        if (! $this->admitted()) {
            return null;
        }
        $origin = $this->origin();

        return $this->encode([
            $this->organization($origin),
            ['@type' => 'WebSite', '@id' => $origin.'/#website', 'name' => self::BRAND, 'url' => $origin.'/',
                'description' => $metadata['description'], 'publisher' => ['@id' => $origin.'/#organization']],
        ]);
    }

    /** @param  array<string, mixed>  $track  one entry of the public catalog projection (already eligibility-checked) */
    public function forTrack(array $track, array $metadata): ?string
    {
        if (! $this->admitted() || $this->synthetic($track)) {
            return null;
        }
        $origin = $this->origin();
        $url = $metadata['canonicalUrl'];
        $name = $this->text($track['title']);

        return $this->encode([
            array_filter([
                '@type' => 'MusicRecording', '@id' => $url.'#recording', 'name' => $name, 'url' => $url,
                // schema.org MusicGroup covers solo musicians too, so no person/group claim is made.
                'byArtist' => ['@type' => 'MusicGroup', 'name' => $this->text($track['artist'])],
                'genre' => is_string($track['genre'] ?? null) && trim($track['genre']) !== '' ? $this->text($track['genre']) : null,
                'image' => $metadata['imageUrl'],
            ], fn ($value) => $value !== null),
            $this->breadcrumbs([[self::BRAND, $origin.'/'], [$name, $url]]),
        ]);
    }

    /** Blog entries only. Other editorial pages carry no structured data rather than a generic or invented type. */
    public function forEditorial(array $page, array $metadata, ?array $collection): ?string
    {
        if (! $this->admitted() || $page['section'] !== 'blog' || $page['kind'] !== 'entry' || $collection === null) {
            return null;
        }
        $origin = $this->origin();
        $url = $metadata['canonicalUrl'];
        $headline = $this->text($page['title']);

        return $this->encode([
            ['@type' => 'BlogPosting', '@id' => $url.'#article', 'headline' => $headline, 'description' => $this->text($page['description']),
                'url' => $url, 'mainEntityOfPage' => $url, 'publisher' => $this->organization($origin)],
            $this->breadcrumbs([[self::BRAND, $origin.'/'], [$this->text($collection['title']), $origin.$collection['path']], [$headline, $url]]),
        ]);
    }

    private function admitted(): bool
    {
        return app()->environment('production');
    }

    /** Defence in depth beside the production gate: labelled rehearsal content is never described. */
    private function synthetic(array $track): bool
    {
        return in_array(SyntheticRehearsalCatalog::MARKER, $track['tags'] ?? [], true)
            || str_starts_with((string) $track['title'], SyntheticRehearsalCatalog::TITLE_PREFIX)
            || $track['artist'] === SyntheticRehearsalCatalog::ARTIST;
    }

    private function organization(string $origin): array
    {
        return ['@type' => 'Organization', '@id' => $origin.'/#organization', 'name' => self::BRAND, 'url' => $origin.'/',
            'logo' => $origin.self::LOGO_PATH, 'parentOrganization' => ['@type' => 'Organization', 'name' => self::OWNER]];
    }

    /** @param  list<array{0: string, 1: string}>  $items */
    private function breadcrumbs(array $items): array
    {
        $list = [];
        foreach ($items as $index => [$name, $item]) {
            $list[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $name, 'item' => $item];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    private function origin(): string
    {
        // Same identity source as StorefrontMetadata: the configured site URL, never the request Host.
        return rtrim((string) config('app.url'), '/');
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * The result is placed raw inside <script type="application/ld+json">, in Blade and through the Inertia head
     * manager. JSON_HEX_TAG and JSON_HEX_AMP turn every <, > and & into \u escapes and the output is pure ASCII, so no
     * value can close the element, open a comment or carry a line separator. The check below keeps that invariant.
     */
    private function encode(array $graph): string
    {
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        if (preg_match('/[<>&]|[^\x20-\x7E]/', $json) === 1) {
            throw new LogicException('Structured data must not contain raw markup characters.');
        }

        return $json;
    }
}
