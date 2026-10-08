<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\QuotePricing;
use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use App\Support\Money\MinorUnits;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

/** Amount comparison only. WP-07 must independently retrieve/verify payment and tax evidence,
 * retain this report, and enforce order, assent, lifetime and inventory before any grant.
 * No HTTP route invokes this service; a match is never proof of payment or fulfillment.
 */
final class ComparePricingSettlement
{
    public function handle(QuotePricing $pricing, array $observation, ?array $taxResult = null): array
    {
        $reason = null;
        try {
            $snapshot = app(PricingSnapshot::class)->verify($pricing, $pricing->quote()->firstOrFail());
        } catch (QueryException $exception) {
            throw $exception;
        } catch (Throwable) {
            $snapshot = null;
            $reason = 'invalid_pricing_evidence';
        }
        if ($snapshot !== null) {
            try {
                $this->compare($snapshot, $observation, $taxResult);
            } catch (InvalidArgumentException $exception) {
                $reason = $exception->getMessage();
            } catch (Throwable) {
                $reason = 'invalid_observation';
            }
        }

        return [
            'comparison_schema' => 1, 'pricing_id' => $pricing->public_id,
            'pricing_snapshot_hash' => $pricing->snapshot_hash,
            'amounts_match' => $reason === null, 'reason' => $reason,
            'observation_hash' => $this->fingerprint($observation), 'tax_result_hash' => $this->fingerprint($taxResult),
        ];
    }

    private function compare(array $snapshot, array $observed, ?array $taxResult): void
    {
        $policy = $snapshot['tax_policy'];
        $this->assertMatch($policy !== null, 'unresolved_tax');
        $this->assertMatch(TestEnvironment::admitsTestCommerce() && $policy['scope'] === 'test', 'test_scope_required');
        PricingPolicy::keys($observed, ['schema_version', 'pricing_id', 'quote_id', 'policy_hash', 'provider', 'account', 'livemode', 'currency',
            'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'tax_calculation_id', 'lines']);
        $this->identity($snapshot, $observed);
        foreach (['subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor'] as $field) {
            MinorUnits::amount($observed[$field]);
        }
        $lines = $this->lines($observed['lines']);
        $this->assertMatch(count($lines) === count($snapshot['lines']), 'line_mismatch');
        $providerTax = $policy['tax']['mode'] === 'provider_calculated';
        $taxLines = [];
        if ($providerTax) {
            $this->assertMatch($taxResult !== null, 'tax_evidence_required');
            PricingPolicy::keys($taxResult, ['schema_version', 'pricing_id', 'quote_id', 'policy_hash', 'provider', 'account', 'livemode', 'currency',
                'id', 'status', 'behavior', 'lines']);
            $this->identity($snapshot, $taxResult);
            $this->assertMatch(is_string($taxResult['id']) && (bool) preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/D', $taxResult['id']) &&
                $observed['tax_calculation_id'] === $taxResult['id'] && $taxResult['status'] === 'complete' && $taxResult['behavior'] === 'exclusive', 'tax_evidence_mismatch');
            $taxLines = $this->lines($taxResult['lines']);
            $this->assertMatch(count($taxLines) === count($lines), 'tax_evidence_mismatch');
        } else {
            $this->assertMatch($observed['tax_calculation_id'] === null && $taxResult === null, 'unexpected_tax_evidence');
        }
        foreach ($snapshot['lines'] as $expected) {
            $line = $lines[$expected['offer_revision_id']] ?? null;
            $this->assertMatch($line !== null, 'line_mismatch');
            foreach (['quantity', 'base_minor', 'discount_minor', 'tax_basis_minor'] as $field) {
                $this->assertMatch($line[$field] === $expected[$field], 'line_mismatch');
            }
            $this->assertMatch($line['total_minor'] === MinorUnits::sum([$line['tax_basis_minor'], $line['tax_minor']]), 'totals_mismatch');
            if ($providerTax) {
                $taxLine = $taxLines[$expected['offer_revision_id']] ?? null;
                $this->assertMatch($taxLine !== null && CanonicalJson::hash($taxLine) === CanonicalJson::hash($line), 'tax_evidence_mismatch');
                $cap = MinorUnits::fraction($expected['tax_basis_minor'], $policy['tax']['max_rate_bps'])['ceiling_minor'];
                $this->assertMatch($line['tax_minor'] <= $cap, 'tax_limit_exceeded');
            } else {
                $this->assertMatch($line['tax_minor'] === $expected['tax_minor'], 'tax_mismatch');
            }
        }
        $this->assertMatch($observed['subtotal_minor'] === $snapshot['subtotal_minor'] && $observed['discount_minor'] === $snapshot['discount_minor'] &&
            $observed['tax_minor'] === MinorUnits::sum(array_column($lines, 'tax_minor')) &&
            $observed['total_minor'] === MinorUnits::sum(array_column($lines, 'total_minor')), 'totals_mismatch');
    }

    private function identity(array $snapshot, array $value): void
    {
        $this->assertMatch($value['schema_version'] === 1 && $value['pricing_id'] === $snapshot['pricing_id'] && $value['quote_id'] === $snapshot['quote_id'] &&
            $value['policy_hash'] === $snapshot['policy_hash'] && $value['provider'] === $snapshot['tax_policy']['provider'] &&
            $value['account'] === $snapshot['tax_policy']['account'] && $value['livemode'] === false && $value['currency'] === $snapshot['currency'], 'identity_mismatch');
    }

    private function lines(mixed $lines): array
    {
        $this->assertMatch(is_array($lines) && array_is_list($lines) && count($lines) >= 1 && count($lines) <= 10, 'line_mismatch');
        $indexed = [];
        foreach ($lines as $line) {
            PricingPolicy::keys($line, ['offer_revision_id', 'quantity', 'base_minor', 'discount_minor', 'tax_basis_minor', 'tax_minor', 'total_minor']);
            $id = MinorUnits::amount($line['offer_revision_id']);
            $this->assertMatch($id > 0 && ! isset($indexed[$id]) && $line['quantity'] === 1, 'line_mismatch');
            foreach (['base_minor', 'discount_minor', 'tax_basis_minor', 'tax_minor', 'total_minor'] as $field) {
                MinorUnits::amount($line[$field]);
            }
            $indexed[$id] = $line;
        }

        return $indexed;
    }

    private function assertMatch(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($reason);
        }
    }

    private function fingerprint(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return CanonicalJson::hash($value);
        } catch (Throwable) {
            return null;
        }
    }
}
