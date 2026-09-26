<?php

namespace Tests\Support;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\PriceQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic test-account evidence only. It never charges a provider or activates delivery. */
final class FinalizationFixtures
{
    public static function policy(): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_order_finalization', 'version' => 'test-order-finalization-v1',
            'eligibility' => 'confirmation_observed_before_attempt_expiry', 'exception_resources' => 'retain_pending',
            'grant_effective_time' => 'finalization_time', 'buyer_identity' => 'unverified_guest'];
    }

    public static function configure(): void
    {
        PaymentFixtures::configure();
        config(['payments.stripe.finalization_enabled' => true,
            'payments.stripe.finalization_policy' => json_encode(self::policy(), JSON_THROW_ON_ERROR)]);
    }

    public static function confirmed(object $gateway, bool $exclusive = false, bool $promoted = false): array
    {
        return self::confirm(PaymentFixtures::started($gateway, $exclusive, $promoted));
    }

    public static function confirm(array $fixture): array
    {
        $outcome = app(VerifyTestPayment::class)->reconcile($fixture['intent']);
        if ($outcome !== 'awaiting_finalization') { throw new \LogicException('Synthetic payment failed verification: '.$outcome); }

        return $fixture + ['payment' => VerifiedPayment::where('order_id', $fixture['order']->id)->sole(),
            'original' => app(ReadOrder::class)->verify($fixture['order'])];
    }

    public static function confirmedMixedCart(object $gateway): array
    {
        $exclusive = ExclusiveSelectionFixtures::active(); $nonExclusive = InventoryFixtures::selection();
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(),
            [...$exclusive['items'], ...$nonExclusive['items']]);
        $promotion = PromotionFixtures::policy(['eligibility' => ['mode' => 'offer_revisions',
            'offer_revision_ids' => [$exclusive['revision']->id, $nonExclusive['revision']->id]]]);
        PromotionFixtures::configure([$promotion]);
        $pricing = app(PriceQuote::class)->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));
        app(HostedCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        $gateway->session['status'] = 'complete'; $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null; $gateway->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $gateway->payment = PaymentFixtures::payment($gateway->session);

        return self::confirm(compact('exclusive', 'nonExclusive', 'quote', 'pricing', 'promotion', 'order') +
            ['intent' => CheckoutIntent::where('order_id', $order->id)->sole()]);
    }

    public static function ownedHttp(TestCase $test, object $gateway): array
    {
        $fixture = InventoryFixtures::selection();
        $quoteId = $test->postJson('/quotes', ['items' => $fixture['items']], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->json('quote.id');
        $quote = Quote::where('public_id', $quoteId)->sole();
        app(PriceQuote::class)->create($quote->public_id, $quote->owner_key);
        $request = OrderFixtures::request($quote, $quote->owner_key);
        $orderId = $test->postJson('/orders', $request, ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('order.id');
        $order = Order::where('public_id', $orderId)->sole();
        app(HostedCheckout::class)->start($order->public_id, $quote->owner_key);
        $gateway->session['status'] = 'complete'; $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null; $gateway->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $gateway->payment = PaymentFixtures::payment($gateway->session);

        return self::confirm(array_replace($fixture, ['quote' => $quote, 'order' => $order,
            'intent' => CheckoutIntent::where('order_id', $order->id)->sole()]));
    }

    public static function retained(): array
    {
        $rows = PaymentFixtures::unchangedBusinessEvidence();
        foreach (['verified_payments', 'order_finalizations', 'license_grants', 'pending_entitlements', 'fulfillment_outbox', 'exclusive_sales'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }
}
