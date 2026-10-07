<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1 as Check;
use App\Domain\Commerce\ProductionPolicy\SourceCommitment;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;

/** Pure consistency only. Requirements must already be authenticated; array calls confer no authority. */
final class AmountInputConsistencyV1
{
    public const INPUT_PURPOSE = 'untrusted_production_amount_comparison_input';

    public const REPORT_PURPOSE = 'untrusted_production_amount_consistency_report';

    public static function compare(array $requirements, array $supplied): array
    {
        self::requirements($requirements);
        self::input($supplied, $requirements);
        $reason = self::mismatch($requirements, $supplied);

        return ['schema_version' => 1, 'purpose' => self::REPORT_PURPOSE, 'canonicalization_version' => CanonicalJson::VERSION,
            'packet_public_id' => $requirements['packet_public_id'], 'retained_body_hash' => $requirements['retained_body_hash'],
            'requirements_hash' => CanonicalJson::hash($requirements), 'supplied_input_hash' => CanonicalJson::hash($supplied),
            'consistency_state' => $reason === null ? 'internally_consistent' : 'inconsistent', 'reason' => $reason,
            'amount_minor' => null, 'tax_minor' => null, 'total_minor' => null, 'amount_observation_id' => null, 'amount_evidence_state' => 'unobserved',
            'payable' => false, 'execution_allowed' => false, 'external_facts_verified' => false, 'provider_authenticated' => false,
            'payment_verified' => false, 'buyer_identity_verified' => false, 'buyer_act_verified' => false, 'purchase_bound' => false,
            'rights_granted' => false, 'consent_verified' => false, 'requires_current_eligibility_proof' => true];
    }

