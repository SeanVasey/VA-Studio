<?php

namespace Tests\Support;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Payments\ReceiveStripeWebhook;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use Illuminate\Support\Facades\DB;

/** Synthetic authoritative-provider transport; no real Stripe credentials or network calls. */
final class PaymentFixtures
{
    public const PAYMENT = 'pi_SYNTHETICPAYMENT';

    public static function configure(): void
    {
        CheckoutFixtures::configure();
        config(['payments.stripe.processing_enabled' => true, 'payments.stripe.webhook_enabled' => true,
            'payments.stripe.webhook_secret' => StripeWebhookFixtures::SECRET]);
    }

    public static function started(object $gateway, bool $exclusive = false, bool $promoted = false): array
    {
        $fixture = CheckoutFixtures::prepared($exclusive, $promoted);
        app(HostedCheckout::class)->start($fixture['order']->public_id, InventoryFixtures::OWNER);
        $gateway->session['status'] = 'complete'; $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null; $gateway->session['payment_intent'] = self::PAYMENT;
        $gateway->payment = self::payment($gateway->session);

        return $fixture + ['intent' => CheckoutIntent::where('order_id', $fixture['order']->id)->sole()];
    }

    public static function payment(array $session): array
    {
        return ['object' => 'payment_intent', 'id' => self::PAYMENT, 'livemode' => false, 'currency' => 'usd',
            'amount' => $session['amount_total'], 'amount_received' => $session['amount_total'], 'amount_capturable' => 0,
            'status' => 'succeeded', 'capture_method' => 'automatic', 'confirmation_method' => 'automatic',
            'payment_method_types' => ['card'], 'metadata' => $session['metadata'],
            'latest_charge' => 'ch_SYNTHETICCHARGE'];
    }

    public static function event(array $session, string $id = 'evt_SYNTHETICPAYMENT', string $type = 'checkout.session.completed'): array
    {
        $event = StripeWebhookFixtures::event();
        $event['id'] = $id; $event['type'] = $type; $event['api_version'] = '2026-08-26.dahlia';
        $event['data']['object'] = $session;

        return $event;
    }

    public static function receipt(array $session, string $id = 'evt_SYNTHETICPAYMENT', string $type = 'checkout.session.completed'): StripeWebhookReceipt
    {
        return self::receive(self::event($session, $id, $type));
    }

    public static function receive(array $event): StripeWebhookReceipt
    {
        $body = StripeWebhookFixtures::body($event);

        return app(ReceiveStripeWebhook::class)->handle($body, StripeWebhookFixtures::signature($body));
    }

    public static function unchangedBusinessEvidence(): array
    {
        $rows = [];
        foreach (['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings',
            'inventory_reservations', 'inventory_claims', 'promotion_uses'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    public static function gateway(): StripePaymentGateway
    {
        return new class implements StripeCheckoutGateway, StripePaymentGateway
        {
            public array $calls = [];
            public array $accountResponse = ['id' => CheckoutFixtures::ACCOUNT, 'object' => 'account'];
            public ?array $session = null;
            public ?array $payment = null;
            public mixed $onCreate = null;
            public mixed $onRetrieve = null;
            public mixed $onPayment = null;

            public function account(): array
            {
                $this->record('account');

                return $this->accountResponse;
            }

            public function create(array $params, string $idempotencyKey): array
            {
                $this->record('create', ['params' => $params, 'key' => $idempotencyKey]);
                $this->session = $this->onCreate === null ? CheckoutFixtures::session($params) : ($this->onCreate)($params, $idempotencyKey);

                return $this->session;
            }

            public function retrieve(string $sessionId): array
            {
                $this->record('retrieve', ['id' => $sessionId]);

                return $this->onRetrieve === null ? $this->session : ($this->onRetrieve)($sessionId);
            }

            public function paymentIntent(string $paymentIntentId): array
            {
                $this->record('payment_intent', ['id' => $paymentIntentId]);

                return $this->onPayment === null ? $this->payment : ($this->onPayment)($paymentIntentId);
            }

            private function record(string $operation, array $data = []): void
            {
                $this->calls[] = $data + ['operation' => $operation, 'transaction_level' => DB::transactionLevel()];
            }
        };
    }
}
