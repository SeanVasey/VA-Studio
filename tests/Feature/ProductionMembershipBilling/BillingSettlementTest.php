<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingSettlement;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Invoice;
use Stripe\InvoiceLineItem;
use Stripe\InvoicePayment;
use Tests\Support\BillingStripeFixtures as F;
use Tests\TestCase;

/** Pure evaluator over synthetic SDK-built graphs. No outcome here awards, reserves or reverses anything. */
class BillingSettlementTest extends TestCase
{
    public function test_exact_paid_graph_settles_with_the_line_service_period_not_the_invoice_window(): void
    {
        $verdict = BillingSettlement::evaluate(F::snapshots(F::graph()), F::expectation());
        $this->assertSame('settled', $verdict->outcome);
        $this->assertNull($verdict->reason);
        $this->assertSame(['2026-10-07 00:00:00', '2026-11-07 00:00:00', F::AMOUNT, F::CURRENCY],
            [$verdict->facts['line_period_start'], $verdict->facts['line_period_end'], $verdict->facts['amount_minor'], $verdict->facts['currency']]);
        $this->assertSame([F::INVOICE_PAYMENT, F::PAYMENT_INTENT, F::CHARGE, F::BALANCE_TRANSACTION],
            [$verdict->facts['invoice_payment_ref'], $verdict->facts['payment_intent_ref'], $verdict->facts['charge_ref'], $verdict->facts['balance_transaction_ref']]);
    }

