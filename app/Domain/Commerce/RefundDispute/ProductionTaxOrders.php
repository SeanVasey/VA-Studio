<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Grants\PaidTax\Models\PaidTaxOrderOrigin;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The production tax chain's side of the refund/dispute ledger: finds a finalized paid order (a `paid_tax_order_origins` batch),
 * proves its retained evidence and returns the facts the shared lifecycle needs. Read-only: it writes nothing and issues no grant.
 *
 * Authority is the application-sealed retained rows. The batch and every line payload are APP_KEY-encrypted and hash-checked,
 * bound to each other, to the batch columns and to the paid line's own self-consistency; line amounts are the provider-calculated
 * totals with tax included, and they must sum exactly to the order total. The refund policy is taken from the machine policy
 * retained in the checkout order that produced the batch (the terms the buyer accepted), after its hash is matched to the
 * candidate binding every line carries.
 *
 * Only finalized orders are visible here: an order paid but not yet finalized has no batch and is `not_found`.
 */
final class ProductionTaxOrders
{
    public function find(string $publicId): ?PaidTaxOrderOrigin
    {
        return OrderRequest::uuid($publicId) ? PaidTaxOrderOrigin::where('public_id', $publicId)->first() : null;
    }

    /**
     * Proves the locked batch and its lines. The caller holds the batch row; line rows are read under lock here.
     *
     * @return array{source: string, batch_id: int, order_public_id: string, provider_account: string, provider_session_id: string, provider_payment_id: string, paid_minor: int, currency: string, machine: array, lines: array<int, array{id: int, public_id: string, total_minor: int, exclusive: bool}>}
     */
    public function verify(PaidTaxOrderOrigin $batch): array
    {
        try {
            return $this->prove($batch->getAttributes());
        } catch (RefundDisputeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RefundDisputeException('unavailable');
        }
    }

    public function policy(array $original): array
    {
        return app(RefundDisputePolicy::class)->productionTax($original['machine']);
    }

    /** What an authenticated provider read must match: the retained account, PaymentIntent and gross total. */
    public function expectation(array $original): array
    {
        return ['account_id' => $original['provider_account'], 'amount_minor' => $original['paid_minor'],
            'payment' => ['id' => $original['provider_payment_id'], 'object' => 'payment_intent', 'livemode' => false, 'currency' => 'usd',
                'amount' => $original['paid_minor'], 'status' => 'succeeded']];
    }

    private function prove(array $row): array
    {
        $account = app(RefundDisputePolicy::class)->productionAccount();
        $payload = PaidTaxRecords::decode($row);
        $identity = (new PaidTaxGrants)->batchIdentity($row);
        RefundDisputeException::require(($payload['schema_version'] ?? null) === 'paid-tax-order-origin-v2' && ($payload['purpose'] ?? null) === 'paid-tax-license-grant'
            && $payload['origin_id'] === $row['public_id'] && $payload['producer'] === TaxCheckoutPolicy::PRODUCER && $row['producer'] === TaxCheckoutPolicy::PRODUCER
            && $row['funds_mode'] === 'test' && hash_equals($account, $row['provider_account']) && $payload['order_id'] === $row['order_public_id']
            && $payload['order_hash'] === $row['order_hash'] && $payload['source_identity'] === $identity
            && preg_match('/\Api_[A-Za-z0-9]{1,120}\z/D', $row['provider_payment_id']) === 1, 'unavailable');
        $origins = DB::table('paid_tax_grant_origins')->where('batch_id', $row['id'])->orderBy('position')->lockForUpdate()->get()->map(fn ($r): array => (array) $r)->all();
        $count = (int) $row['line_count'];
        RefundDisputeException::require($count >= 1 && $count <= 10 && count($origins) === $count && count($payload['line_commitments']) === $count, 'unavailable');
        $lines = [];
        $sum = 0;
        $orderTotal = null;
        $machineHash = null;
        foreach ($origins as $index => $origin) {
            $body = PaidTaxRecords::decode($origin);
            $source = $body['source'];
            RefundDisputeException::require($body['schema_version'] === 'paid-tax-grant-origin-v2' && $body['purpose'] === 'paid-tax-license-grant'
                && $body['origin_id'] === $origin['public_id'] && $body['batch_id'] === $row['public_id'] && $body['position'] === $index + 1
                && (int) $origin['position'] === $index + 1 && $source['order_id'] === $payload['order_id'] && $source['order_hash'] === $payload['order_hash']
                && $source['line_id'] === $origin['line_public_id'] && $source['line_hash'] === $origin['line_hash'] && $source['source_hash'] === $origin['source_hash']
                && $payload['line_commitments'][$index] === array_intersect_key($source, array_flip(['line_id', 'line_hash', 'source_hash']))
                && $source['provider_account'] === $row['provider_account'] && $source['provider_session_id'] === $row['provider_session_id']
                && $source['provider_payment_id'] === $row['provider_payment_id'] && $source['funds_mode'] === 'test', 'unavailable');
            // Fails closed for anything but a synthetic-rehearsal, non-exclusive, internally consistent taxed line. The production
            // chain sells no exclusive license (ProductionTaxPaidLineAdapterV2 and ExclusiveInventoryGate), so a line is never exclusive.
            ProductionTaxPaidLineAdapterV2::assertSelfConsistent($source, 'synthetic_rehearsal');
            $tax = $source['tax'];
            RefundDisputeException::require($tax['currency'] === 'USD' && is_int($tax['line_total_minor']) && $tax['line_total_minor'] >= 1
                && ($orderTotal ?? $tax['order_total_minor']) === $tax['order_total_minor'], 'unavailable');
            $orderTotal = $tax['order_total_minor'];
            $machineHash ??= $source['candidate']['machine_hash'] ?? null;
            RefundDisputeException::require(($source['candidate']['machine_hash'] ?? null) === $machineHash, 'unavailable');
            $sum += $tax['line_total_minor'];
            $lines[$index + 1] = ['id' => (int) $origin['id'], 'public_id' => $origin['public_id'], 'total_minor' => $tax['line_total_minor'], 'exclusive' => false];
        }
        RefundDisputeException::require($orderTotal !== null && $sum === $orderTotal && $orderTotal <= 99999999, 'unavailable');

        return ['source' => RefundDisputeSource::PRODUCTION_TAX, 'batch_id' => (int) $row['id'], 'order_public_id' => $row['order_public_id'],
            'provider_account' => $row['provider_account'], 'provider_session_id' => $row['provider_session_id'],
            'provider_payment_id' => $row['provider_payment_id'], 'paid_minor' => $orderTotal, 'currency' => 'USD',
            'machine' => $this->machine($row, $machineHash), 'lines' => $lines];
    }

    /** The approved machine policy retained in the checkout order the batch came from, matched to the lines' candidate binding. */
    private function machine(array $row, mixed $expectedHash): array
    {
        $order = DB::table('production_tax_checkout_orders')->where('public_id', $row['order_public_id'])->first();
        RefundDisputeException::require($order !== null && hash_equals($row['order_hash'], $order->payload_hash), 'unavailable');
        $body = Evidence::open((array) $order, 'production_tax_checkout_order');
        $machine = $body['machine'] ?? null;
        RefundDisputeException::require(is_array($machine) && is_string($expectedHash) && hash_equals($expectedHash, CanonicalJson::hash($machine))
            && ($body['candidate']['machine_hash'] ?? null) === $expectedHash, 'unavailable');

        return $machine;
    }
}
