<?php

namespace App\Domain\Grants\Member;

use App\Domain\Contracts\ContractIssuanceException;
use App\Support\CanonicalJson;

/** Detached pure document; construction confers no current source authority. */
final readonly class MemberGrantProfileDocument
{
    private function __construct(private string $canonical) {}

    public static function from(array $document): self
    {
        MemberGrantDocumentValues::bounded($document);
        MemberGrantDocumentValues::keys($document, ['schema_version', 'family', 'purpose', 'provenance', 'render_profile', 'original_terms']);
        MemberGrantDocumentValues::family($document);
        MemberGrantException::require($document['schema_version'] === 'member-grant-profile-document-v1'
            && is_array($document['render_profile']), 'invalid_document');
        MemberGrantDocumentValues::originalTerms($document['original_terms']);
        try {
            $profile = MemberGrantRenderProfile::validateStored($document['render_profile']);
        } catch (ContractIssuanceException) {
            throw new MemberGrantException('invalid_document');
        }
        MemberGrantException::require($profile['provenance'] === $document['provenance'], 'invalid_document');

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
