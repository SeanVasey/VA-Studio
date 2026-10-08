<?php

namespace Tests\ReviewProbes;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\TaxExecutionContext;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/**
 * Independent reviewer probe (question 1). Not part of the suite. Each case documents the ACTUAL behaviour at
 * 9ec94d8c: a passing case means the stated behaviour was observed. Fixture figures are synthetic.
 */
final class TaxReviewMoneyProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    /** Wraps the lane's recording transport so the PaymentIntent can be mutated too. */
    private static function wrap(RecordingTaxCheckoutTransport $inner, ?Closure $payment): TaxCheckoutTransport
    {
        return new class($inner, $payment) implements TaxCheckoutTransport
        {
            public function __construct(public RecordingTaxCheckoutTransport $inner, public ?Closure $payment) {}

            public function boundTo(): string
            {
                return $this->inner->boundTo();
            }

            public function create(TaxExecutionContext $context, array $params, string $idempotencyKey): array
            {
                return $this->inner->create($context, $params, $idempotencyKey);
            }

            public function retrieve(TaxExecutionContext $context, string $sessionId): array
            {
                return $this->inner->retrieve($context, $sessionId);
            }

            public function paymentIntent(TaxExecutionContext $context, string $paymentId): array
            {
                $intent = $this->inner->paymentIntent($context, $paymentId);

                return $this->payment === null ? $intent : ($this->payment)($intent);
            }
        };
    }

    private function bound(string $behavior = 'exclusive', int $ceiling = 2500, ?Closure $payment = null): array
    {
        $f = $this->taxOrder($behavior, $ceiling);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout'] = new ProductionTaxCheckout($f['access'], self::wrap($f['transport'], $payment));
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    private function reconcileRefused(array $f): string
    {
        try {
            $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        } catch (CheckoutException $error) {
            $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);

            return $error->reason.'/'.$error->status;
        }
        $this->fail('reconcile accepted');
    }

    public static function refusedProviderFacts(): array
    {
        $session = fn (Closure $m): array => [$m, null];
        $payment = fn (Closure $m): array => [null, $m];

        return [
            'PI status processing while session paid' => [...$payment(fn (array $p): array => ['status' => 'processing', 'amount_received' => 0] + $p), 'provider_uncertain/503'],
            'PI requires_capture while session paid' => [...$payment(fn (array $p): array => ['status' => 'requires_capture', 'amount_received' => 0, 'amount_capturable' => $p['amount']] + $p), 'provider_uncertain/503'],
            'PI amount differs from session total' => [...$payment(fn (array $p): array => ['amount' => $p['amount'] + 1] + $p), 'provider_uncertain/503'],
            'PI succeeded but amount_received short' => [...$payment(fn (array $p): array => ['amount_received' => $p['amount'] - 1] + $p), 'provider_uncertain/503'],
            'PI currency eur' => [...$payment(fn (array $p): array => ['currency' => 'eur'] + $p), 'provider_uncertain/503'],
            'PI livemode true' => [...$payment(fn (array $p): array => ['livemode' => true] + $p), 'provider_uncertain/503'],
            'PI metadata from another request' => [...$payment(function (array $p): array {
                $p['metadata']['request_id'] = (string) Str::uuid();

                return $p;
            }), 'provider_uncertain/503'],
            'PI on_behalf_of connected account' => [...$payment(fn (array $p): array => ['on_behalf_of' => 'acct_OTHER'] + $p), 'provider_uncertain/503'],
            'session currency eur' => [...$session(fn (array $s): array => ['currency' => 'eur'] + $s), 'provider_uncertain/503'],
            'line currency eur' => [...$session(function (array $s): array {
                $s['line_items']['data'][0]['currency'] = 'eur';

                return $s;
            }), 'provider_uncertain/503'],
            'exclusive request but inclusive-shaped session totals' => [...$session(function (array $s): array {
                $s['amount_total'] = $s['amount_subtotal'];
                $s['line_items']['data'][0]['amount_total'] = $s['line_items']['data'][0]['amount_subtotal'];

                return $s;
            }), 'provider_uncertain/503'],
            'automatic_tax.status failed while paid' => [...$session(fn (array $s): array => array_replace_recursive($s, ['automatic_tax' => ['status' => 'failed']])), 'provider_uncertain/503'],
            'automatic_tax.status null while paid' => [...$session(function (array $s): array {
                $s['automatic_tax']['status'] = null;

                return $s;
            }), 'provider_uncertain/503'],
            'session livemode true' => [...$session(fn (array $s): array => ['livemode' => true] + $s), 'provider_uncertain/503'],
            'session for another order (client_reference_id)' => [...$session(fn (array $s): array => ['client_reference_id' => (string) Str::uuid()] + $s), 'provider_uncertain/503'],
            'line unit_amount raised and subtotal kept' => [...$session(function (array $s): array {
                $s['line_items']['data'][0]['price']['unit_amount'] += 1;

                return $s;
            }), 'provider_uncertain/503'],
            'negative line tax offset' => [...$session(function (array $s): array {
                $s['line_items']['data'][0]['amount_tax'] = -1;

                return $s;
            }), 'provider_uncertain/503'],
            'string tax figure' => [...$session(fn (array $s): array => array_replace_recursive($s, ['total_details' => ['amount_tax' => '437']])), 'provider_uncertain/503'],
        ];
    }

    #[DataProvider('refusedProviderFacts')]
    public function test_q1_provider_facts_that_must_not_be_retained(?Closure $session, ?Closure $payment, string $expected): void
    {
        $f = $this->bound(payment: $payment);
        $f['transport']->paid = true;
        $f['transport']->mutateSession = $session;
        $this->assertSame($expected, $this->reconcileRefused($f));
        $this->assertSame('unverified', $f['checkout']->status($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
    }

    public function test_q1_session_complete_but_unpaid_with_processing_intent_is_pending_not_retained(): void
    {
        $f = $this->bound(payment: fn (array $p): array => ['status' => 'processing', 'amount_received' => 0] + $p);
        $f['transport']->paid = true;
        $f['transport']->mutateSession = fn (array $s): array => ['payment_status' => 'unpaid'] + $s;
        $result = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(['complete', 'unverified'], [$result['checkoutStatus'], $result['paymentStatus']]);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);
    }

    public function test_q1_expired_session_retains_nothing(): void
    {
        $f = $this->bound();
        $f['transport']->mutateSession = fn (array $s): array => ['status' => 'expired', 'url' => null] + $s;
        $result = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame(['expired', 'unverified'], [$result['checkoutStatus'], $result['paymentStatus']]);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);
    }

    public function test_q1_inclusive_ceiling_binds_on_the_reviewed_session(): void
    {
        // 437 inside 4999 inclusive: net 4562; 437 * 10000 > 4562 * 800.
        $f = $this->bound('inclusive', 800);
        $f['transport']->paid = true;
        $this->assertSame('tax_ceiling/409', $this->reconcileRefused($f));
        $f['transport']->lineTax = 337; // 337 * 10000 = 3370000 <= (4999 - 337) * 800 = 3729600
        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame([4999, 337, 4999], [$paid['reviewedAmounts']['subtotal_minor'], $paid['reviewedAmounts']['tax_minor'], $paid['reviewedAmounts']['total_minor']]);
    }

    public function test_q1_ceiling_bound_at_initiate_too(): void
    {
        // An open session whose provider tax already breaches the ceiling refuses at initiate (not only at reconcile).
        $f = $this->taxOrder('exclusive', 800);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['transport']->mutateSession = function (array $s): array {
            $s['line_items']['data'][0]['amount_tax'] = 437;
            $s['line_items']['data'][0]['amount_total'] += 437;
            $s['total_details']['amount_tax'] = 437;
            $s['amount_total'] += 437;

            return $s;
        };
        try {
            $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('initiate accepted');
        } catch (CheckoutException $error) {
            $this->assertSame(['tax_ceiling', 409], [$error->reason, $error->status]);
        }
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['binding'], 0);
        $this->assertSame(800, DB::table(TaxCheckoutSchema::TABLES['request'])->value('maximum_rate_bps'));
    }

    public function test_q1_retained_review_is_never_replaced_by_a_later_retrieve(): void
    {
        $f = $this->bound();
        $f['transport']->paid = true;
        $first = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $rows = DB::table(TaxCheckoutSchema::TABLES['reviewed'])->get()->toJson();
        $calls = count($f['transport']->calls);
        $f['transport']->lineTax = 1; // a later provider read would disagree
        foreach (['reconcile', 'initiate', 'status'] as $command) {
            $again = $f['checkout']->{$command}($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->assertSame($first['reviewedAmounts'], $again['reviewedAmounts']);
        }
        $this->assertSame($calls, count($f['transport']->calls));
        $this->assertSame($rows, DB::table(TaxCheckoutSchema::TABLES['reviewed'])->get()->toJson());
    }

    public function test_q1_initiate_does_not_compare_the_retrieved_session_id_with_the_created_locator(): void
    {
        // Observation: initiate validates the retrieved session against the retained request (metadata, client
        // reference, amounts) but not that its id equals the id `create` returned; it then binds the created id.
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['transport']->mutateSession = fn (array $s): array => ['id' => 'cs_test_OTHERSESSION', 'url' => $s['url'] === null ? null : 'https://checkout.stripe.com/c/pay/cs_test_OTHERSESSION'] + $s;
        $open = $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_OTHERSESSION', $open['checkoutUrl']);
        $this->assertSame(RecordingTaxCheckoutTransport::SESSION, DB::table(TaxCheckoutSchema::TABLES['binding'])->value('provider_session_id'));
        // Reconcile does compare: a paid session whose id differs from the binding is not retained.
        $f['transport']->paid = true;
        try {
            $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('reconcile retained a session that is not the bound one');
        } catch (CheckoutException $error) {
            // Refused outside the provider try block by retain(): binding id !== retrieved id (default reason, 409).
            $this->assertSame(['changed', 409], [$error->reason, $error->status]);
        }
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 0);
    }

    private function genuineLine(array $f): array
    {
        $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);

        return CommandTransaction::run(function (Records $rows) use ($locator, $f): array {
            $historical = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);

            return ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current, $historical)->line(1);
        });
    }

    private static function reseal(array $line): array
    {
        unset($line['source_hash']);

        return [...$line, 'source_hash' => CanonicalJson::hash($line)];
    }

    public function test_q1_forged_lines_with_a_consistent_self_hash_are_accepted_by_the_adapter(): void
    {
        $f = $this->bound();
        $f['transport']->paid = true;
        $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $genuine = $this->genuineLine($f);
        $this->assertSame($genuine, ProductionTaxPaidLineAdapterV2::accept($genuine, 'synthetic_rehearsal'));

        // Control: an edit without resealing is refused.
        $edited = $genuine;
        $edited['tax']['order_tax_minor'] = 0;
        try {
            ProductionTaxPaidLineAdapterV2::accept($edited, 'synthetic_rehearsal');
            $this->fail('unsealed edit accepted');
        } catch (CheckoutException $error) {
            $this->assertSame('source_hash', $error->reason);
        }

        // Forgery A: a different (nonexistent) order and zero tax, resealed. Accepted.
        $order = (string) Str::uuid();
        $forged = $genuine;
        $forged['order_id'] = $order;
        $forged['origin_key'] = 'production_tax_checkout_v2:'.$order.':'.$forged['line_id'];
        $forged['tax'] = ['line_tax_minor' => 0, 'line_total_minor' => $forged['tax']['line_subtotal_minor'],
            'order_tax_minor' => 0, 'order_total_minor' => $forged['tax']['order_subtotal_minor']] + $forged['tax'];
        $forged = self::reseal($forged);
        $this->assertSame($forged, ProductionTaxPaidLineAdapterV2::accept($forged, 'synthetic_rehearsal'));
        $this->assertSame(0, DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $order)->count());

        // Forgery B: a verified_production / live / own_account_sdk line that no producer in this lane can mint. Accepted.
        $live = $genuine;
        $live['provenance'] = 'verified_production';
        $live['funds_mode'] = 'live';
        $live['payment_evidence_origin'] = 'own_account_sdk';
        $live['buyer']['provenance'] = 'verified_production';
        $live['buyer_binding_hash'] = CanonicalJson::hash($live['buyer']);
        $live['execution_context']['funds_mode'] = 'live';
        $live['execution_context']['provenance'] = 'verified_production';
        $live['provider_session_id'] = 'cs_live_FORGED';
        $live = self::reseal($live);
        $this->assertSame($live, ProductionTaxPaidLineAdapterV2::accept($live, 'verified_production'));
    }
}
