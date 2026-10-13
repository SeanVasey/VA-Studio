<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Support\CanonicalJson;

/** Detached commitment only. Neither a valid shape nor these pure factories issue readiness. */
final readonly class MemberActivationReadiness
{
    private const IDS = ['origin_id', 'redemption_id', 'period_id', 'reservation_event_id', 'definition_id', 'profile_id'];

    private const HASHES = ['redemption_seal', 'period_seal', 'reservation_event_seal', 'render_source_hash',
        'source_invoice_hash', 'policy_hash', 'consumption_terms_hash', 'retention_policy_hash',
        'authorization_document_hash', 'authorization_intent_hash', 'definition_hash', 'profile_hash', 'artifact_manifest_hash'];

    private function __construct(private string $canonical) {}

    public static function from(array $value): self
    {
        MemberGrantDocumentValues::keys($value, ['schema_version', 'family', 'purpose', 'provenance', ...self::IDS, ...self::HASHES]);
        MemberGrantException::require($value['schema_version'] === 'member-activation-readiness-v1'
            && $value['family'] === MemberGrantIntent::FAMILY && $value['purpose'] === MemberGrantIntent::PURPOSE, 'invalid_readiness');
        MemberGrantDocumentValues::provenance($value['provenance']);
        foreach (self::IDS as $key) {
            MemberGrantDocumentValues::id($value[$key]);
        }
        foreach (self::HASHES as $key) {
            MemberGrantDocumentValues::hash($value[$key]);
        }
        MemberGrantException::require($value['origin_id'] === $value['redemption_id'], 'invalid_readiness');

        return new self(CanonicalJson::encode($value));
    }

    public static function forSource(MemberOriginalSourceValues $source, array $authorization,
        MembershipPolicyBinding $policy, MemberOriginalArtifactManifest $manifest): self
    {
        $s = $source->document();
        $definition = $s['definition']['document'];
        MemberGrantException::require($policy->supports('consume') && $policy->consumptionTerms !== null
            && $policy->planVersionId === $s['invoice']['plan_version_id'] && $policy->policyHash === $s['invoice']['policy_hash']
            && $policy->originalTermsHash === $s['invoice']['original_terms_hash'] && $policy->provenance === $s['provenance']
            && $policy->retentionPolicyHash === CanonicalJson::hash($definition['retention_policy'])
            && $policy->consumptionTerms->retentionHash() === $policy->retentionPolicyHash, 'unsupported_policy');
        $a = MemberLicenseAuthorizationRecords::verifyRetained($authorization, $source, $policy);
        // Reconstruct a detached manifest; the public DTO may have retained caller array references.
        $originals = json_decode(CanonicalJson::encode($manifest->originals), true, 16, JSON_THROW_ON_ERROR);
        MemberGrantException::require(array_is_list($originals) && count($originals) === 1, 'invalid_manifest');
        MemberGrantDocumentValues::keys($originals[0], ['role', 'sha256', 'bytes', 'storage_policy_hash']);
        MemberGrantDocumentValues::hash($originals[0]['sha256']);
        MemberGrantDocumentValues::hash($originals[0]['storage_policy_hash']);
        MemberGrantDocumentValues::integer($originals[0]['bytes'], 32, MemberOriginalFiles::MAX_BYTES);
        MemberGrantException::require($manifest->originId === $s['origin_id'] && count($originals) === 1
            && $originals[0]['role'] === 'license_pdf' && $originals[0]['bytes'] >= 32
            && $originals[0]['bytes'] <= MemberOriginalFiles::MAX_BYTES
            && $originals[0]['storage_policy_hash'] === CanonicalJson::hash($definition['storage_policy']), 'invalid_manifest');
        $m = ['schema_version' => 'member-original-artifact-manifest-v1', 'family' => MemberGrantIntent::FAMILY,
            'purpose' => MemberGrantIntent::PURPOSE, 'origin_id' => $s['origin_id'], 'originals' => $originals];
        MemberGrantException::require(CanonicalJson::hash($m) === $manifest->manifestHash, 'invalid_manifest');

        return self::from(['schema_version' => 'member-activation-readiness-v1', 'family' => MemberGrantIntent::FAMILY,
            'purpose' => MemberGrantIntent::PURPOSE, 'provenance' => $s['provenance'], 'origin_id' => $s['origin_id'],
            'redemption_id' => $s['redemption']['id'], 'redemption_seal' => $s['redemption']['seal'],
            'period_id' => $s['period']['id'], 'period_seal' => $s['period']['seal'],
            'reservation_event_id' => $s['reserve_event']['id'], 'reservation_event_seal' => $s['reserve_event']['seal'],
            'render_source_hash' => $source->hash(), 'source_invoice_hash' => $s['invoice']['source_invoice_hash'],
            'policy_hash' => $policy->policyHash, 'consumption_terms_hash' => $policy->consumptionTerms->hash(),
            'retention_policy_hash' => $policy->retentionPolicyHash, 'authorization_document_hash' => CanonicalJson::hash($a),
            'authorization_intent_hash' => $a['intent_hash'], 'definition_id' => $s['definition']['id'],
            'definition_hash' => CanonicalJson::hash($definition), 'profile_id' => $s['profile']['id'],
            'profile_hash' => CanonicalJson::hash($s['profile']['document']), 'artifact_manifest_hash' => $manifest->manifestHash]);
    }

    public function value(): array
    {
        return json_decode($this->canonical, true, 16, JSON_THROW_ON_ERROR);
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonical);
    }
}
