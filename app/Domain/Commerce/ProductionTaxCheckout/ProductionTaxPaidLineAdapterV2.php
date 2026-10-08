<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Support\CanonicalJson;

/**
 * The separate V2 paid consumer adapter. A paid consumer calls this for SourceV2 lines only; V1 lines keep their
 * existing, unchanged reader. It refuses V1-shaped input outright instead of interpreting it, so a V1 line can
 * never be read as a taxed V2 line and a V2 line can never reach a V1 reader by relabelling.
 */
final class ProductionTaxPaidLineAdapterV2
{
    public const KEYS = ['schema_version', 'producer', 'origin_key', 'order_id', 'order_hash', 'line_id', 'line_hash', 'request_id', 'request_hash',
        'reviewed_session_id', 'reviewed_session_hash', 'provider_session_id', 'provider_payment_id', 'provider_account', 'funds_mode', 'provenance',
        'payment_evidence_origin', 'observed_at', 'buyer', 'buyer_binding_hash', 'buyer_declarations', 'product', 'product_hash', 'assent',
        'assent_hash', 'license_version_id', 'license', 'license_hash', 'asset_revisions', 'asset_revisions_hash', 'candidate', 'execution_context',
        'inventory', 'inventory_hash', 'pre_tax', 'tax', 'source_hash'];

    private const TAX_KEYS = ['authority', 'calculator', 'automatic_tax', 'tax_behavior', 'currency', 'line_subtotal_minor', 'line_tax_minor',
        'line_total_minor', 'order_subtotal_minor', 'order_tax_minor', 'order_total_minor'];

    /** True only for the V2 shape; a consumer routes on this before choosing V1 or V2 handling. */
    public static function isV2(array $line): bool
    {
        return ($line['schema_version'] ?? null) === 2 && ($line['producer'] ?? null) === TaxCheckoutPolicy::PRODUCER;
    }

    /** Returns the authenticated V2 line unchanged, or refuses with 409. Never coerces or fills a field. */
    public static function accept(array $line, string $provenance): array
    {
        CheckoutException::require(self::isV2($line) && ! array_key_exists('line_tax_minor', $line) && ! array_key_exists('line_amount_minor', $line)
            && ! array_key_exists('amounts', $line) && ! array_key_exists('payment_id', $line), 'source_version');
        CheckoutException::require(count($line) === count(self::KEYS) && array_diff(self::KEYS, array_keys($line)) === []);
        $source = $line;
        unset($source['source_hash']);
        CheckoutException::require(is_string($line['source_hash']) && hash_equals(CanonicalJson::hash($source), $line['source_hash']), 'source_hash');
        $rehearsal = $provenance === 'synthetic_rehearsal';
        CheckoutException::require(in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true)
            && $line['provenance'] === $provenance && ($line['buyer']['provenance'] ?? null) === $provenance
            && $line['funds_mode'] === ($rehearsal ? 'test' : 'live')
            && $line['payment_evidence_origin'] === ($rehearsal ? 'synthetic_rehearsal' : 'own_account_sdk')
            && ($line['assent']['accepted'] ?? null) === true && ($line['license']['type'] ?? null) === 'non-exclusive'
            && is_string($line['order_id']) && is_string($line['line_id'])
            && $line['origin_key'] === TaxCheckoutPolicy::PRODUCER.':'.$line['order_id'].':'.$line['line_id']);
        foreach (['buyer' => 'buyer_binding_hash', 'product' => 'product_hash', 'assent' => 'assent_hash', 'license' => 'license_hash',
            'asset_revisions' => 'asset_revisions_hash', 'inventory' => 'inventory_hash'] as $value => $hash) {
            CheckoutException::require(is_string($line[$hash]) && hash_equals(CanonicalJson::hash($line[$value]), $line[$hash]));
        }
        $preTax = $line['pre_tax'];
        $tax = $line['tax'];
        CheckoutException::require(is_array($preTax) && count($preTax) === 3 && ($preTax['currency'] ?? null) === 'USD'
            && is_array($tax) && count($tax) === count(self::TAX_KEYS) && array_diff(self::TAX_KEYS, array_keys($tax)) === []
            && $tax['authority'] === 'provider_calculated_buyer_reviewed' && $tax['calculator'] === 'stripe_checkout_automatic_tax'
            && $tax['currency'] === 'USD' && in_array($tax['tax_behavior'], ['exclusive', 'inclusive'], true)
            && ($tax['automatic_tax']['enabled'] ?? null) === true && ($tax['automatic_tax']['status'] ?? null) === 'complete');
        // The line's own retained execution context must agree with its funds, account, provenance and tax behavior.
        $context = $line['execution_context'];
        CheckoutException::require(is_array($context) && ($context['funds_mode'] ?? null) === $line['funds_mode']
            && ($context['provenance'] ?? null) === $line['provenance'] && ($context['account_id'] ?? null) === $line['provider_account']
            && ($context['tax']['strategy'] ?? null) === 'provider_calculated' && ($context['tax']['behavior'] ?? null) === $tax['tax_behavior']
            && is_int($context['tax']['maximum_rate_bps'] ?? null) && $context['tax']['maximum_rate_bps'] >= 0 && $context['tax']['maximum_rate_bps'] <= 10000);
        foreach (['line', 'order'] as $scope) {
            $subtotal = $tax[$scope.'_subtotal_minor'];
            $taxed = $tax[$scope.'_tax_minor'];
            $total = $tax[$scope.'_total_minor'];
            CheckoutException::require(is_int($subtotal) && is_int($taxed) && is_int($total) && $subtotal >= 1 && $taxed >= 0
                && $total === ($tax['tax_behavior'] === 'exclusive' ? $subtotal + $taxed : $subtotal)
                && ($tax['tax_behavior'] === 'exclusive' || $taxed <= $subtotal));
        }
        CheckoutException::require($tax['line_subtotal_minor'] === ($preTax['line_amount_minor'] ?? null)
            && $tax['order_subtotal_minor'] === ($preTax['order_subtotal_minor'] ?? null)
            && $tax['line_subtotal_minor'] <= $tax['order_subtotal_minor'] && $tax['line_tax_minor'] <= $tax['order_tax_minor']);
        // Same approved machine-policy ceiling the producer enforced; checked, never adjusted.
        $net = $tax['tax_behavior'] === 'exclusive' ? $tax['order_subtotal_minor'] : $tax['order_subtotal_minor'] - $tax['order_tax_minor'];
        CheckoutException::require($net >= 0 && $net * $context['tax']['maximum_rate_bps'] >= $tax['order_tax_minor'] * 10000, 'tax_ceiling');

        return $line;
    }
}
