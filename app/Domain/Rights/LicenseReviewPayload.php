<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;
use App\Support\CanonicalJson;

final class LicenseReviewPayload
{
    public function build(LicenseVersion $version): array
    {
        $content = app(LicenseContent::class)->validate($version->only(['authored_source', 'structured_terms', 'effective_from', 'effective_until']));
        $template = $version->template()->firstOrFail();
        $preview = app(LicensePreview::class)->render($version);

        return [
            'canonicalization_version' => CanonicalJson::VERSION,
            'license_version_id' => $version->id, 'version' => $version->version,
            'template' => $template->only(['id', 'name', 'slug', 'type']),
            'author_id' => $version->author_id, 'content_author_ids' => $version->content_author_ids, 'predecessor_id' => $version->predecessor_id,
            'authored_source' => $content['authored_source'], 'structured_terms' => $content['structured_terms'],
            'source_hash' => hash('sha256', $content['authored_source']), 'model_hash' => CanonicalJson::hash($content['structured_terms']),
            'effective_from' => $content['effective_from']?->format('Y-m-d\TH:i:s\Z'),
            'effective_until' => $content['effective_until']?->format('Y-m-d\TH:i:s\Z'),
            'renderer_version' => $preview['renderer_version'], 'render_fixture_hash' => $preview['sha256'],
        ];
    }
}
