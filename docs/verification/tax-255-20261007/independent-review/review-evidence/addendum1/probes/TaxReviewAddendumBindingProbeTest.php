<?php

namespace Tests\ReviewProbes;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\TaxExecutionContext;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/**
 * Independent reviewer probe, addendum 1 (R-1 rework at f8cc0322). Not part of the suite. A passing case means the
 * stated behaviour was observed. Every figure is synthetic.
 */
final class TaxReviewAddendumBindingProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    /** Second paid order through a fresh recording transport whose provider ids are renamed, so unique keys hold. */
    private static function renamed(RecordingTaxCheckoutTransport $inner, string $suffix): TaxCheckoutTransport
    {
        return new class($inner, $suffix) implements TaxCheckoutTransport
        {
            public function __construct(public RecordingTaxCheckoutTransport $inner, private string $suffix) {}

            public function boundTo(): string
            {
                return $this->inner->boundTo();
            }

            public function create(TaxExecutionContext $context, array $params, string $idempotencyKey): array
            {
                return ['id' => RecordingTaxCheckoutTransport::SESSION.$this->suffix] + $this->inner->create($context, $params, $idempotencyKey);
            }

            public function retrieve(TaxExecutionContext $context, string $sessionId): array
            {
                $s = $this->inner->retrieve($context, RecordingTaxCheckoutTransport::SESSION);
                $s['id'] .= $this->suffix;
                $s['url'] = $s['url'] === null ? null : $s['url'].$this->suffix;
                $s['payment_intent'] = $s['payment_intent'] === null ? null : $s['payment_intent'].$this->suffix;

                return $s;
            }

            public function paymentIntent(TaxExecutionContext $context, string $paymentId): array
            {
                $p = $this->inner->paymentIntent($context, RecordingTaxCheckoutTransport::PAYMENT);
                $p['id'] .= $this->suffix;

                return $p;
            }
        };
    }

    private function twoPaidOrders(): array
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        $second = new RecordingTaxCheckoutTransport;
        $checkout = new ProductionTaxCheckout($f['access'], self::renamed($second, 'TWO'));
        $preview = $checkout->preview($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items']);
        $orderB = $checkout->order($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'],
            $preview['previewHash'], true, ['legalName' => 'Declared synthetic buyer'], 'synthetic-tax-order-two');
        $checkout->initiate($f['buyer']['principal'], $f['buyer']['user'], $orderB['orderId']);
        $second->paid = true;
        $paidB = $checkout->reconcile($f['buyer']['principal'], $f['buyer']['user'], $orderB['orderId']);
        $this->assertSame('verified', $paidB['paymentStatus']);

        return [$f, $orderB['orderId']];
    }

    /** Runs $use inside one held frame with both sources read; returns whatever $use returns. */
    private function held(array $f, array $orderIds, Closure $use): mixed
    {
        $locators = array_map(fn (string $id) => ProductionTaxPaidOrderLocatorV2::locate($id), $orderIds);

        return CommandTransaction::run(function (Records $rows) use ($locators, $f, $use): mixed {
            $sources = [];
            foreach ($locators as $locator) {
                $historical = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
                $sources[] = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current, $historical);
            }

            return $use($sources, $rows);
        });
    }

    private static function refusal(Closure $call): string
    {
        try {
            $call();

            return 'ACCEPTED';
        } catch (CheckoutException $error) {
            return $error->reason.'/'.$error->status;
        }
    }

    private static function reseal(array $line): array
    {
        unset($line['source_hash']);

        return [...$line, 'source_hash' => CanonicalJson::hash($line)];
    }

    public function test_a1_forgeries_from_the_original_probe_are_refused_by_the_held_source_binding(): void
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $out = $this->held($f, [$f['order']['orderId']], function (array $sources): array {
            [$source] = $sources;
            $genuine = $source->line(1);
            $moved = $genuine;
            $order = (string) Str::uuid();
            $moved['order_id'] = $order;
            $moved['origin_key'] = 'production_tax_checkout_v2:'.$order.':'.$moved['line_id'];
            $moved['tax'] = ['line_tax_minor' => 0, 'line_total_minor' => $moved['tax']['line_subtotal_minor'],
                'order_tax_minor' => 0, 'order_total_minor' => $moved['tax']['order_subtotal_minor']] + $moved['tax'];
            $moved = self::reseal($moved);
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

            return [
                'genuine' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($source, 1, $genuine, 'synthetic_rehearsal')),
                'moved, zero tax, resealed' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($source, 1, $moved, 'synthetic_rehearsal')),
                'live forgery as verified_production' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($source, 1, $live, 'verified_production')),
                'live forgery as rehearsal' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($source, 1, $live, 'synthetic_rehearsal')),
                'genuine at position 2' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($source, 2, $genuine, 'synthetic_rehearsal')),
                // assertSelfConsistent is public and is not authentication: the resealed forgery passes it.
                'assertSelfConsistent(moved)' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::assertSelfConsistent($moved, 'synthetic_rehearsal')),
            ];
        });
        fwrite(STDERR, PHP_EOL.'A1 '.json_encode($out).PHP_EOL);
        $this->assertSame(['genuine' => 'ACCEPTED', 'moved, zero tax, resealed' => 'source_binding/409',
            'live forgery as verified_production' => 'live_unsupported/409', 'live forgery as rehearsal' => 'source_binding/409',
            'genuine at position 2' => 'changed/409', 'assertSelfConsistent(moved)' => 'ACCEPTED'], $out);
    }

    public function test_a1_the_adapter_binds_line_to_source_but_not_to_the_order_the_caller_intends(): void
    {
        [$f, $orderB] = $this->twoPaidOrders();
        $orderA = $f['order']['orderId'];
        $out = $this->held($f, [$orderA, $orderB], function (array $sources): array {
            [$a, $b] = $sources;
            $lineA = $a->line(1);
            $lineB = $b->line(1);

            return [
                'accept(A, lineB)' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($a, 1, $lineB, 'synthetic_rehearsal')),
                'accept(B, lineA)' => self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($b, 1, $lineA, 'synthetic_rehearsal')),
                // The adapter returns A's line whatever order the caller means to grant; only the returned line says which.
                'accept(A, lineA) order_id' => ProductionTaxPaidLineAdapterV2::accept($a, 1, $lineA, 'synthetic_rehearsal')['order_id'],
            ];
        });
        fwrite(STDERR, PHP_EOL.'A2 '.json_encode($out).PHP_EOL);
        $this->assertSame(['accept(A, lineB)' => 'source_binding/409', 'accept(B, lineA)' => 'source_binding/409', 'accept(A, lineA) order_id' => $orderA], $out);
    }

    public function test_a1_a_source_that_outlived_its_held_frame_is_still_accepted(): void
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        [$source, $line] = $this->held($f, [$f['order']['orderId']], fn (array $sources): array => [$sources[0], $sources[0]->line(1)]);
        // The frame has committed: no transaction is open, so the source is no longer held.
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('ACCEPTED', self::refusal(fn () => ProductionTaxPaidLineAdapterV2::accept($source, 1, $line, 'synthetic_rehearsal')));
        // Only an explicit proveRetainedCurrent by the caller would notice (it needs the caller's reader).
        fwrite(STDERR, PHP_EOL.'A3 stale source accepted outside any transaction'.PHP_EOL);
    }
}
