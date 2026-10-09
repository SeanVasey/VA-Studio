<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** Explicit owner-approved qualification scope. Validation is not independent tax/legal verification. */
final class ExemptionPolicyV1
{
    public static function validate(array $policy, array $current): array
    {
        Evidence::keys($policy, ['schema_version', 'purpose', 'provenance', 'tax_source_reference', 'tax_source_sha256',
            'qualification_reference', 'qualification_source_sha256', 'issuer_legal_name', 'qualifier_ids', 'effective_from', 'effective_until', 'owner_approved']);
        $c = $current['machine']['choices'];
        CheckoutException::require($policy['schema_version'] === 1 && $policy['purpose'] === 'production_checkout_exemption_qualification_policy'
            && $policy['owner_approved'] === true && $policy['provenance'] === $current['context']->provenance
            && $policy['tax_source_sha256'] === $current['machine']['source_commitments']['tax_calculation']
            && Evidence::hash($policy['qualification_source_sha256']) && $c['tax_calculation']['strategy'] === 'declared_exemption');
        foreach (['tax_source_reference', 'qualification_reference', 'issuer_legal_name'] as $field) {
            CheckoutException::require(is_string($policy[$field]) && trim($policy[$field]) !== '' && strlen($policy[$field]) <= 512
                && mb_check_encoding($policy[$field], 'UTF-8') && ! preg_match('/[\x00-\x1f\x7f]/u', $policy[$field]));
            CheckoutException::require($policy['provenance'] !== 'verified_production' || ! str_starts_with(strtolower($policy[$field]), 'synthetic:'));
        }
        CheckoutException::require(is_array($policy['qualifier_ids']) && array_is_list($policy['qualifier_ids'])
            && count($policy['qualifier_ids']) >= 1 && count($policy['qualifier_ids']) <= 10
            && count(array_unique($policy['qualifier_ids'])) === count($policy['qualifier_ids']));
        foreach ($policy['qualifier_ids'] as $id) {
            CheckoutException::require(is_int($id) && $id > 0 && $id <= 2147483647);
        }
        $sorted = $policy['qualifier_ids'];
        sort($sorted, SORT_NUMERIC);
        Evidence::same($sorted, $policy['qualifier_ids']);
        self::interval($policy['effective_from'], $policy['effective_until']);
        CheckoutException::require(! preg_match('/(?:sk|rk)_(?:test|live)_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', CanonicalJson::encode($policy)));

        return $policy;
    }

    public static function interval(mixed $from, mixed $until): void
    {
        foreach ([$from, $until] as $value) {
            CheckoutException::require(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) === 1);
            CheckoutException::require(CarbonImmutable::parse($value)->format('Y-m-d\TH:i:s\Z') === $value);
        }
        CheckoutException::require($from < $until);
    }

    public static function effective(array $policy, CarbonImmutable $at): void
    {
        $value = $at->format('Y-m-d\TH:i:s\Z');
        CheckoutException::require($policy['effective_from'] <= $value && $value < $policy['effective_until'], 'expired');
    }
}
