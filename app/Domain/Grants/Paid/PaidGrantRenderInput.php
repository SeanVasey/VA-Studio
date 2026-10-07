<?php

namespace App\Domain\Grants\Paid;

final class PaidGrantRenderInput
{
    public static function fromOrigin(array $origin): array
    {
        $source = $origin['source'];

        return ['schema_version' => 'paid-grant-render-input-v1', 'purpose' => 'paid-license-grant', 'provenance' => $source['provenance'],
            'origin_id' => $origin['origin_id'], 'effective_at' => $source['assent']['accepted_at'],
            'buyer' => ['declarations' => $source['buyer_declarations'], 'name_provenance' => 'buyer-declared-name', 'legal_identity_verified' => false],
            'source' => $source, 'assent' => $source['assent'], 'license' => $origin['disclosure'],
            'assets' => array_map(fn (array $file): array => array_intersect_key($file, array_flip(['id', 'role', 'sha256', 'size_bytes', 'descriptor', 'descriptor_hash', 'provenance', 'scan_scope'])), $origin['assets']['files']),
            'retrieval_policy' => $origin['delivery_policy'],
            'source_commitments' => ['source_hash' => $source['source_hash'], 'asset_graph_hash' => $origin['assets']['raw_hash'],
                'license_source_hash' => $source['license']['source_hash'], 'license_submission_hash' => $source['license']['submission_hash'],
                'license_review_hash' => $source['license']['review_evidence_hash'], 'assent_hash' => $source['assent_hash']]];
    }
}
