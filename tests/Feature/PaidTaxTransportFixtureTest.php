<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutEvidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxExecutionContext;
use Tests\Support\PaidTaxDistinctTransportFixture;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** Verifies the test transport protocol without a catalog/database workflow or any provider call. */
class PaidTaxTransportFixtureTest extends TestCase
{
    use ProductionTaxCheckoutFixtures;

    public function test_distinct_synthetic_ids_keep_open_url_and_paid_financial_protocol_consistent(): void
    {
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $context = TaxExecutionContext::current(self::taxMachine());
        $params = ['client_reference_id' => 'synthetic-order', 'metadata' => ['order' => 'synthetic-order'], 'expires_at' => time() + 3600,
            'payment_intent_data' => ['capture_method' => 'automatic', 'metadata' => ['order' => 'synthetic-order']],
            'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => 4999, 'tax_behavior' => 'exclusive',
                'product_data' => ['name' => 'Synthetic fixture', 'metadata' => ['line_id' => 'synthetic-line']]]]]];
        $request = ['params' => $params, 'execution_context' => $context->binding(), 'amounts' => ['subtotal_minor' => 4999], 'order_public_id' => 'synthetic-order'];
        $transport = new PaidTaxDistinctTransportFixture('cs_test_synthetictax', 'pi_synthetictax');
        $this->assertSame('cs_test_synthetictax', $transport->create($context, $params, 'synthetic-fixture-key')['id']);
        $open = TaxCheckoutEvidence::session($transport->retrieve($context, 'cs_test_synthetictax'), $request);
        $this->assertSame(['cs_test_synthetictax', 'https://checkout.stripe.com/c/pay/cs_test_synthetictax', null],
            [$open['session_id'], $open['url'], $open['payment_intent_id']]);
        $transport->paid = true;
        $paid = TaxCheckoutEvidence::session($transport->retrieve($context, 'cs_test_synthetictax'), $request);
        $this->assertSame(['cs_test_synthetictax', null, 'pi_synthetictax', 5436],
            [$paid['session_id'], $paid['url'], $paid['payment_intent_id'], $paid['amount_total']]);
        $this->assertSame('pi_synthetictax', $transport->paymentIntent($context, 'pi_synthetictax')['id']);
    }
}
