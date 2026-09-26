<?php

namespace Tests\Support;

use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic hosted-session transport only; never a live Stripe account or payment. */
final class CheckoutFixtures
{
    public const ACCOUNT = 'acct_SYNTHETICONLY';
    public const SESSION = 'cs_test_SYNTHETIC';

    public static function policy(): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_hosted_checkout', 'version' => 'SYNTHETIC-CHECKOUT-1',
            'provider_lifetime_seconds' => 3600, 'retry_seconds' => 900,
            'pending_resources' => 'retain_until_authoritative_finalization', 'tax' => 'fixed_test_zero',
            'return_origin' => 'https://store.example.invalid'];
    }

    public static function configure(): void
    {
        OrderFixtures::configure();
        $tax = PricingFixtures::policy(); $tax['tax']['rate_bps'] = 0;
        PricingFixtures::configure($tax);
        config(['commerce.test_checkout_policy' => json_encode(self::policy(), JSON_THROW_ON_ERROR),
            'payments.stripe.checkout_enabled' => true, 'payments.stripe.mode' => 'test',
            'payments.stripe.account_id' => self::ACCOUNT, 'payments.stripe.secret_key' => 'sk_test_SYNTHETIC_ONLY']);
    }

    public static function prepared(bool $exclusive = false, bool $promoted = false): array
    {
        $f = OrderFixtures::priced($exclusive, $promoted);
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($f['quote']));

        return $f + ['order' => $order->refresh()];
    }

    public static function preparedPrice(int $amount): array
    {
        $f = QuoteFixtures::selection($amount); $scopes = app(ManageRightsScope::class);
        $scope = $scopes->register('checkout-fixture-'.Str::uuid(), 'SYNTHETIC-CHECKOUT-SCOPE', $f['actor']);
        $scopes->link($scope->id, $f['revision']->id, 'SYNTHETIC-CHECKOUT-LINK', $f['actor']);
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        $pricing = app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));

        return $f + ['quote' => $quote, 'pricing' => $pricing, 'order' => $order->refresh()];
    }

    public static function session(array $params, string $id = self::SESSION): array
    {
        $lines = array_map(static function (array $line): array {
            $price = $line['price_data']; $amount = $price['unit_amount'] * $line['quantity'];

            return ['currency' => $price['currency'], 'quantity' => $line['quantity'], 'amount_subtotal' => $amount,
                'amount_total' => $amount, 'amount_discount' => 0, 'amount_tax' => 0,
                'price' => ['currency' => $price['currency'], 'unit_amount' => $price['unit_amount']]];
        }, $params['line_items']);
        $total = array_sum(array_column($lines, 'amount_total'));

        return ['object' => 'checkout.session', 'id' => $id, 'livemode' => false, 'mode' => 'payment', 'currency' => 'usd',
            'client_reference_id' => $params['client_reference_id'], 'metadata' => $params['metadata'],
            'status' => 'open', 'payment_status' => 'unpaid', 'expires_at' => $params['expires_at'],
            'amount_subtotal' => $total, 'amount_total' => $total,
            'total_details' => ['amount_discount' => 0, 'amount_shipping' => 0, 'amount_tax' => 0],
            'automatic_tax' => ['enabled' => false], 'payment_method_types' => ['card'],
            'line_items' => ['object' => 'list', 'has_more' => false, 'data' => $lines],
            'url' => 'https://checkout.stripe.com/c/pay/'.$id, 'payment_intent' => null];
    }

    public static function gateway(): StripeCheckoutGateway
    {
        return new class implements StripeCheckoutGateway
        {
            public array $calls = [];
            public array $accountResponse = ['id' => CheckoutFixtures::ACCOUNT, 'object' => 'account'];
            public ?array $session = null;
            public mixed $onCreate = null;
            public mixed $onRetrieve = null;

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

            private function record(string $operation, array $data = []): void
            {
                $this->calls[] = $data + ['operation' => $operation, 'transaction_level' => DB::transactionLevel(),
                    'intent_count' => DB::table('checkout_intents')->count()];
            }
        };
    }
}
