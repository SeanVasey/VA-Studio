<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Domain\Commerce\Policy\ProductionTrackPolicyDraft;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/** Explicit software choices only. Validation never establishes merchant facts or permits execution. */
final class MachinePolicyV1
{
    public const PURPOSE = 'production_track_machine_policy_candidate';

    public const CURRENCIES = ['USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CAD' => 2, 'AUD' => 2, 'JPY' => 0];

    private const FIELDS = [
        'seller_identity' => ['legal_name'],
        'provider_account' => ['provider', 'account_id', 'mode', 'api_version', 'capture_method', 'return_origin'],
        'currency' => ['code', 'minor_unit_exponent'],
        'tax_calculation' => ['strategy', 'behavior', 'maximum_rate_bps', 'rounding'],
        'assent' => ['version', 'text'],
        'license_terms' => ['authority', 'disclosure', 'publication_required', 'prior_grants'],
        'buyer_identity' => ['mode', 'order_binding'],
        'recovery' => ['mode', 'channel', 'purchase_implies_marketing'],
        'reservation_and_exclusives' => ['reservation_seconds', 'provider_lifetime_seconds', 'retry_seconds', 'pending_cutoff', 'late_time_basis', 'late_action'],
        'refunds_and_disputes' => ['refund_authority', 'partial_refunds', 'dispute_access', 'exclusive_reopening'],
        'original_documents' => ['renderer_profile', 'purpose', 'preservation'],
        'storage' => ['adapter', 'adapter_version', 'boundary_id'],
        'delivery' => ['transfer', 'authorization_seconds', 'budget_count', 'budget_window_seconds', 'maximum_bytes'],
        'privacy' => ['retention_policy_id', 'deletion_policy_id', 'marketing_consent', 'unknown_consent'],
    ];

