<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipValues;
use App\Support\CanonicalJson;
use Throwable;

/** Detached retained values only; this package proves neither ownership nor a complete period. */
final readonly class MemberActivatedOriginal
{
    private function __construct(private MemberOriginalSourceValues $source, private array $artifact, private array $identities) {}

    public static function from(array $source, array $artifact, array $identities): self
    {
        try {
            $values = MemberOriginalSourceValues::from($source);
            $source = $values->document();
            MembershipPolicyDocument::keys($artifact, ['schema_version', 'family', 'purpose', 'provenance',
                'artifact_id', 'origin_id', 'ordinal', 'role', 'sha256', 'bytes', 'storage_policy_hash',
                'render_source_hash', 'profile_hash', 'derived_relative_path', 'created_at']);
            MembershipPolicyDocument::keys($identities, ['origin_id', 'redemption_id', 'definition_id',
                'consume_event_id', 'activation_id', 'artifact_manifest_hash', 'activation_hash']);
            foreach (['artifact_id', 'origin_id'] as $key) {
                MemberGrantException::require(is_string($artifact[$key]), 'retained_original_value');
                MembershipValues::id($artifact[$key]);
            }
            foreach (['origin_id', 'redemption_id', 'definition_id', 'consume_event_id', 'activation_id'] as $key) {
                MemberGrantException::require(is_string($identities[$key]), 'retained_original_value');
                MembershipValues::id($identities[$key]);
            }
            foreach (['sha256', 'storage_policy_hash', 'render_source_hash', 'profile_hash'] as $key) {
                MemberGrantException::require(is_string($artifact[$key]), 'retained_original_value');
                MembershipValues::hash($artifact[$key]);
            }
            foreach (['artifact_manifest_hash', 'activation_hash'] as $key) {
                MemberGrantException::require(is_string($identities[$key]), 'retained_original_value');
                MembershipValues::hash($identities[$key]);
            }
            MemberGrantException::require(is_string($artifact['created_at']), 'retained_original_value');
            MembershipValues::utc($artifact['created_at']);
            $storage = $source['definition']['document']['storage_policy'];
            $path = 'contracts/production-member-v1/'.$source['provenance'].'/'.$source['origin_id'].'/'.$values->hash().'/original.pdf';
            MemberGrantException::require($artifact['schema_version'] === 'member-original-artifact-v1'
                && $artifact['family'] === $source['family'] && $artifact['purpose'] === $source['purpose']
                && $artifact['provenance'] === $source['provenance'] && $artifact['origin_id'] === $source['origin_id']
                && $artifact['ordinal'] === 0 && $artifact['role'] === 'license_pdf'
                && is_int($artifact['bytes']) && $artifact['bytes'] >= 32 && $artifact['bytes'] <= MemberOriginalFiles::MAX_BYTES
                && $artifact['storage_policy_hash'] === CanonicalJson::hash($storage)
                && $artifact['render_source_hash'] === $values->hash()
                && $artifact['profile_hash'] === CanonicalJson::hash($source['profile']['document']['render_profile'])
                && $artifact['derived_relative_path'] === $path
                && $identities['origin_id'] === $source['origin_id']
                && $identities['redemption_id'] === $source['redemption']['id']
                && $identities['definition_id'] === $source['definition']['id'], 'retained_original_value');
            $manifest = ['schema_version' => 'member-original-artifact-manifest-v1',
                'family' => $source['family'], 'purpose' => $source['purpose'], 'origin_id' => $source['origin_id'],
                'originals' => [['role' => 'license_pdf', 'sha256' => $artifact['sha256'], 'bytes' => $artifact['bytes'],
                    'storage_policy_hash' => $artifact['storage_policy_hash']]]];
            MemberGrantException::require(CanonicalJson::hash($manifest) === $identities['artifact_manifest_hash'], 'retained_original_value');

            // All remaining fields are validated scalars. Canonical copying detaches caller references.
            return new self($values, json_decode(CanonicalJson::encode($artifact), true, 8, JSON_THROW_ON_ERROR),
                json_decode(CanonicalJson::encode($identities), true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new MemberGrantException('retained_original_value');
        }
    }

    public function source(): MemberOriginalSourceValues
    {
        return $this->source;
    }

    public function artifact(): array
    {
        return $this->artifact;
    }

    public function identities(): array
    {
        return $this->identities;
    }

    public function __debugInfo(): array
    {
        return ['member_activated_original' => true];
    }
}
