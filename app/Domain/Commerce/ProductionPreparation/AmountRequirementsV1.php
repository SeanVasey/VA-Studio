<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1 as Check;
use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPolicy\SourceCommitment;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;

/** Pure schema projection. Caller must authenticate the retained packet; this grants no authority. */
final class AmountRequirementsV1
{
    public const PURPOSE = 'production_amount_evidence_requirements';

    public static function project(array $packet): array
    {
        Check::record($packet, ['schema_version', 'purpose', 'public_id', 'created_by', 'created_at', 'request', 'request_hash', 'capability', 'catalog_graph', 'catalog_graph_hash', 'selection']);
        Check::require($packet['schema_version'] === 1 && $packet['purpose'] === PacketEvidence::PURPOSE
            && CapabilityHistory::uuid($packet['public_id']) && Check::integer($packet['created_by'], 1, MinorUnits::MAX)
            && is_string($packet['created_at']) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $packet['created_at']) === 1);
        $request = $packet['request'];
        Check::record($request, ['actor_id', 'candidate_id', 'context', 'items', 'key_digest']);
        Check::require($request['actor_id'] === $packet['created_by'] && SourceCommitment::hash($request['key_digest'])
            && SourceCommitment::hash($packet['request_hash']) && $packet['request_hash'] === CanonicalJson::hash($request));
        Check::require(is_array($request['items']));
        $items = PreparationSelection::items($request['items']);
        Check::require($items === $request['items']);
        $capability = $packet['capability'];
        Check::record($capability, ['schema_version', 'purpose', 'context', 'source', 'candidate_id', 'candidate_public_id', 'candidate_hash', 'generation', 'approval_id', 'approval_hash', 'machine_hash', 'machine', 'external_facts_verified', 'execution_allowed', 'signature']);
        Check::require($capability['schema_version'] === 1 && $capability['purpose'] === PreparationContextV1::PURPOSE
            && CapabilityHistory::uuid($capability['candidate_public_id']) && $request['candidate_id'] === $capability['candidate_id']
            && $request['context'] === $capability['context'] && $capability['external_facts_verified'] === false && $capability['execution_allowed'] === false);
        foreach (['candidate_id', 'generation', 'approval_id'] as $field) {
            Check::require(Check::integer($capability[$field], 1, MinorUnits::MAX));
        }
        foreach (['candidate_hash', 'approval_hash', 'machine_hash', 'signature'] as $field) {
            Check::require(SourceCommitment::hash($capability[$field]));
        }
        Check::require(is_array($capability['machine']) && is_array($capability['context']));
        Check::validate($capability['machine']);
        Check::require($capability['machine_hash'] === CanonicalJson::hash($capability['machine']));
        PreparationContextV1::requireMatches($capability['context'], $capability['machine']);
        $source = $capability['source'];
        Check::record($source, ['draft_id', 'draft_public_id', 'revision', 'version_id', 'version_cipher_hash', 'source_review_id', 'review_cipher_hash', 'source_graph_hash']);
        Check::require(CapabilityHistory::uuid($source['draft_public_id']));
        foreach (['draft_id', 'version_id', 'source_review_id'] as $field) {
            Check::require(Check::integer($source[$field], 1, MinorUnits::MAX));
        }
        Check::require(Check::integer($source['revision'], 1, 256));
        foreach (['version_cipher_hash', 'review_cipher_hash', 'source_graph_hash'] as $field) {
            Check::require(SourceCommitment::hash($source[$field]));
        }
        Check::record($packet['catalog_graph'], ['tracks', 'offers', 'rights', 'media', 'bindings', 'revisions', 'licenses', 'templates', 'reviews', 'runs', 'outputs', 'audits']);
        Check::require(SourceCommitment::hash($packet['catalog_graph_hash']) && $packet['catalog_graph_hash'] === CanonicalJson::hash($packet['catalog_graph']));
        $selection = $packet['selection'];
        Check::record($selection, ['currency', 'minor_unit_exponent', 'advertised_subtotal_minor', 'tax_minor', 'total_minor', 'tax_state', 'assent_state', 'buyer_state', 'private_bytes_verified', 'payable', 'execution_allowed', 'external_facts_verified', 'lines']);
        Check::require($selection['currency'] === 'USD' && $selection['minor_unit_exponent'] === 2
            && $capability['context']['currency'] === 'USD' && $capability['context']['minor_unit_exponent'] === 2
            && $selection['tax_minor'] === null && $selection['total_minor'] === null && $selection['tax_state'] === 'unresolved'
            && $selection['assent_state'] === 'not_collected' && $selection['buyer_state'] === 'not_bound'
            && is_array($selection['lines']) && array_is_list($selection['lines']) && count($selection['lines']) === count($items));
        foreach (['private_bytes_verified', 'payable', 'execution_allowed', 'external_facts_verified'] as $field) {
            Check::require($selection[$field] === false);
        }
        foreach ($selection['lines'] as $position => $line) {
            Check::record($line, ['position', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id', 'price_minor', 'currency', 'offer_snapshot_hash', 'offer_snapshot']);
            $item = $items[$position];
            Check::require($line['position'] === $position + 1 && $line['track_id'] === $item['trackId'] && $line['offer_id'] === $item['offerId']
                && $line['offer_revision_id'] === $item['offerRevisionId'] && $line['license_version_id'] === $item['licenseVersionId']
                && Check::integer($line['price_minor'], 1, 2147483647) && $line['currency'] === 'USD' && is_array($line['offer_snapshot'])
                && SourceCommitment::hash($line['offer_snapshot_hash']) && $line['offer_snapshot_hash'] === CanonicalJson::hash($line['offer_snapshot']));
            Check::require(($line['offer_snapshot']['schema_version'] ?? null) === 1
                && ($line['offer_snapshot']['product']['id'] ?? null) === $line['track_id']
                && ($line['offer_snapshot']['commercial']['type'] ?? null) === 'non-exclusive'
                && ($line['offer_snapshot']['commercial']['currency'] ?? null) === 'USD'
                && ($line['offer_snapshot']['commercial']['price_minor'] ?? null) === $line['price_minor']);
            $revision = PreparationSelection::one($packet['catalog_graph']['revisions'], $line['offer_revision_id']);
            Check::require($revision['price_minor'] === $line['price_minor'] && $revision['snapshot_hash'] === $line['offer_snapshot_hash']);
        }
        Check::require(Check::integer($selection['advertised_subtotal_minor'], 1, MinorUnits::MAX)
            && $selection['advertised_subtotal_minor'] === PreparationSelection::subtotal(array_column($selection['lines'], 'price_minor')));
        Check::require(strlen(CanonicalJson::encode($packet)) <= 524288);

        return ['schema_version' => 1, 'purpose' => self::PURPOSE, 'canonicalization_version' => CanonicalJson::VERSION,
            'packet_public_id' => $packet['public_id'], 'retained_body_hash' => CanonicalJson::hash($packet),
            'request_hash' => $packet['request_hash'], 'catalog_graph_hash' => $packet['catalog_graph_hash'],
            'source' => $source, 'candidate_id' => $capability['candidate_id'], 'candidate_public_id' => $capability['candidate_public_id'],
            'candidate_hash' => $capability['candidate_hash'], 'generation' => $capability['generation'],
            'approval_id' => $capability['approval_id'], 'approval_hash' => $capability['approval_hash'], 'machine_hash' => $capability['machine_hash'],
            'context' => $capability['context'], 'lines' => $selection['lines'], 'currency' => 'USD', 'minor_unit_exponent' => 2,
            'advertised_subtotal_minor' => $selection['advertised_subtotal_minor'], 'tax_requirements' => $capability['machine']['choices']['tax_calculation'],
            'required_amount_evidence' => $capability['machine']['choices']['tax_calculation']['strategy'] === 'provider_calculated'
                ? 'authenticated_provider_calculation' : 'qualified_exemption_observation',
            'amount_evidence_state' => 'unobserved', 'amount_observation_id' => null, 'tax_minor' => null, 'total_minor' => null,
            'payable' => false, 'execution_allowed' => false, 'external_facts_verified' => false,
            'buyer_identity_verified' => false, 'buyer_act_verified' => false, 'purchase_bound' => false, 'requires_current_eligibility_proof' => true];
    }

    /** Exact schema/content equality to a separately authenticated packet, never a receipt verifier. */
    public static function requireMatches(array $requirements, array $authenticatedPacket): void
    {
        $expected = self::project($authenticatedPacket);
        Check::record($requirements, array_keys($expected));
        Check::require(CanonicalJson::encode($requirements) === CanonicalJson::encode($expected));
    }
}