    public function test_fixtures_cannot_invent_or_flatten_provider_fields(): void
    {
        foreach ([[Invoice::class, ['subscription' => F::SUBSCRIPTION]], [Invoice::class, ['payment_intent' => F::PAYMENT_INTENT]],
            [Invoice::class, ['charge' => F::CHARGE]], [InvoiceLineItem::class, ['period' => ['begin' => 1, 'end' => 2]]],
            [InvoicePayment::class, ['payment' => ['payment_intent' => F::PAYMENT_INTENT, 'source' => 'x']]]] as [$class, $values]) {
            try {
                F::sdk($class, $values);
                $this->fail($class.' accepted an undocumented key.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertInstanceOf(Invoice::class, Invoice::constructFrom(F::graph()['invoice']));
    }

    public static function cases(): array
    {
        $line = fn (array $values) => ['line' => $values];
        $second = F::sdk(InvoicePayment::class, ['id' => 'inpay_SYNTHETIC2', 'object' => 'invoice_payment', 'livemode' => false, 'invoice' => F::INVOICE,
            'status' => 'paid', 'amount_paid' => 4, 'amount_requested' => 4, 'currency' => 'xts', 'is_default' => false,
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_SYNTHETIC2']]);
        $proration = ['type' => 'subscription_item_details', 'subscription_item_details' => ['invoice_item' => 'ii_SYNTHETIC', 'proration' => true,
            'proration_details' => null, 'subscription' => F::SUBSCRIPTION, 'subscription_item' => 'si_SYNTHETIC']];

        return [
            'open' => [['invoice' => ['status' => 'open', 'amount_paid' => 0, 'amount_remaining' => F::AMOUNT]], 'not_settled', 'invoice_open'],
            'draft' => [['invoice' => ['status' => 'draft']], 'not_settled', 'invoice_draft'],
            'void' => [['invoice' => ['status' => 'void']], 'not_settled', 'invoice_void'],
            'uncollectible' => [['invoice' => ['status' => 'uncollectible']], 'not_settled', 'invoice_uncollectible'],
            'partial_open' => [['invoice' => ['status' => 'open', 'amount_paid' => 1000, 'amount_remaining' => 234]], 'not_settled', 'invoice_open'],
            'partial_paid_amount' => [['invoice' => ['amount_paid' => 1000, 'amount_remaining' => 234]], 'refused', 'amount'],
            'two_payments' => [['payments' => [F::graph()['payments'][0], $second]], 'refused', 'payment_count'],
            'no_payment' => [['payments' => []], 'refused', 'payment_absent'],
            'out_of_band' => [['invoice' => ['amount_paid_off_stripe' => F::AMOUNT], 'payments' => []], 'refused', 'out_of_band'],
            'refunded' => [['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]], 'reversed', 'refunded'],
            'partially_refunded' => [['charge' => ['amount_refunded' => 1]], 'reversed', 'refunded'],
            'disputed' => [['charge' => ['disputed' => true]], 'reversed', 'disputed'],
            'pending_balance' => [['transaction' => ['status' => 'pending']], 'not_settled', 'balance_pending'],
            'fx' => [['transaction' => ['exchange_rate' => 1.1]], 'refused', 'fx'],
            'wrong_currency' => [['invoice' => ['currency' => 'usd']], 'refused', 'currency'],
            'wrong_amount' => [['invoice' => ['total' => 999, 'subtotal' => 999, 'amount_due' => 999, 'amount_paid' => 999], 'line' => ['amount' => 999, 'subtotal' => 999]], 'refused', 'amount'],
            'wrong_account' => [['account' => 'acct_FOREIGNSYNTHETIC'], 'refused', 'account'],
            'wrong_mode' => [['invoice' => ['livemode' => true]], 'refused', 'mode'],
            'wrong_mode_charge' => [['charge' => ['livemode' => true]], 'refused', 'mode'],
            'wrong_customer' => [['invoice' => ['customer' => 'cus_FOREIGNSYNTHETIC']], 'refused', 'customer'],
            'wrong_subscription' => [['invoice' => ['parent' => ['type' => 'subscription_details', 'quote_details' => null,
                'subscription_details' => ['metadata' => [], 'subscription' => 'sub_FOREIGNSYNTHETIC']]]], 'refused', 'subscription'],
            'not_a_subscription_invoice' => [['invoice' => ['parent' => null]], 'refused', 'subscription'],
            'proration_line' => [$line(['parent' => $proration]), 'refused', 'proration'],
            'two_subscription_lines' => [['lines' => [F::graph()['invoice']['lines']['data'][0], F::graph()['invoice']['lines']['data'][0]]], 'refused', 'line_count'],
            'wrong_price' => [$line(['pricing' => ['type' => 'price_details', 'price_details' => ['price' => 'price_FOREIGNSYNTHETIC', 'product' => 'prod_SYNTHETIC'], 'unit_amount_decimal' => null]]), 'refused', 'price'],
            'quantity' => [$line(['quantity' => 2]), 'refused', 'quantity'],
            'zero_amount' => [['invoice' => ['total' => 0, 'subtotal' => 0, 'amount_due' => 0, 'amount_paid' => 0], 'line' => ['amount' => 0, 'subtotal' => 0]], 'refused', 'zero_amount'],
            'invoice_discount' => [['invoice' => ['discounts' => ['di_SYNTHETIC']]], 'refused', 'discount'],
            'line_discount' => [$line(['discount_amounts' => [['amount' => 100, 'discount' => 'di_SYNTHETIC']]]), 'refused', 'discount'],
            'tax' => [['invoice' => ['total_taxes' => [['amount' => 10, 'tax_behavior' => 'exclusive', 'tax_rate_details' => null, 'taxability_reason' => 'standard_rated', 'taxable_amount' => F::AMOUNT, 'type' => 'tax_rate_details']]]], 'refused', 'tax_unapproved'],
            'credit_balance' => [['invoice' => ['starting_balance' => -F::AMOUNT]], 'refused', 'credit_balance'],
            'credit_note' => [['invoice' => ['post_payment_credit_notes_amount' => 100]], 'refused', 'credit_note'],
            'overpaid' => [['invoice' => ['amount_overpaid' => 1]], 'refused', 'amount'],
            'payment_open' => [['payment' => ['status' => 'open', 'amount_paid' => null]], 'not_settled', 'payment_open'],
            'payment_not_intent' => [['payment' => ['payment' => ['type' => 'charge', 'charge' => F::CHARGE]]], 'refused', 'payment_type'],
            'intent_processing' => [['intent' => ['status' => 'processing']], 'not_settled', 'payment_intent_processing'],
            'charge_uncaptured' => [['charge' => ['captured' => false, 'status' => 'succeeded']], 'not_settled', 'charge_not_captured'],
            'connect_charge' => [['charge' => ['on_behalf_of' => 'acct_CONNECTEDSYNTHETIC']], 'refused', 'connect'],
            'missing_charge' => [['intent' => ['latest_charge' => 'ch_ABSENTSYNTHETIC']], 'refused', 'incomplete_graph'],
            'subscription_price_absent' => [['subscription' => ['items' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/subscription_items', 'data' => []]]], 'refused', 'price'],
            'subscription_items_incomplete' => [['subscription' => ['items' => ['object' => 'list', 'has_more' => true, 'url' => '/v1/subscription_items', 'data' => F::graph()['subscription']['items']['data']]]], 'refused', 'price'],
        ];
    }

    #[DataProvider('cases')]
    public function test_unapproved_or_unsettled_shapes_never_settle(array $overrides, string $outcome, string $reason): void
    {
        $verdict = BillingSettlement::evaluate(F::snapshots(F::graph($overrides)), F::expectation());
        $this->assertSame([$outcome, $reason], [$verdict->outcome, $verdict->reason]);
        $this->assertArrayNotHasKey('amount_minor', $verdict->facts);
        $this->assertArrayNotHasKey('line_period_start', $verdict->facts);
    }
}
