<?php

namespace App\Domain\Grants\Free;

use App\Support\CanonicalJson;

final class FreeGrantRenderInput
{
    public static function fromOrigin(array $origin): array
    {
        $definition = $origin['definition'];

        return ['schema_version' => 'free-grant-render-input-v1', 'purpose' => 'free-license-grant', 'test_only' => $definition['test_only'],
            'origin_id' => $origin['origin_id'], 'effective_at' => $origin['accepted_at'],
            'buyer' => ['declared_name' => $origin['declared_name'], 'name_provenance' => 'buyer-declared-name', 'legal_identity_verified' => false],
            'definition' => ['public_id' => $origin['definition_id'], 'title' => $definition['title'], 'terms_reference' => $definition['terms_reference'],
                'definition_hash' => $origin['definition_hash'], 'review_hash' => $origin['review_hash'], 'collection' => 'none', 'amount_minor' => 0],
            'assent' => $origin['assent'] + ['exact_text' => $definition['assent_text']], 'license' => $definition['source']['disclosure'],
            'assets' => array_map(fn (array $asset): array => array_intersect_key($asset, array_flip(['id', 'role', 'sha256', 'mime_type', 'size_bytes'])), $definition['source']['assets']),
            'retrieval_policy' => ['max_committed_attempts' => $definition['max_downloads'], 'authorization_ttl_seconds' => $definition['token_ttl_seconds']],
            'source_commitments' => ['graph_hash' => CanonicalJson::hash($definition['source']['raw']),
                'license_source_hash' => $definition['source']['license']['source_hash'], 'license_submission_hash' => $definition['source']['license']['submission_hash'],
                'license_review_hash' => $definition['source']['license']['review_evidence_hash'], 'scope_hash' => CanonicalJson::hash($definition['source']['raw']['scope'])]];
    }
}
