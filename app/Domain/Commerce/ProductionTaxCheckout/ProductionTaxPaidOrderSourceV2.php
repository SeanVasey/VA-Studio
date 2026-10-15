<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\HeldSourceTransaction;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use App\Support\CanonicalJson;
use LogicException;
use Throwable;

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
        private ?TaxPaidReadContext $receiptContext,
        private ?TaxPaidSourceRawReads $rawReads,
        private array $rawPlans,
        private array $rawDefinitions,
    ) {}

    /** The consumer must prelock T23 historical identity before calling this under its captured transaction. */
    public static function lockedRead(ProductionTaxPaidOrderLocatorV2 $locator, CurrentRows $reader, array $prelockedIdentityRaw): self
    {
        $bornAtNs = hrtime(true);
        $context = TaxPaidReadContext::birth($reader, $bornAtNs);
        try {
            $transaction = HeldSourceTransaction::capture($reader);
            $context?->attach($transaction->committedReadContext($reader));
            $read = fn (): self => self::authenticatedRead($locator, $reader, $prelockedIdentityRaw, $transaction, $context);

            return $context === null ? $read() : $context->held($read);
        } catch (Throwable $error) {
            $context?->abortOwnedFrame();
            throw $error;
        }
    }

    private static function authenticatedRead(ProductionTaxPaidOrderLocatorV2 $locator, CurrentRows $reader, array $prelockedIdentityRaw,
        HeldSourceTransaction $transaction, ?TaxPaidReadContext $context): self
    {
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
        $raw = $context === null ? null : TaxPaidSourceRawReads::capture($context);
        $plans = [];
        foreach (self::selectors($order, $intent) as [$kind, $where, $bindings, $expected]) {
            if ($raw !== null) {
                $actual = $raw->rows($kind, $where, $bindings, 1);
                Evidence::same($expected, $actual);
                $plans[] = [$kind, $where, $bindings, $actual];
            }
        }
        $source = new self($locator, $order, $intent, $identity, $transaction, $context, $raw, $plans, $raw?->definitions(1) ?? []);
        $source->proveRetainedCurrent($reader);

        return $source;
    }

    /** Exact original order/request/binding/reviewed-session rows and identity prefix in the same held frame. */
    public function proveRetainedCurrent(CurrentRows $reader): void
    {
        if ($this->receiptContext !== null) {
            CheckoutException::require($this->receiptContext->reader() === $reader, 'committed_read_frame');
            $this->receiptContext->held(fn () => $this->proveAuthenticatedCurrent($reader));

            return;
        }
        $this->proveAuthenticatedCurrent($reader);
    }

    private function proveAuthenticatedCurrent(CurrentRows $reader): void
    {
        $this->transaction->prove($reader);
        $tax = TaxCheckoutRecords::reader($reader);
        Evidence::same($this->order['row'], $tax->one('order', $this->locator->orderId));
        Evidence::same($this->intent['raw'], TaxCheckoutEvidence::intent($tax, $this->order, $this->intent['row'])['raw']);
        (new ProductionCustomerAccess)->proveHistoricalBindingCurrent($this->locator->historicalBuyerBinding(), $reader, $this->historicalIdentity);
        $tax->proveTables();
        $this->transaction->prove($reader);
    }

    public function committedReadReceipt(CurrentRows $reader, int $originalDeadlineNs,
        ?ProductionTaxPaidOrderConsumerCommitAdmissionV2 $admission = null): ProductionTaxPaidOrderCommittedReadReceiptV2
    {
        $context = $this->receiptContext;
        CheckoutException::require($context !== null, 'unsupported');
        CheckoutException::require($context->reader() === $reader, 'committed_read_frame');
        $deadline = $context->deadline($originalDeadlineNs);
        // Third/incompatible siblings are rejected without disturbing the installed admission fence.
        $observer = TaxPaidOriginalCommitDispatcher::capture($this, $context, $reader, $deadline, $admission);

        return $observer->mint();
    }

    /** @internal Original identity-owned capability; never a new principal or historical prefix. */
    public function historicalCommittedReceipt(CurrentRows $reader, int $deadlineNs, IdentityOriginalCommitWitness $witness): IdentityHistoricalCommittedReceipt
    {
        $this->proveRetainedCurrent($reader);

        return IdentityHistoricalCommittedReceipt::capture($this->locator->historicalBuyerBinding(), $reader,
            $this->historicalIdentity, $deadlineNs, $witness);
    }

    /** @internal Fixed original comparisons. Authentication and callback work have already ended. */
    public function proveReceiptRows(TaxPaidReadContext $context, int $depth): void
    {
        CheckoutException::require($context === $this->receiptContext && $this->rawReads !== null, 'committed_read_frame');
        $context->prove($depth);
        CheckoutException::require($this->rawReads->definitions($depth) === $this->rawDefinitions, 'committed_read_frame');
        foreach ($this->rawPlans as [$kind, $where, $bindings, $expected]) {
            CheckoutException::require($this->rawReads->rows($kind, $where, $bindings, $depth) === $expected, 'committed_read_frame');
        }
        $context->prove($depth);
    }

    /** Retain the source by reference inside the transaction so failed first mint/commit is cleanable. */
    public function abortOwnedFrame(): void
    {
        $this->receiptContext?->abortOwnedFrame();
    }

    private static function selectors(array $order, array $intent): array
    {
        $rows = ['order' => $order['row'], 'request' => $intent['row'], 'binding' => $intent['binding'], 'reviewed' => $intent['reviewed']];
        $plans = [];
        foreach ($rows as $kind => $row) {
            foreach (['id', 'public_id'] as $column) {
                $plans[] = [$kind, $column.' = ?', [$row[$column]], [$row]];
            }
        }
        $o = $rows['order'];
        $r = $rows['request'];
        $b = $rows['binding'];
        $v = $rows['reviewed'];

        return [...$plans,
            ['order', 'buyer_origin_id = ? AND request_key = ?', [$o['buyer_origin_id'], $o['request_key']], [$o]],
            ['request', 'order_id = ?', [$o['id']], [$r]],
            ['request', 'idempotency_key = ?', [$r['idempotency_key']], [$r]],
            ['binding', 'request_id = ?', [$r['id']], [$b]],
            ['binding', 'account_id = ? AND funds_mode = ? AND provider_session_id = ?', [$b['account_id'], $b['funds_mode'], $b['provider_session_id']], [$b]],
            ['reviewed', 'request_id = ?', [$r['id']], [$v]],
            ['reviewed', 'order_id = ?', [$o['id']], [$v]],
            ['reviewed', 'binding_id = ?', [$b['id']], [$v]],
            ['reviewed', 'account_id = ? AND funds_mode = ? AND provider_session_id = ?', [$v['account_id'], $v['funds_mode'], $v['provider_session_id']], [$v]],
            ['reviewed', 'account_id = ? AND funds_mode = ? AND provider_payment_id = ?', [$v['account_id'], $v['funds_mode'], $v['provider_payment_id']], [$v]]];
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

    private function __clone() {}

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