    /** D-28's trusted projection shape, not an authentication or receipt factory. */
    private static function requirements(array $r): void
    {
        Check::record($r, ['schema_version', 'purpose', 'canonicalization_version', 'packet_public_id', 'retained_body_hash', 'request_hash',
            'catalog_graph_hash', 'source', 'candidate_id', 'candidate_public_id', 'candidate_hash', 'generation', 'approval_id', 'approval_hash',
            'machine_hash', 'context', 'lines', 'currency', 'minor_unit_exponent', 'advertised_subtotal_minor', 'tax_requirements', 'required_amount_evidence',
            'amount_evidence_state', 'amount_observation_id', 'tax_minor', 'total_minor', 'payable', 'execution_allowed', 'external_facts_verified',
            'buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'requires_current_eligibility_proof']);
        Check::require($r['schema_version'] === 1 && $r['purpose'] === AmountRequirementsV1::PURPOSE && $r['canonicalization_version'] === CanonicalJson::VERSION
            && CapabilityHistory::uuid($r['packet_public_id']) && SourceCommitment::hash($r['retained_body_hash'])
            && $r['currency'] === 'USD' && $r['minor_unit_exponent'] === 2 && is_array($r['context'])
            && $r['amount_evidence_state'] === 'unobserved' && $r['amount_observation_id'] === null && $r['tax_minor'] === null && $r['total_minor'] === null
            && $r['requires_current_eligibility_proof'] === true);
        foreach (['payable', 'execution_allowed', 'external_facts_verified', 'buyer_identity_verified', 'buyer_act_verified', 'purchase_bound'] as $field) {
            Check::require($r[$field] === false);
        }
        self::tax($r['tax_requirements']);
        Check::require(is_array($r['lines']) && array_is_list($r['lines']) && count($r['lines']) >= 1 && count($r['lines']) <= 10
            && Check::integer($r['advertised_subtotal_minor'], 1, MinorUnits::MAX));
        foreach ($r['lines'] as $position => $line) {
            Check::record($line, ['position', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id', 'price_minor', 'currency', 'offer_snapshot_hash', 'offer_snapshot']);
            Check::require($line['position'] === $position + 1 && Check::integer($line['price_minor'], 1, 2147483647)
                && $line['currency'] === 'USD' && SourceCommitment::hash($line['offer_snapshot_hash']));
        }
        Check::require($r['advertised_subtotal_minor'] === MinorUnits::sum(array_column($r['lines'], 'price_minor')));
    }

    /** Reject unknown/private/provider fields and all nonprimitive/coerced operands before hashing. */
    private static function input(array $s, array $r): void
    {
        Check::record($s, ['schema_version', 'purpose', 'packet_public_id', 'retained_body_hash', 'requirements_hash', 'context', 'currency', 'minor_unit_exponent',
            'tax_requirements', 'advertised_subtotal_minor', 'supplied_net_subtotal_minor', 'supplied_tax_minor', 'supplied_gross_total_minor', 'lines']);
        Check::require($s['schema_version'] === 1 && $s['purpose'] === self::INPUT_PURPOSE && CapabilityHistory::uuid($s['packet_public_id'])
            && SourceCommitment::hash($s['retained_body_hash']) && SourceCommitment::hash($s['requirements_hash'])
            && is_string($s['currency']) && array_key_exists($s['currency'], Check::CURRENCIES)
            && $s['minor_unit_exponent'] === Check::CURRENCIES[$s['currency']]);
        Check::record($s['context'], array_keys($r['context']));
        // Context is copied only from retained server choices: no free string can
        // smuggle a reported identity/location/reference into a supplied hash.
        foreach ($r['context'] as $field => $value) {
            Check::require($s['context'][$field] === $value);
        }
        self::tax($s['tax_requirements']);
        foreach (['advertised_subtotal_minor', 'supplied_net_subtotal_minor', 'supplied_tax_minor', 'supplied_gross_total_minor'] as $field) {
            Check::require(Check::integer($s[$field], 0, MinorUnits::MAX));
        }
        Check::require(is_array($s['lines']) && array_is_list($s['lines']) && count($s['lines']) >= 1 && count($s['lines']) <= 10);
        $tracks = [];
        $revisions = [];
        foreach ($s['lines'] as $position => $line) {
            Check::record($line, ['position', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id', 'offer_snapshot_hash', 'quantity',
                'advertised_price_minor', 'supplied_net_minor', 'supplied_tax_minor', 'supplied_gross_minor']);
            Check::require($line['position'] === $position + 1 && $line['quantity'] === 1 && SourceCommitment::hash($line['offer_snapshot_hash']));
            foreach (['track_id', 'offer_id', 'offer_revision_id', 'license_version_id'] as $field) {
                Check::require(Check::integer($line[$field], 1, MinorUnits::MAX));
            }
            Check::require(! isset($tracks[$line['track_id']]) && ! isset($revisions[$line['offer_revision_id']]));
            $tracks[$line['track_id']] = true;
            $revisions[$line['offer_revision_id']] = true;
            foreach (['advertised_price_minor', 'supplied_net_minor', 'supplied_tax_minor', 'supplied_gross_minor'] as $field) {
                Check::require(Check::integer($line[$field], 0, MinorUnits::MAX));
            }
        }
        Check::require(strlen(CanonicalJson::encode($s)) <= 16384);
    }

    private static function tax(mixed $tax): void
    {
        Check::record($tax, ['strategy', 'behavior', 'maximum_rate_bps', 'rounding']);
        Check::require(in_array($tax['strategy'], ['provider_calculated', 'declared_exemption'], true)
            && in_array($tax['behavior'], ['inclusive', 'exclusive'], true) && Check::integer($tax['maximum_rate_bps'], 0, 10000)
            && ($tax['strategy'] === 'provider_calculated' ? $tax['rounding'] === 'provider_exact'
                : $tax['rounding'] === 'not_applicable' && $tax['maximum_rate_bps'] === 0));
    }

    private static function mismatch(array $r, array $s): ?string
    {
        if ($s['packet_public_id'] !== $r['packet_public_id'] || $s['retained_body_hash'] !== $r['retained_body_hash']
            || $s['requirements_hash'] !== CanonicalJson::hash($r)) {
            return 'commitment_mismatch';
        }
        if ($s['currency'] !== $r['currency'] || $s['minor_unit_exponent'] !== $r['minor_unit_exponent']
            || CanonicalJson::encode($s['tax_requirements']) !== CanonicalJson::encode($r['tax_requirements'])) {
            return 'tax_context_mismatch';
        }
        if (count($s['lines']) !== count($r['lines'])) {
            return 'line_identity_mismatch';
        }
        $net = $tax = $gross = 0;
        foreach ($r['lines'] as $position => $expected) {
            $line = $s['lines'][$position];
            foreach (['position', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id', 'offer_snapshot_hash'] as $field) {
                if ($line[$field] !== $expected[$field]) {
                    return 'line_identity_mismatch';
                }
            }
            if ($line['advertised_price_minor'] !== $expected['price_minor']) {
                return 'advertised_price_mismatch';
            }
            $n = $line['supplied_net_minor'];
            $t = $line['supplied_tax_minor'];
            $g = $line['supplied_gross_minor'];
            if ($t > MinorUnits::MAX - $n || $n + $t !== $g
                || ($r['tax_requirements']['behavior'] === 'exclusive' ? $n : $g) !== $expected['price_minor']) {
                return 'line_arithmetic_mismatch';
            }
            // An upper bound only: no rate/rounding/exemption/provider fact is inferred.
            if ($t > MinorUnits::fraction($n, $r['tax_requirements']['maximum_rate_bps'])['ceiling_minor']) {
                return 'tax_constraint_mismatch';
            }
            if ($n > MinorUnits::MAX - $net || $t > MinorUnits::MAX - $tax || $g > MinorUnits::MAX - $gross) {
                return 'aggregate_arithmetic_mismatch';
            }
            $net += $n;
            $tax += $t;
            $gross += $g;
        }
        if ($s['advertised_subtotal_minor'] !== $r['advertised_subtotal_minor'] || $s['supplied_net_subtotal_minor'] !== $net
            || $s['supplied_tax_minor'] !== $tax || $s['supplied_gross_total_minor'] !== $gross) {
            return 'aggregate_arithmetic_mismatch';
        }

        return null;
    }
}
