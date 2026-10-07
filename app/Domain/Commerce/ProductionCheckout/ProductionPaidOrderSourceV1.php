<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Support\CanonicalJson;
use LogicException;

/** Server-minted paid graph for a future distinct grant-origin consumer. It issues no grant itself. */
final readonly class ProductionPaidOrderSourceV1 implements \JsonSerializable
{
    private function __construct(
        private ProductionPaidOrderLocatorV1 $locator,
        private array $order,
        private array $intent,
        private array $historicalIdentity,
        private HeldSourceTransaction $transaction,
    ) {}

    /** Consumer must prelock T23 historical identity before calling this under its captured transaction. */
    public static function lockedRead(ProductionPaidOrderLocatorV1 $locator, CurrentRows $reader, array $prelockedIdentityRaw): self
    {
        $transaction = HeldSourceTransaction::capture($reader);
        $access = new ProductionCustomerAccess;
        $identity = $access->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
        Evidence::same($prelockedIdentityRaw, $identity);
        $rows = Records::retained($reader);
        $order = OrderEvidence::order($rows, $rows->one('order', $locator->orderId));
        CheckoutException::require($order['row']['payload_hash'] === $locator->orderHash);
        Evidence::same($locator->historicalBuyerBinding(), $order['body']['buyer']);
        $intents = $rows->selector('intent', 'order_id = ?', [$order['row']['id']]);
        CheckoutException::require(count($intents) === 1);
        $intent = HostedEvidence::intent($rows, $order, $intents[0]);
        CheckoutException::require($intent['payment'] !== null && $intent['payment']['outcome'] === 'on_time', 'payment_required');
        $source = new self($locator, $order, $intent, $identity, $transaction);
        $source->proveRetainedCurrent($reader);

        return $source;
    }

    /** Exact original order/payment/line and identity-prefix fence; grants still need their own final authority. */
    public function proveRetainedCurrent(CurrentRows $reader): void
    {
        $this->transaction->prove($reader);
        $rows = Records::retained($reader);
        OrderEvidence::proveRetained($rows, $this->order['raw']);
        HostedEvidence::proveRetained($rows, $this->intent['raw']);
        (new ProductionCustomerAccess)->proveHistoricalBindingCurrent($this->locator->historicalBuyerBinding(), $reader, $this->historicalIdentity);
        $rows->provePrimary();
        $this->transaction->prove($reader);
    }

    /** Safe immutable producer evidence. No credential stamp, identity token or private storage path. */
    public function line(int $position): array
    {
        CheckoutException::require($position >= 1 && $position <= count($this->order['lines']));
        $record = $this->order['lines'][$position - 1];
        $descriptor = $this->order['body']['lines'][$position - 1];
        $snapshot = $descriptor['selection']['offer_snapshot'];
        $payment = $this->intent['payment'];
        $confirmed = Evidence::open($payment, 'production_checkout_confirmed_payment');
        $context = $this->order['context']->binding();
        $evidence = ['schema_version' => 1, 'producer' => 'production_checkout_v1',
            'origin_key' => 'production_checkout_v1:'.$this->locator->orderId.':'.$record['public_id'],
            'order_id' => $this->locator->orderId, 'order_hash' => $this->locator->orderHash,
            'line_id' => $record['public_id'], 'line_hash' => $record['line_hash'], 'line_record_hash' => $record['payload_hash'],
            'payment_id' => $payment['public_id'], 'payment_hash' => $payment['payload_hash'],
            'provider_payment_id' => $payment['provider_payment_id'], 'provider_account' => $context['account_id'],
            'funds_mode' => $context['funds_mode'], 'provenance' => $context['provenance'],
            'payment_evidence_origin' => $confirmed['provider_evidence_origin'], 'observed_at' => $payment['observed_at'],
            'buyer' => $this->locator->historicalBuyerBinding(), 'buyer_binding_hash' => CanonicalJson::hash($this->locator->historicalBuyerBinding()),
            'buyer_declarations' => $this->order['body']['buyer_declarations'],
            'product' => $snapshot['product'], 'product_hash' => CanonicalJson::hash($snapshot['product']),
            'assent' => $this->order['body']['assent'], 'assent_hash' => CanonicalJson::hash($this->order['body']['assent']),
            'license_version_id' => $record['license_version_id'], 'license' => $snapshot['license'], 'license_hash' => CanonicalJson::hash($snapshot['license']),
            'asset_revisions' => $snapshot['assets'], 'asset_revisions_hash' => CanonicalJson::hash($snapshot['assets']),
            'amounts' => $this->order['body']['amounts'], 'line_amount_minor' => $descriptor['amount_minor'], 'line_tax_minor' => $descriptor['tax_minor'],
            'candidate' => $this->order['review']['body']['candidate'], 'execution_context' => $context,
            'inventory' => $this->order['attempt_body']['inventory'], 'inventory_hash' => CanonicalJson::hash($this->order['attempt_body']['inventory'])];

        return [...$evidence, 'source_hash' => CanonicalJson::hash($evidence)];
    }

    public function lineCount(): int
    {
        return count($this->order['lines']);
    }

    public function __serialize(): array
    {
        throw new LogicException('Production paid sources cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Production paid sources are internal server objects.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Production paid sources cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['schema_version' => 1, 'authority' => 'retained_paid_source'];
    }
}
