<?php

namespace App\Domain\Grants\ProductionFree;

/** The render input is a pure function of the sealed origin payload: recovery never re-reads live catalog data. */
final class ProductionFreeGrantRenderInput
{
    public static function fromOrigin(array $origin): array
    {
        $definition = $origin['definition'];

        return ['schema_version' => 'production-free-grant-render-input-v1', 'purpose' => ProductionFreeGrantSchema::PURPOSE,
            'provenance' => $origin['provenance'], 'origin_id' => $origin['origin_id'], 'effective_at' => $origin['accepted_at'],
            'buyer' => ['declared_name' => $origin['declared_name'], 'name_provenance' => 'buyer-declared-name',
                'legal_identity_verified' => false, 'account_reference' => $origin['buyer_binding']['account_public_id']],
            'definition' => ['id' => $origin['definition_id'], 'title' => $definition['title'], 'terms_reference' => $definition['terms_reference'],
                'definition_hash' => $origin['definition_hash'], 'terms_hash' => $definition['terms_hash'], 'review_id' => $origin['review_id'],
                'collection' => 'none', 'amount_minor' => 0],
            'terms' => ['text' => $definition['terms_text']],
            'assent' => ['text' => $definition['assent_text'], 'affirmed' => true, 'display_hash' => $origin['display_hash'], 'at' => $origin['accepted_at']],
            'assets' => array_map(fn (array $asset): array => array_intersect_key($asset, array_flip(['role', 'source_id', 'sha256', 'bytes', 'mime_type', 'filename'])),
                $definition['assets'])];
    }
}
