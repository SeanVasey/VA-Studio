<?php

namespace App\Domain\Grants\Member;

use App\Support\CanonicalJson;

/** Complete retained author input, without adoption of a plan, catalog source or host. */
final readonly class MemberGrantDefinitionDocument
{
    private function __construct(private string $canonical) {}

    public static function from(array $document): self
    {
        MemberGrantDocumentValues::bounded($document);
        MemberGrantDocumentValues::keys($document, ['schema_version', 'family', 'purpose', 'version', 'provenance', 'profile_hash',
            'plan_version_id', 'policy_hash', 'license_manifest', 'asset_manifest', 'original_terms_hash', 'retention_policy', 'storage_policy']);
        MemberGrantDocumentValues::family($document);
        MemberGrantException::require($document['schema_version'] === 'member-grant-definition-document-v1' && $document['version'] === 1, 'invalid_document');
        foreach (['profile_hash', 'policy_hash', 'original_terms_hash'] as $key) {
            MemberGrantDocumentValues::hash($document[$key]);
        }
        MemberGrantDocumentValues::id($document['plan_version_id']);
        MemberGrantDocumentValues::license($document['license_manifest']);
        MemberGrantDocumentValues::assets($document['asset_manifest'], $document['license_manifest']['required_asset_roles']);
        MemberGrantException::require(CanonicalJson::hash(MemberGrantDocumentValues::termsProjection($document['license_manifest'])) === $document['original_terms_hash'], 'invalid_document');
        $retention = $document['retention_policy'];
        MemberGrantDocumentValues::keys($retention, ['schema_version', 'reference', 'text']);
        MemberGrantException::require($retention['schema_version'] === 'member-original-retention-facts-v1', 'invalid_document');
        MemberGrantDocumentValues::text($retention['reference'], 160);
        MemberGrantDocumentValues::text($retention['text'], 65536, true);
        $storage = $document['storage_policy'];
        MemberGrantDocumentValues::keys($storage, ['schema_version', 'backend', 'layout', 'provenance', 'ownership_reference', 'retention_policy_hash']);
        MemberGrantException::require($storage['schema_version'] === 'member-original-storage-policy-v1' && $storage['backend'] === 'private_local'
            && $storage['layout'] === 'production-member-candidate-v1' && $storage['provenance'] === $document['provenance']
            && $storage['retention_policy_hash'] === CanonicalJson::hash($retention), 'invalid_document');
        MemberGrantDocumentValues::text($storage['ownership_reference'], 160);

        return new self(CanonicalJson::encode($document));
    }

    public function value(): array
    {
        return json_decode($this->canonical, true, 32, JSON_THROW_ON_ERROR);
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonical);
    }
}
