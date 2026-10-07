<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\UnboundTaxCheckoutTransport;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** Actual enrolled buyer/source/catalog/assent graph; every provider figure is an explicit synthetic fixture. */
class ProductionTaxCheckoutJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    public function test_unbound_provider_retains_the_exact_automatic_tax_request_and_never_touches_a_transport(): void
    {
        $f = $this->taxOrder();
        $this->assertSame(['currency' => 'USD', 'subtotal_minor' => 4999, 'tax' => 'calculated_by_provider_at_hosted_checkout',
            'total' => 'reviewed_by_buyer_at_hosted_checkout'], $f['order']['amounts']);
        $this->assertNull(config('production-tax-checkout.provider'));
        try {
            $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('An unbound provider must fail closed.');
        } catch (CheckoutException $error) {
            $this->assertSame(['provider_unbound', 503], [$error->reason, $error->status]);
        }
        $this->assertSame([], $f['transport']->calls);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['request'], 1);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['binding'], 0);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);

        $row = (array) DB::table(TaxCheckoutSchema::TABLES['request'])->sole();
        $request = Evidence::open($row, 'production_tax_checkout_provider_request');
        $order = (array) DB::table(TaxCheckoutSchema::TABLES['order'])->sole();
        $line = Evidence::open($order, 'production_tax_checkout_order')['lines'][0];
        $metadata = ['schema_version' => '2', 'producer' => 'production_tax_checkout_v2', 'order_id' => $f['order']['orderId'],
            'order_hash' => $order['payload_hash'], 'request_id' => $row['public_id'], 'buyer_origin_id' => $f['buyer']['binding']['origin_id'], 'funds_mode' => 'test'];
        $return = 'https://review.invalid/production/tax-checkout/orders/'.$f['order']['orderId'].'/return';
        $expected = ['mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => $f['order']['orderId'], 'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata, 'capture_method' => 'automatic'],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => 4999, 'tax_behavior' => 'exclusive',
                'product_data' => ['name' => mb_strcut($line['selection']['offer_snapshot']['product']['title'].' — '.$line['selection']['offer_snapshot']['license']['name'], 0, 240, 'UTF-8'),
                    'metadata' => ['line_id' => $line['public_id'], 'line_hash' => CanonicalJson::hash($line)]]]]],
            'automatic_tax' => ['enabled' => true], 'adaptive_pricing' => ['enabled' => false], 'allow_promotion_codes' => false,
            'customer_creation' => 'if_required', 'success_url' => $return, 'cancel_url' => $return,
            'expires_at' => CarbonImmutable::parse($row['created_at'])->addSeconds(3600)->timestamp, 'expand' => ['line_items.data.price.product']];
        $this->assertSame(CanonicalJson::encode($expected), CanonicalJson::encode($request['params']));
        $this->assertSame(['enabled' => true], $request['params']['automatic_tax']);
        $this->assertArrayNotHasKey('tax', $request['params']['payment_intent_data']);
        $this->assertStringNotContainsString('calculation', CanonicalJson::encode($request['params']));
        $this->assertSame('va-production-tax-checkout-v2-'.$row['public_id'], $row['idempotency_key']);
        $this->assertSame(['exclusive', 2500, 4999], [$row['tax_behavior'], $row['maximum_rate_bps'], $row['subtotal_minor']]);

        // The shipped refusing default is never admitted, even when configuration names it.
        config(['production-tax-checkout.provider' => 'unbound']);
        $unbound = new ProductionTaxCheckout($f['access'], new UnboundTaxCheckoutTransport);
        try {
            $unbound->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('The refusing default transport was admitted.');
        } catch (CheckoutException $error) {
            $this->assertSame('provider_unbound', $error->reason);
        }
        // A configured identifier that the bound transport does not report is refused before any call.
        config(['production-tax-checkout.provider' => 'some-other-transport']);
        try {
            $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('A mismatched transport identity was admitted.');
        } catch (CheckoutException $error) {
            $this->assertSame('provider_unbound', $error->reason);
        }
        $this->assertSame([], $f['transport']->calls);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['request'], 1);
    }

    public function test_bound_transport_retains_the_buyer_reviewed_provider_tax_exactly_once(): void
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $open = $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(['open', 'https://checkout.stripe.com/c/pay/cs_test_SYNTHETICTAX', 'unverified'],
            [$open['checkoutStatus'], $open['checkoutUrl'], $open['paymentStatus']]);
        $this->assertSame([['create', 'va-production-tax-checkout-v2-'.DB::table(TaxCheckoutSchema::TABLES['request'])->value('public_id')],
            ['retrieve', 'cs_test_SYNTHETICTAX']], $f['transport']->calls);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['binding'], 1);

        // Before payment nothing is retained as reviewed: an open session's tax is not yet the buyer's reviewed amount.
        $pending = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(['open', 'unverified'], [$pending['checkoutStatus'], $pending['paymentStatus']]);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);

        $f['transport']->paid = true;
        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(['complete', 'verified', 'pending_fulfillment'], [$paid['checkoutStatus'], $paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $this->assertSame(['currency' => 'USD', 'subtotal_minor' => 4999, 'tax_behavior' => 'exclusive', 'tax_minor' => 437, 'total_minor' => 5436], $paid['reviewedAmounts']);
        $reviewed = (array) DB::table(TaxCheckoutSchema::TABLES['reviewed'])->sole();
        $this->assertSame([4999, 437, 5436, 'cs_test_SYNTHETICTAX', 'pi_SYNTHETICTAX'], [$reviewed['amount_subtotal_minor'], $reviewed['amount_tax_minor'],
            $reviewed['amount_total_minor'], $reviewed['provider_session_id'], $reviewed['provider_payment_id']]);
        $body = Evidence::open($reviewed, 'production_tax_checkout_reviewed_session');
        $this->assertSame(['enabled' => true, 'provider' => 'stripe', 'status' => 'complete'], $body['automatic_tax']);
        $encoded = CanonicalJson::encode($body);
        foreach (['customer_details', 'SYNTHETIC-POSTAL', 'synthetic-buyer-not-retained', 'client_secret', 'NOTRETAINED'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }

        $rows = DB::table(TaxCheckoutSchema::TABLES['reviewed'])->get()->toJson();
        $calls = count($f['transport']->calls);
        $this->assertSame($paid, $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']) + ['checkoutStatus' => 'complete']);
        $this->assertSame($calls, count($f['transport']->calls), 'A retained reviewed session needs no further provider read.');
        $this->assertSame($rows, DB::table(TaxCheckoutSchema::TABLES['reviewed'])->get()->toJson());
        $status = $f['checkout']->status($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame($paid['reviewedAmounts'], $status['reviewedAmounts']);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_inclusive_behavior_retains_provider_tax_inside_the_unchanged_total(): void
    {
        $f = $this->taxOrder('inclusive');
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('inclusive', $f['transport']->params['line_items'][0]['price_data']['tax_behavior']);
        $f['transport']->paid = true;
        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(['currency' => 'USD', 'subtotal_minor' => 4999, 'tax_behavior' => 'inclusive', 'tax_minor' => 437, 'total_minor' => 4999], $paid['reviewedAmounts']);
    }

    public static function inconsistentProviderSessions(): array
    {
        return [
            'automatic tax disabled' => [fn (array $s): array => array_replace_recursive($s, ['automatic_tax' => ['enabled' => false]]), 'provider_uncertain'],
            'tax total differs from its lines' => [fn (array $s): array => array_replace_recursive($s, ['total_details' => ['amount_tax' => 438], 'amount_total' => 5437]), 'provider_uncertain'],
            'total is not subtotal plus tax' => [fn (array $s): array => array_replace($s, ['amount_total' => 4999]), 'provider_uncertain'],
            'subtotal differs from the reviewed order' => [fn (array $s): array => array_replace($s, ['amount_subtotal' => 4998]), 'provider_uncertain'],
            'paid without a complete tax calculation' => [fn (array $s): array => array_replace_recursive($s, ['automatic_tax' => ['status' => 'requires_location_inputs']]), 'provider_uncertain'],
            'line tax behavior differs' => [function (array $s): array {
                $s['line_items']['data'][0]['price']['tax_behavior'] = 'inclusive';

                return $s;
            }, 'provider_uncertain'],
            'connected-account liability' => [fn (array $s): array => array_replace_recursive($s, ['automatic_tax' => ['liability' => ['type' => 'account', 'account' => 'acct_OTHER']]]), 'provider_uncertain'],
            'discount applied' => [fn (array $s): array => array_replace_recursive($s, ['total_details' => ['amount_discount' => 1]]), 'provider_uncertain'],
        ];
    }

    #[DataProvider('inconsistentProviderSessions')]
    public function test_inconsistent_provider_sessions_are_refused_and_nothing_is_retained(\Closure $mutation, string $reason): void
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $f['transport']->mutateSession = $mutation;
        try {
            $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('An inconsistent provider session was retained.');
        } catch (CheckoutException $error) {
            $this->assertSame($reason, $error->reason);
        }
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);
        $status = $f['checkout']->status($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('unverified', $status['paymentStatus']);
        $this->assertArrayNotHasKey('reviewedAmounts', $status);
    }

    public function test_provider_tax_above_the_approved_ceiling_is_refused_never_adjusted(): void
    {
        $f = $this->taxOrder('exclusive', 800);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true; // 437 / 4999 is above the synthetic 8.00% ceiling.
        try {
            $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('Tax above the approved ceiling was retained.');
        } catch (CheckoutException $error) {
            $this->assertSame(['tax_ceiling', 409], [$error->reason, $error->status]);
        }
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);
        $f['transport']->lineTax = 399; // 399 * 10000 <= 4999 * 800
        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(399, $paid['reviewedAmounts']['tax_minor']);
    }

    public function test_assent_binds_the_previewed_terms_and_a_replayed_order_is_the_same_order(): void
    {
        $f = $this->taxOrder();
        $declarations = ['legalName' => 'Declared synthetic buyer'];
        $replay = $f['checkout']->order($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'],
            $f['preview']['previewHash'], true, $declarations, 'synthetic-tax-order');
        $this->assertSame($f['order'], $replay);
        try {
            $f['checkout']->order($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'],
                str_repeat('0', 64), true, $declarations, 'synthetic-tax-other');
            $this->fail('Assent to terms that were not previewed was accepted.');
        } catch (CheckoutException $error) {
            $this->assertSame('changed', $error->reason);
        }
        try {
            $f['checkout']->order($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'],
                $f['preview']['previewHash'], false, $declarations, 'synthetic-tax-declined');
            $this->fail('Order without affirmative assent was accepted.');
        } catch (CheckoutException $error) {
            $this->assertSame(['invalid', 422], [$error->reason, $error->status]);
        }
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['order'], 1);
    }

    public function test_withdrawn_policy_refuses_new_commands_but_keeps_retained_records(): void
    {
        $f = $this->taxOrder();
        config(['production-tax-checkout.enabled' => false]);
        foreach (['initiate', 'reconcile', 'status'] as $command) {
            try {
                $f['checkout']->{$command}($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
                $this->fail($command.' ran while disabled.');
            } catch (CheckoutException $error) {
                $this->assertSame(['disabled', 503], [$error->reason, $error->status]);
            }
        }
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['order'], 1);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['request'], 0);
        $this->assertSame([], $f['transport']->calls);
    }
}
