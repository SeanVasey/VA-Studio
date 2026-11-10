<?php

namespace App\Domain\Services\Catalog;

use App\Domain\Services\Models\ServiceDraftVersion;
use App\Domain\Services\Models\ServicePublication;
use App\Domain\Services\Projects\ServiceProjectPolicy;
use App\Domain\Services\ServiceDraftManifest;
use App\Support\CanonicalJson;
use Throwable;

/**
 * Read model of the public service pages (BL-64). It returns nothing while the catalog flag is off, and otherwise only
 * services staff approved and have not withdrawn, rendered from the pinned, hash-verified draft version. Staff-authored
 * values only: a service without a staff-entered price says so. A page whose pinned version fails verification is
 * skipped rather than shown.
 */
final class ServiceCatalog
{
    public const LIMIT = 50;

    public const QUOTED_ON_REQUEST = 'Quoted on request';

    /** Only these authored declarations are shown, in this order; an unresolved one is left out. */
    private const SECTIONS = ['scope' => 'Scope', 'revisions' => 'Revisions', 'deposit' => 'Deposit', 'cancellation' => 'Cancellation'];

    public function enabled(): bool
    {
        return app(ServiceProjectPolicy::class)->catalogEnabled();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $services = [];
        foreach (ServicePublication::query()->whereNull('withdrawn_at')->orderBy('slug')->limit(self::LIMIT)->get() as $publication) {
            $service = $this->project($publication);
            if ($service !== null) {
                $services[] = $service;
            }
        }

        return $services;
    }

    public function find(string $slug): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $publication = ServicePublication::query()->whereNull('withdrawn_at')->where('slug', $slug)->first();

        return $publication === null ? null : $this->project($publication);
    }

    /** The text the contact form starts with for a service request; null for an unknown, withdrawn or disabled service. */
    public function inquiryPrefill(mixed $slug): ?array
    {
        $service = is_string($slug) && strlen($slug) <= 80 ? $this->find($slug) : null;

        return $service === null ? null : ['slug' => $service['slug'], 'title' => $service['title'], 'subject' => 'Service request',
            'message' => 'I would like to request this service: '.$service['title']."\n\n"];
    }

    /** The version's manifest when it decrypts, passes the service format and matches its recorded hash; otherwise null. */
    public static function verifiedManifest(ServiceDraftVersion $version): ?array
    {
        try {
            $manifest = (new ServiceDraftManifest)->verified($version->manifest);

            return hash_equals(CanonicalJson::hash($manifest), (string) $version->manifest_sha256) ? $manifest : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function project(ServicePublication $publication): ?array
    {
        $version = ServiceDraftVersion::query()->find($publication->service_draft_version_id);
        if ($version === null || (int) $version->draft_id !== (int) $publication->service_draft_id
            || ! hash_equals((string) $publication->manifest_sha256, (string) $version->manifest_sha256)) {
            return null;
        }
        $manifest = self::verifiedManifest($version);
        if ($manifest === null) {
            return null;
        }
        $sections = [];
        foreach (self::SECTIONS as $key => $label) {
            if ($manifest[$key]['status'] === 'authored') {
                $sections[] = ['label' => $label, 'text' => $manifest[$key]['text']];
            }
        }

        return ['slug' => $publication->slug, 'title' => $manifest['title'], 'description' => $manifest['description'],
            'sections' => $sections, 'price' => self::price($publication->price_minor, $publication->currency),
            'path' => '/services/'.$publication->slug, 'requestHref' => '/contact?service='.$publication->slug];
    }

    /** The staff-entered price as text, or "Quoted on request". Currencies are limited to two-digit minor units. */
    public static function price(?int $minor, ?string $currency): string
    {
        if ($minor === null || $minor < 1 || ! in_array($currency, ServicePublications::CURRENCIES, true)) {
            return self::QUOTED_ON_REQUEST;
        }

        return $currency.' '.number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
