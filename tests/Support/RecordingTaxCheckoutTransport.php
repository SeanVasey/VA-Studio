<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\TaxExecutionContext;
use Illuminate\Support\Facades\DB;

/**
 * In-process synthetic Stripe Checkout `automatic_tax` responses, restricted to testing and test funds.
 * The tax figures are fixture inputs standing in for a provider calculation; they are not a calculation.
 */
final class RecordingTaxCheckoutTransport implements TaxCheckoutTransport
{
    public const SESSION = 'cs_test_SYNTHETICTAX';

    public const PAYMENT = 'pi_SYNTHETICTAX';

    /** Every call, in order: [operation, argument]. */
    public array $calls = [];

    public array $params = [];

    public bool $paid = false;

    /** Synthetic provider tax per line once the buyer's location is known (status complete). */
    public int $lineTax = 437;

    public ?\Closure $mutateSession = null;

    public function boundTo(): string
    {
        return 'synthetic-tax-transport-v1';
    }

    public function create(TaxExecutionContext $context, array $params, string $idempotencyKey): array
    {
        $this->guard($context);
        if ($this->params !== []) {
            Evidence::same($this->params, $params);
        }
        $this->params = $params;
        $this->calls[] = ['create', $idempotencyKey];

        return ['id' => self::SESSION, 'object' => 'checkout.session'];
    }

    public function retrieve(TaxExecutionContext $context, string $sessionId): array
    {
        $this->guard($context);
        $this->calls[] = ['retrieve', $sessionId];
        CheckoutException::require($sessionId === self::SESSION && $this->params !== []);
        $session = $this->session($context->taxBehavior);

        return $this->mutateSession === null ? $session : ($this->mutateSession)($session);
    }

    public function paymentIntent(TaxExecutionContext $context, string $paymentId): array
    {
        $this->guard($context);
        $this->calls[] = ['paymentIntent', $paymentId];
        CheckoutException::require($paymentId === self::PAYMENT && $this->paid);
        $total = $this->session($context->taxBehavior)['amount_total'];

        return ['id' => self::PAYMENT, 'object' => 'payment_intent', 'livemode' => false, 'currency' => 'usd', 'amount' => $total, 'amount_received' => $total,
            'amount_capturable' => 0, 'capture_method' => $this->params['payment_intent_data']['capture_method'], 'payment_method_types' => ['card'],
            'status' => 'succeeded', 'metadata' => $this->params['payment_intent_data']['metadata'], 'client_secret' => 'pi_SYNTHETICTAX_secret_NOTRETAINED'];
    }

    private function guard(TaxExecutionContext $context): void
    {
        CheckoutException::require(app()->environment('testing') && $context->fundsMode === 'test' && DB::transactionLevel() === 0, 'provider', 503);
    }

    private function session(string $behavior): array
    {
        $tax = $this->paid ? $this->lineTax : 0;
        $lines = [];
        foreach ($this->params['line_items'] as $line) {
            $price = $line['price_data'];
            $amount = $price['unit_amount'];
            $lines[] = ['quantity' => 1, 'currency' => 'usd', 'amount_subtotal' => $amount, 'amount_tax' => $tax, 'amount_discount' => 0,
                'amount_total' => $behavior === 'exclusive' ? $amount + $tax : $amount,
                'price' => ['currency' => 'usd', 'unit_amount' => $amount, 'tax_behavior' => $price['tax_behavior'],
                    'product' => ['name' => $price['product_data']['name'], 'metadata' => $price['product_data']['metadata']]]];
        }
        $subtotal = array_sum(array_column($lines, 'amount_subtotal'));
        $taxTotal = array_sum(array_column($lines, 'amount_tax'));

        return ['id' => self::SESSION, 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'payment', 'currency' => 'usd',
            'client_reference_id' => $this->params['client_reference_id'], 'metadata' => $this->params['metadata'],
            'amount_subtotal' => $subtotal, 'amount_total' => array_sum(array_column($lines, 'amount_total')),
            'expires_at' => $this->params['expires_at'], 'total_details' => ['amount_discount' => 0, 'amount_tax' => $taxTotal, 'amount_shipping' => 0],
            'automatic_tax' => ['enabled' => true, 'liability' => ['type' => 'self'], 'provider' => 'stripe', 'status' => $this->paid ? 'complete' : 'requires_location_inputs'],
            // Buyer location and contact are present on real sessions; the retention projection must drop them.
            'customer_details' => $this->paid ? ['address' => ['country' => 'ZZ', 'postal_code' => 'SYNTHETIC-POSTAL'], 'email' => 'synthetic-buyer-not-retained@example.test'] : null,
            'payment_method_types' => ['card'], 'status' => $this->paid ? 'complete' : 'open', 'payment_status' => $this->paid ? 'paid' : 'unpaid',
            'payment_intent' => $this->paid ? self::PAYMENT : null, 'url' => $this->paid ? null : 'https://checkout.stripe.com/c/pay/'.self::SESSION,
            'line_items' => ['object' => 'list', 'has_more' => false, 'data' => $lines]];
    }
}