    public static function validate(array $policy): array
    {
        self::record($policy, ['schema_version', 'purpose', 'version', 'choices', 'source_commitments']);
        self::require($policy['schema_version'] === 1 && $policy['purpose'] === self::PURPOSE && self::version($policy['version']));
        self::record($policy['choices'], ProductionTrackPolicyDraft::CATEGORIES);
        self::record($policy['source_commitments'], ProductionTrackPolicyDraft::CATEGORIES);
        foreach (self::FIELDS as $category => $fields) {
            self::record($policy['choices'][$category], $fields);
            self::require(is_string($policy['source_commitments'][$category]) && preg_match('/\A[a-f0-9]{64}\z/D', $policy['source_commitments'][$category]) === 1);
        }
        $c = $policy['choices'];
        self::require(ProductionTrackPolicyDraft::text($c['seller_identity']['legal_name'], 512));
        $p = $c['provider_account'];
        self::require($p['provider'] === 'stripe' && $p['mode'] === 'live' && is_string($p['account_id'])
            && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $p['account_id']) === 1
            && self::apiVersion($p['api_version'])
            && in_array($p['capture_method'], ['automatic', 'automatic_async', 'manual'], true) && self::origin($p['return_origin']));
        $currency = $c['currency'];
        self::require(is_string($currency['code']) && array_key_exists($currency['code'], self::CURRENCIES)
            && $currency['minor_unit_exponent'] === self::CURRENCIES[$currency['code']]);
        $tax = $c['tax_calculation'];
        self::require(in_array($tax['strategy'], ['provider_calculated', 'declared_exemption'], true)
            && in_array($tax['behavior'], ['exclusive', 'inclusive'], true) && self::integer($tax['maximum_rate_bps'], 0, 10000)
            && ($tax['strategy'] === 'provider_calculated' ? $tax['rounding'] === 'provider_exact'
                : $tax['rounding'] === 'not_applicable' && $tax['maximum_rate_bps'] === 0));
        self::require(self::version($c['assent']['version']) && self::multiline($c['assent']['text'], 4096));
        self::require($c['license_terms']['authority'] === 'immutable_published_license_version' && $c['license_terms']['disclosure'] === 'exact_purchased_revision'
            && $c['license_terms']['publication_required'] === true && $c['license_terms']['prior_grants'] === 'retain');
        self::require(in_array($c['buyer_identity']['mode'], ['verified_account', 'verified_guest_claim'], true)
            && $c['buyer_identity']['order_binding'] === 'explicit_verified_purchase_binding');
        self::require($c['recovery']['mode'] === ($c['buyer_identity']['mode'] === 'verified_account' ? 'verified_account_recovery' : 'verified_guest_claim_recovery')
            && $c['recovery']['channel'] === 'transactional_mail' && $c['recovery']['purchase_implies_marketing'] === false);
        $r = $c['reservation_and_exclusives'];
        self::require(self::integer($r['reservation_seconds'], 1, 86400) && self::integer($r['provider_lifetime_seconds'], 1, 86400)
            && self::integer($r['retry_seconds'], 1, 86400) && $r['retry_seconds'] <= $r['reservation_seconds']
            && $r['retry_seconds'] <= $r['provider_lifetime_seconds']
            && in_array($r['pending_cutoff'], ['block_new_nonexclusive_on_pending_exclusive', 'block_new_nonexclusive_on_paid_exclusive'], true)
            && in_array($r['late_time_basis'], ['authoritative_provider_payment_time', 'application_verified_observation_time'], true)
            && $r['late_action'] === 'paid_exception_operator_reconciliation');
        $refund = $c['refunds_and_disputes'];
        self::require($refund['refund_authority'] === 'authoritative_provider_operator_review' && $refund['partial_refunds'] === 'exact_line_allocation'
            && in_array($refund['dispute_access'], ['operator_review', 'temporarily_block_while_reconciling'], true)
            && in_array($refund['exclusive_reopening'], ['remain_sold', 'qualified_operator_review'], true));
        self::require(self::version($c['original_documents']['renderer_profile']) && $c['original_documents']['purpose'] === 'production_original_contract'
            && $c['original_documents']['preservation'] === 'retain_original_never_substitute_regeneration');
        $storage = $c['storage'];
        self::require(in_array($storage['adapter'], ['private_posix', 'private_object_store'], true)
            && self::version($storage['adapter_version']) && self::version($storage['boundary_id']));
        $delivery = $c['delivery'];
        self::require($delivery['transfer'] === ($storage['adapter'] === 'private_posix' ? 'private_posix_single_attempt' : 'private_object_single_attempt')
            && self::integer($delivery['authorization_seconds'], 1, 3600) && self::integer($delivery['budget_count'], 1, 1000)
            && self::integer($delivery['budget_window_seconds'], 1, 86400) && self::integer($delivery['maximum_bytes'], 1, 1073741824));
        self::require(self::version($c['privacy']['retention_policy_id']) && self::version($c['privacy']['deletion_policy_id'])
            && $c['privacy']['marketing_consent'] === 'explicit_purpose_only' && $c['privacy']['unknown_consent'] === 'remain_unknown');
        $canonical = CanonicalJson::encode($policy);
        self::require(strlen($canonical) <= 32768 && preg_match('/(?:sk|rk)_(?:test|live)_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', $canonical) !== 1);

        return $policy;
    }

    public static function forSource(array $policy, array $authored): array
    {
        $policy = self::validate($policy);
        ProductionTrackPolicyDraft::validateAuthored($authored);
        foreach ($authored['declarations'] as $category => $declaration) {
            self::require($declaration['state'] === 'declared' && $policy['source_commitments'][$category] === $declaration['source_sha256']);
        }

        return $policy;
    }

    public static function record(mixed $value, array $keys): void
    {
        self::require(is_array($value) && ProductionTrackPolicyDraft::keys($value, $keys));
    }

    public static function version(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,79}\z/D', $value) === 1;
    }

    public static function integer(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    private static function multiline(mixed $value, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= $maximum && trim($value) !== '' && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value) === 0;
    }

    private static function apiVersion(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\.[a-z][a-z0-9_-]{0,31}\z/D', $value) !== 1) {
            return false;
        }
        $date = substr($value, 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private static function origin(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || str_ends_with($value, '/')) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && isset($parts['host'])
            && preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/D', $parts['host']) === 1
            && ! in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true)
            && array_diff(array_keys($parts), ['scheme', 'host', 'port']) === [];
    }

    public static function require(bool $condition): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['capability' => 'Review the current source and supply every supported production machine-policy choice.']);
        }
    }
}
