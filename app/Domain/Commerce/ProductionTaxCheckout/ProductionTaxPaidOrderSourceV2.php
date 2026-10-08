<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\HeldSourceTransaction;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Support\CanonicalJson;
use LogicException;

/**
 * SourceV2: server-minted paid graph for a provider-calculated tax order. Its line format is a NEW purpose
 * (`schema_version` 2, producer `production_tax_checkout_v2`); tax facts live in a separate `tax` block retained
 * from the buyer-reviewed provider session. It never reuses, wraps or changes the frozen V1 source or its bytes,
 * and it issues no grant itself.
 */
final readonly class ProductionTaxPaidOrderSourceV2 implements \JsonSerializable
{
    private function __construct(
        private ProductionTaxPaidOrderLocatorV2 $locator,
        private array $order,
        private array $intent,
        private array $historicalIdentity,
        private HeldSourceTransaction $transaction,
    ) {}

    /** The consumer must prelock T23 historical identity before calling this under its captured transaction. */
    public static function lockedRead(ProductionTaxPaidOrderLocatorV2 $locator, CurrentRows $reader, array $prelockedIdentityRaw): self
    {
        $transaction = HeldSourceTransaction::capture($reader);
        $identity = (new ProductionCustomerAccess)->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
        Evidence::same($prelockedIdentityRaw, $identity);
        $tax = TaxCheckoutRecords::reader($reader);
        $order = TaxCheckoutEvidence::order($tax, $tax->one('order', $locator->orderId));
        CheckoutException::require($order['row']['payload_hash'] === $locator->orderHash);
        Evidence::same($locator->historicalBuyerBinding(), $order['body']['buyer']);
        $requests = $tax->selector('request', 'order_id = ?', [$order['row']['id']]);
        CheckoutException::require(count($requests) === 1, 'payment_required');
        $intent = TaxCheckoutEvidence::intent($tax, $order, $requests[0]);
        CheckoutException::require($intent['reviewed'] !== null && $intent['reviewed_body']['outcome'] === 'on_time', 'payment_required');
        $source = new self($locator, $order, $intent, $identity, $transaction);
        $source->proveRetainedCurrent($reader);

        return $source;
    }

    /** Exact original order/request/binding/reviewed-session rows and identity prefix in the same held frame. */
    public function proveRetainedCurrent(CurrentRows $reader): void
    {
        $this->transaction->prove($reader);
        $tax = TaxCheckoutRecords::reader($reader);
        Evidence::same($this->order['row'], $tax->one('order', $this->locator->orderId));
        Evidence::same($this->intent['raw'], TaxCheckoutEvidence::intent($tax, $this->order, $this->intent['row'])['raw']);
        (new ProductionCustomerAccess)->proveHistoricalBindingCurrent($this->locator->historicalBuyerBinding(), $reader, $this->historicalIdentity);
        $tax->proveTables();
        $this->transaction->prove($reader);
    }

    /** Safe immutable producer evidence. No credential, identity token, address, email or private storage path. */
    public function line(int $position): array
    {
        CheckoutException::require($position >= 1 && $position <= count($this->order['body']['lines']));
        $line = $this->order['body']['lines'][$position - 1];
        $reviewed = $this->intent['reviewed_body'];
        $taxed = $reviewed['lines'][$position - 1];
        CheckoutException::require($taxed['line_id'] === $line['public_id'] && $taxed['subtotal_minor'] === $line['amount_minor']);
        $snapshot = $line['selection']['offer_snapshot'];
        $context = $this->order['context']->binding();
        $amounts = $reviewed['amounts'];
        $evidence = ['schema_version' => 2, 'producer' => TaxCheckoutPolicy::PRODUCER,
            'origin_key' => TaxCheckoutPolicy::PRODUCER.':'.$this->locator->orderId.':'.$line['public_id'],
            'order_id' => $this->locator->orderId, 'order_hash' => $this->locator->orderHash,
            'line_id' => $line['public_id'], 'line_hash' => CanonicalJson::hash($line),
            'request_id' => $this->intent['row']['public_id'], 'request_hash' => $this->intent['row']['payload_hash'],
            'reviewed_session_id' => $this->intent['reviewed']['public_id'], 'reviewed_session_hash' => $this->intent['reviewed']['payload_hash'],
            'provider_session_id' => $reviewed['provider_session_id'], 'provider_payment_id' => $this->intent['reviewed']['provider_payment_id'],
            'provider_account' => $context['account_id'], 'funds_mode' => $context['funds_mode'], 'provenance' => $context['provenance'],
            'payment_evidence_origin' => $reviewed['provider_evidence_origin'], 'observed_at' => $reviewed['observed_at'],
            'buyer' => $this->locator->historicalBuyerBinding(), 'buyer_binding_hash' => CanonicalJson::hash($this->locator->historicalBuyerBinding()),
            'buyer_declarations' => $this->order['body']['buyer_declarations'],
            'product' => $snapshot['product'], 'product_hash' => CanonicalJson::hash($snapshot['product']),
            'assent' => $this->order['body']['assent'], 'assent_hash' => CanonicalJson::hash($this->order['body']['assent']),
            'license_version_id' => $line['selection']['license_version_id'], 'license' => $snapshot['license'], 'license_hash' => CanonicalJson::hash($snapshot['license']),
            'asset_revisions' => $snapshot['assets'], 'asset_revisions_hash' => CanonicalJson::hash($snapshot['assets']),
            'candidate' => $this->order['body']['candidate'], 'execution_context' => $context,
            'inventory' => $this->order['body']['inventory'], 'inventory_hash' => CanonicalJson::hash($this->order['body']['inventory']),
            'pre_tax' => ['currency' => 'USD', 'line_amount_minor' => $line['amount_minor'], 'order_subtotal_minor' => $this->order['row']['subtotal_minor']],
            'tax' => ['authority' => 'provider_calculated_buyer_reviewed', 'calculator' => 'stripe_checkout_automatic_tax',
                'automatic_tax' => $reviewed['automatic_tax'], 'tax_behavior' => $amounts['tax_behavior'], 'currency' => 'USD',
                'line_subtotal_minor' => $taxed['subtotal_minor'], 'line_tax_minor' => $taxed['tax_minor'], 'line_total_minor' => $taxed['total_minor'],
                'order_subtotal_minor' => $amounts['subtotal_minor'], 'order_tax_minor' => $amounts['tax_minor'], 'order_total_minor' => $amounts['total_minor']]];

        return [...$evidence, 'source_hash' => CanonicalJson::hash($evidence)];
    }

    public function lineCount(): int
    {
        return count($this->order['body']['lines']);
    }

    public function __serialize(): array
    {
        throw new LogicException('Production tax paid sources cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Production tax paid sources are internal server objects.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Production tax paid sources cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['schema_version' => 2, 'authority' => 'retained_paid_tax_source'];
    }
}
