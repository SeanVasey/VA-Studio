<?php

namespace Tests\Feature\ProductionFinancialInspection;

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialCandidate;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialExpectation;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialInspectionException;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialInspectionPolicy;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialObservation;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Customers\ProductionCustomerAccess;
use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionCheckoutFixtures;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\ProductionCheckoutProviderFixtures;
use Tests\Support\ProductionTrackPreparationFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/** Actual local-SMTP paid source fixtures; envelopes are unattested synthetic inputs, never provider facts. */
final class FinancialCandidateTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        config(['production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'production-financial-inspection.enabled' => true]);
    }

    private function financialCatalog(bool $tax, string $capture): array
    {
        if ($tax) {
            self::configureTax();
        } else {
            ProductionCheckoutProviderFixtures::configure();
        }
        $catalog = ProductionTrackPreparationFixtures::prepared();
        $machine = $tax ? self::taxMachine() : ProductionCheckoutProviderFixtures::machine();
        $machine['choices']['provider_account']['capture_method'] = $capture;
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed(
            app(PrepareProductionTrackCapabilities::class)->review($catalog['candidate'], $catalog['source'], $machine, $catalog['actor']), $catalog['actor']);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($candidate, $catalog['reviewer']),
            ProductionTrackPreparationFixtures::reference('software_choices_reviewed'), $catalog['reviewer']);
        config(['production_checkout.exemption_authoring_enabled' => true,
            'production_checkout.exemption_policy_owner_ids' => [$catalog['actor']->id]]);

        return array_replace($catalog, compact('candidate', 'machine'));
    }

    private function paidV1(bool $two = false, string $capture = 'automatic'): array
    {
        if (! $two && $capture === 'automatic') {
            $f = $this->payable();
        } else {
            $catalog = $this->financialCatalog(false, $capture);
            $items = $two ? [...$catalog['items'], ...QuoteFixtures::selection(3499)['items']] : $catalog['items'];
            $buyer = $this->enrollThroughLocalSmtp();
            $access = new ProductionCustomerAccess;
            $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id,
                ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
            $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $items,
                ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification',
                    'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
                    'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                    'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')],
                'synthetic-qualified-buyer', $catalog['actor']);
            $checkout = new ProductionCheckout($access);
            $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items,
                $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-review');
            $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-accept');
            $gateway = new ProductionCheckoutGatewayFixture;
            $hosted = new HostedCheckout($access, $gateway);
            $f = compact('catalog', 'buyer', 'access', 'order', 'gateway', 'hosted');
        }
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);

        return $f;
    }

    private function inV1(array $f, callable $use): mixed
    {
        $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);

        return CommandTransaction::run(function (Records $rows) use ($f, $locator, $use): mixed {
            $history = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
            $source = ProductionPaidOrderSourceV1::lockedRead($locator, $rows->current, $history);

            return $use($source, $rows->current);
        });
    }

    private function expectation(): FinancialExpectation
    {
        return $this->inV1($this->paidV1(), fn ($source, $reader) => FinancialExpectation::fromV1($source, $reader));
    }

    private function envelope(FinancialExpectation $expectation): array
    {
        $identity = $expectation->identity();
        $charge = ['id' => 'ch_SYNTHETIC', 'object' => 'charge', 'payment_intent' => $identity['provider_payment_id'],
            'livemode' => false, 'currency' => 'usd', 'amount' => $identity['gross_minor'], 'amount_captured' => $identity['gross_minor'],
            'amount_refunded' => 0, 'refunded' => false, 'paid' => true, 'captured' => true, 'status' => 'succeeded', 'disputed' => false];

        return ['account_id' => $identity['provider_account'], 'payment' => ['id' => $identity['provider_payment_id'],
            'object' => 'payment_intent', 'livemode' => false, 'currency' => 'usd', 'amount' => $identity['gross_minor'],
            'amount_received' => $identity['gross_minor'], 'amount_capturable' => 0, 'status' => 'succeeded',
            'capture_method' => 'automatic', 'latest_charge' => $charge['id'], 'client_secret' => 'SYNTHETIC_PRIVATE_DO_NOT_RETAIN'],
            'charge_before' => $charge, 'charge_after' => $charge,
            'refunds' => ['object' => 'list', 'has_more' => false, 'data' => []],
            'disputes' => ['object' => 'list', 'has_more' => false, 'data' => []]];
    }

    private function item(array $raw, string $kind, string $id, string $status, int $amount): array
    {
        return ['id' => ($kind === 'refund' ? 're_' : 'du_').$id, 'object' => $kind,
            'payment_intent' => $raw['payment']['id'], 'charge' => $raw['payment']['latest_charge'], 'currency' => 'usd',
            'amount' => $amount, 'status' => $status, 'livemode' => false];
    }

    private function refuses(callable $operation, string $reason = 'attention'): void
    {
        try {
            $operation();
            $this->fail('Invalid candidate input was admitted.');
        } catch (FinancialInspectionException $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertSame('PAID_FINANCIAL_CANDIDATE_UNAVAILABLE', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_two_line_v1_uses_original_order_gross_once_and_creates_only_unattested_validation(): void
    {
        $f = $this->paidV1(true);
        $expectation = $this->inV1($f, function ($source, $reader) {
            $level = DB::transactionLevel();
            $expected = FinancialExpectation::fromV1($source, $reader);
            $this->assertSame(2, $expected->lineCount());
            $this->assertSame($source->line(1)['amounts']['total_minor'], $expected->identity()['gross_minor']);
            $this->assertSame($level, DB::transactionLevel());
            $source->proveRetainedCurrent($reader);

            return $expected;
        });
        $summary = FinancialObservation::capture($expectation, $this->envelope($expectation))->summary();
        $this->assertSame(['production_checkout_v1', 8498, 'consistent_candidate', false, 'unattested_input'],
            [$summary['originalSourceKind'], $summary['originalGrossMinor'], $summary['inputState'],
                $summary['providerAuthenticated'], $summary['inputProvenance']]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertSame(0, $summary['refundCountsByStatus']['succeeded']);
        $this->assertStringNotContainsString('SYNTHETIC', json_encode($summary, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($f['order']['orderId'], json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function test_two_line_tax_v2_preserves_buyer_reviewed_tax_gross_and_its_distinct_source_kind(): void
    {
        $f = $this->paidTaxOrder(twoLines: true);
        $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);
        $expectation = CommandTransaction::run(function (Records $rows) use ($f, $locator) {
            $history = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
            $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current, $history);
            $expectation = FinancialExpectation::fromTaxV2($source, $rows->current);
            $this->assertSame(2, $expectation->lineCount());
            $line = $source->line(1);
            $this->assertSame($line['tax']['order_total_minor'], $expectation->identity()['gross_minor']);
            $this->assertGreaterThan($line['pre_tax']['order_subtotal_minor'], $expectation->identity()['gross_minor']);
            $source->proveRetainedCurrent($rows->current);

            return $expectation;
        });
        $summary = FinancialObservation::capture($expectation, $this->envelope($expectation))->summary();
        $this->assertSame('production_tax_checkout_v2', $summary['originalSourceKind']);
        $this->assertSame(9372, $summary['originalGrossMinor']);
        $this->assertFalse($summary['providerAuthenticated']);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_original_v1_automatic_async_capture_is_admitted_and_other_capture_methods_are_refused(): void
    {
        $expected = $this->inV1($this->paidV1(capture: 'automatic_async'), fn ($source, $reader) => FinancialExpectation::fromV1($source, $reader));
        $raw = $this->envelope($expected);
        $raw['payment']['capture_method'] = 'automatic_async';
        $this->assertSame('consistent_candidate', FinancialObservation::capture($expected, $raw)->summary()['inputState']);
        $raw['payment']['capture_method'] = 'automatic';
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
    }

    public function test_original_tax_automatic_async_capture_is_admitted_and_other_capture_methods_are_refused(): void
    {
        $catalog = $this->financialCatalog(true, 'automatic_async');
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $transport = new RecordingTaxCheckoutTransport;
        $checkout = new ProductionTaxCheckout($access, $transport);
        $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);
        $order = $checkout->order($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $preview['previewHash'], true,
            ['legalName' => 'Declared synthetic buyer'], 'synthetic-tax-async');
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $checkout->initiate($buyer['principal'], $buyer['user'], $order['orderId']);
        $transport->paid = true;
        $this->assertSame('verified', $checkout->reconcile($buyer['principal'], $buyer['user'], $order['orderId'])['paymentStatus']);
        $locator = ProductionTaxPaidOrderLocatorV2::locate($order['orderId']);
        $expected = CommandTransaction::run(function (Records $rows) use ($access, $locator) {
            $history = $access->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
            $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current, $history);

            return FinancialExpectation::fromTaxV2($source, $rows->current);
        });
        $raw = $this->envelope($expected);
        $raw['payment']['capture_method'] = 'automatic_async';
        $this->assertSame('consistent_candidate', FinancialObservation::capture($expected, $raw)->summary()['inputState']);
        $raw['payment']['capture_method'] = 'automatic';
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
    }

    public function test_charge_transfer_pointer_is_refused_in_either_separate_get(): void
    {
        $expected = $this->expectation();
        foreach (['charge_before', 'charge_after'] as $key) {
            $raw = $this->envelope($expected);
            $raw[$key]['transfer'] = 'tr_SYNTHETIC';
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
        }
    }

    public function test_refund_status_sums_and_historical_disputes_remain_separate_from_charge_flags_and_aggregate(): void
    {
        $expected = $this->expectation();
        $raw = $this->envelope($expected);
        $gross = $raw['payment']['amount'];
        $raw['refunds']['data'] = [
            $this->item($raw, 'refund', 'S1', 'succeeded', 123),
            $this->item($raw, 'refund', 'P1', 'pending', $gross), $this->item($raw, 'refund', 'P2', 'pending', $gross),
            $this->item($raw, 'refund', 'F1', 'failed', $gross), $this->item($raw, 'refund', 'F2', 'failed', $gross),
            $this->item($raw, 'refund', 'C1', 'canceled', $gross), $this->item($raw, 'refund', 'A1', 'requires_action', 20),
        ];
        $raw['disputes']['data'] = [$this->item($raw, 'dispute', 'W1', 'won', $gross),
            $this->item($raw, 'dispute', 'L1', 'lost', $gross), $this->item($raw, 'dispute', 'C1', 'warning_closed', $gross)];
        $summary = FinancialObservation::capture($expected, $raw)->summary();
        $this->assertSame(['pending' => 2, 'requires_action' => 1, 'succeeded' => 1, 'failed' => 2, 'canceled' => 1], $summary['refundCountsByStatus']);
        $this->assertSame(['pending' => $gross * 2, 'requires_action' => 20, 'succeeded' => 123, 'failed' => $gross * 2, 'canceled' => $gross], $summary['refundSumsMinorByStatus']);
        $this->assertSame(0, $summary['chargeAmountRefundedMinor']);
        $this->assertFalse($summary['refundSucceededSumMatchesChargeAggregate']);
        $this->assertFalse($summary['chargeDisputedFlag']);
        $this->assertSame(3, $summary['disputeCount']);
        $this->assertSame(1, $summary['disputeCountsByStatus']['won']);
        $this->assertSame(1, $summary['disputeCountsByStatus']['lost']);
        $this->assertSame(0, $summary['disputeCountsByStatus']['under_review']);
        $raw['refunds']['data'] = array_reverse($raw['refunds']['data']);
        $raw['disputes']['data'] = array_reverse($raw['disputes']['data']);
        $raw['charge_before'] = array_reverse($raw['charge_before'], true);
        $this->assertSame($summary, FinancialObservation::capture($expected, $raw)->summary());
        // Separately read aggregates may disagree even with a complete empty list.
        $raw['refunds']['data'] = [];
        $raw['disputes']['data'] = [];
        foreach (['charge_before', 'charge_after'] as $key) {
            $raw[$key]['amount_refunded'] = $gross;
            $raw[$key]['refunded'] = true;
            $raw[$key]['disputed'] = true;
        }
        $summary = FinancialObservation::capture($expected, $raw)->summary();
        $this->assertTrue($summary['chargeRefundedFlag']);
        $this->assertTrue($summary['chargeDisputedFlag']);
        $this->assertSame(0, $summary['disputeCount']);
        $this->assertFalse($summary['refundSucceededSumMatchesChargeAggregate']);
        $raw['refunds']['data'] = [$this->item($raw, 'refund', 'FULL', 'succeeded', $gross)];
        $this->assertTrue(FinancialObservation::capture($expected, $raw)->summary()['refundSucceededSumMatchesChargeAggregate']);
        foreach (FinancialObservation::DISPUTE_STATUSES as $index => $status) {
            $raw['disputes']['data'][] = $this->item($raw, 'dispute', 'STATUS'.$index, $status, $gross);
        }
        $this->assertSame(array_fill_keys(FinancialObservation::DISPUTE_STATUSES, 1), FinancialObservation::capture($expected, $raw)->summary()['disputeCountsByStatus']);
    }

    public function test_malformed_money_subject_status_shape_and_bounded_inputs_are_refused_without_transport_or_sql(): void
    {
        $expected = $this->expectation();
        $original = $this->envelope($expected);
        $original['refunds']['data'] = [$this->item($original, 'refund', 'ONE', 'succeeded', 1)];
        $original['disputes']['data'] = [$this->item($original, 'dispute', 'ONE', 'needs_response', 1)];
        $changes = [
            [['account_id'], 'acct_OTHER'], [['payment'], null], [['payment', 'object'], 'charge'],
            [['payment', 'id'], 'pi_OTHER'], [['payment', 'livemode'], true], [['payment', 'currency'], 'USD'],
            [['payment', 'amount'], '4999'], [['payment', 'amount_received'], 0], [['payment', 'amount_capturable'], 1],
            [['payment', 'status'], 'processing'], [['payment', 'capture_method'], 'manual'], [['payment', 'latest_charge'], 'ch_/other'],
            [['payment', 'application'], 'app_OTHER'], [['context'], 'acct_OTHER'],
            [['charge_before', 'payment_intent'], 'pi_OTHER'], [['charge_before', 'livemode'], true],
            [['charge_before', 'currency'], 'eur'], [['charge_before', 'amount'], 0], [['charge_before', 'amount_captured'], 0],
            [['charge_before', 'paid'], false], [['charge_before', 'captured'], false], [['charge_before', 'status'], 'failed'],
            [['charge_before', 'amount_refunded'], -1], [['charge_before', 'amount_refunded'], PHP_INT_MAX],
            [['charge_before', 'amount_refunded'], 0.0], [['charge_before', 'refunded'], true], [['charge_before', 'disputed'], 0],
            [['charge_after', 'disputed'], true], [['charge_before', 'transfer_data'], []],
            [['refunds', 'object'], 'refund'], [['refunds', 'has_more'], 'false'], [['refunds', 'data'], [2 => []]],
            [['refunds', 'data', 0, 'id'], 'du_WRONG'], [['refunds', 'data', 0, 'object'], 'dispute'],
            [['refunds', 'data', 0, 'payment_intent'], 'pi_OTHER'], [['refunds', 'data', 0, 'charge'], 'ch_OTHER'],
            [['refunds', 'data', 0, 'currency'], 'eur'], [['refunds', 'data', 0, 'amount'], 0],
            [['refunds', 'data', 0, 'amount'], 5000], [['refunds', 'data', 0, 'status'], 'unknown'],
            [['refunds', 'data', 0, 'livemode'], true], [['refunds', 'data', 0, 'on_behalf_of'], 'acct_OTHER'],
            [['disputes', 'data', 0, 'payment_intent'], 'pi_OTHER'], [['disputes', 'data', 0, 'charge'], 'ch_OTHER'],
            [['disputes', 'data', 0, 'livemode'], null], [['disputes', 'data', 0, 'amount'], 5000],
            [['disputes', 'data', 0, 'status'], 'unknown'], [['unknown'], new ArrayObject],
            [['unknown'], INF], [['unknown'], str_repeat('x', 1048577)], [['unknown'], array_fill(0, 4097, null)],
            [['unknown'], "\xFF"],
        ];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->app->bind(StripeFinancialInspectionGateway::class, fn () => throw new LogicException('Unexpected transport resolution.'));
        foreach ($changes as [$path, $value]) {
            $raw = $original;
            $slot = &$raw;
            foreach ($path as $key) {
                $slot = &$slot[$key];
            }
            $slot = $value;
            unset($slot);
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
        }
        foreach (['refunds', 'disputes'] as $key) {
            $raw = $original;
            $raw[$key]['has_more'] = true;
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw), 'incomplete');
            $raw = $original;
            $raw[$key]['data'][] = $raw[$key]['data'][0];
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
            $raw[$key]['data'] = array_fill(0, 101, $raw[$key]['data'][0]);
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
        }
        $raw = $original;
        $raw['refunds']['data'] = [$this->item($raw, 'refund', 'A', 'succeeded', 4999), $this->item($raw, 'refund', 'B', 'succeeded', 1)];
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
        $deep = null;
        for ($i = 0; $i < 14; $i++) {
            $deep = [$deep];
        }
        $raw = [...$original, 'unknown' => $deep];
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
        $recursive = [];
        $recursive['self'] = &$recursive;
        $raw = [...$original, 'unknown' => $recursive];
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
        $spy = new class implements JsonSerializable
        {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                $this->calls++;

                return null;
            }
        };
        $raw = [...$original, 'unknown' => $spy];
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw));
        $this->assertSame(0, $spy->calls);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_default_off_nonliteral_pins_raw_object_parents_and_nonliteral_environment_never_resolve_callbacks(): void
    {
        $expected = $this->expectation();
        $raw = $this->envelope($expected);
        $original = config('production-financial-inspection');
        $shipped = require base_path('config/production-financial-inspection.php');
        $this->assertFalse($shipped['enabled']);
        foreach ([false, 1, 'true', null, new ArrayObject] as $value) {
            config(['production-financial-inspection.enabled' => $value]);
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw), 'disabled');
        }
        foreach (['purpose', 'schema_version', 'stripe_sdk_version', 'stripe_api_version'] as $key) {
            config(['production-financial-inspection' => [...$original, $key => 'wrong']]);
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw), 'disabled');
        }
        $calls = 0;
        $parent = new class($calls) extends ArrayObject
        {
            public function __construct(public int &$calls) {}

            public function offsetExists(mixed $key): bool
            {
                $this->calls++;

                return true;
            }

            public function offsetGet(mixed $key): mixed
            {
                $this->calls++;

                return true;
            }
        };
        config(['production-financial-inspection' => $parent]);
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw), 'disabled');
        $this->assertSame(0, $calls);
        config(['production-financial-inspection' => $original]);
        $configuration = $this->app['config'];
        $this->app->instance('config', new class($configuration->all()) extends Repository {});
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw), 'disabled');
        $this->app->instance('config', $configuration);
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->refuses(fn () => FinancialObservation::capture($expected, $raw), 'disabled');
        }
        $this->app->forgetInstance('env');
        $this->app->bind('env', function () use (&$calls) {
            $calls++;

            return 'testing';
        });
        $this->refuses(fn () => FinancialObservation::capture($expected, $raw), 'disabled');
        $this->assertSame(0, $calls);
        $this->app['env'] = 'testing';
        $this->assertFalse(FinancialObservation::capture($expected, $raw)->summary()['providerAuthenticated']);
    }

    public function test_v1_factory_entry_and_late_decryption_withdrawal_refuse_without_owning_frame_cleanup(): void
    {
        $f = $this->paidV1();
        $this->inV1($f, function ($source, $reader) {
            config(['production-financial-inspection.enabled' => false]);
            $this->refuses(fn () => FinancialExpectation::fromV1($source, $reader), 'disabled');
            config(['production-financial-inspection.enabled' => true]);
            $encrypter = Crypt::getFacadeRoot();
            $calls = 0;
            Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($encrypter, &$calls) {
                $calls++;
                config(['production-financial-inspection.enabled' => false]);

                return $encrypter->decryptString($cipher);
            });
            try {
                $this->refuses(fn () => FinancialExpectation::fromV1($source, $reader), 'disabled');
                $this->assertGreaterThan(0, $calls);
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue(DB::getPdo()->inTransaction());
            } finally {
                Crypt::swap($encrypter);
                config(['production-financial-inspection.enabled' => true]);
            }
            $source->proveRetainedCurrent($reader);
        });
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_tax_factory_refuses_disabled_different_reader_and_late_policy_withdrawal_in_the_original_frame(): void
    {
        $f = $this->paidTaxOrder();
        $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);
        CommandTransaction::run(function (Records $rows) use ($f, $locator) {
            $history = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
            $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current, $history);
            config(['production-financial-inspection.enabled' => false]);
            $this->refuses(fn () => FinancialExpectation::fromTaxV2($source, $rows->current), 'disabled');
            config(['production-financial-inspection.enabled' => true]);
            try {
                FinancialExpectation::fromTaxV2($source, new CurrentRows($rows->current->identityPrimary(), $rows->current->identityDriver()));
                $this->fail('A different Tax reader was accepted.');
            } catch (CheckoutException $error) {
                $this->assertSame('committed_read_frame', $error->reason);
            }
            $encrypter = Crypt::getFacadeRoot();
            $calls = 0;
            Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($encrypter, &$calls) {
                $calls++;
                config(['production-financial-inspection.enabled' => false]);

                return $encrypter->decryptString($cipher);
            });
            try {
                $this->refuses(fn () => FinancialExpectation::fromTaxV2($source, $rows->current), 'disabled');
                $this->assertGreaterThan(0, $calls);
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue(DB::getPdo()->inTransaction());
            } finally {
                Crypt::swap($encrypter);
                config(['production-financial-inspection.enabled' => true]);
            }
            $source->proveRetainedCurrent($rows->current);
        });
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_source_frame_end_and_different_reader_cannot_construct_validation_identity(): void
    {
        $f = $this->paidV1();
        [$source, $reader] = $this->inV1($f, function ($source, $reader) {
            $different = new CurrentRows(new \PDO('sqlite::memory:'), 'sqlite');
            try {
                FinancialExpectation::fromV1($source, $different);
                $this->fail('Different reader was accepted.');
            } catch (CheckoutException $error) {
                $this->assertSame('held_transaction', $error->reason);
            }

            return [$source, $reader];
        });
        try {
            FinancialExpectation::fromV1($source, $reader);
            $this->fail('Ended source frame was accepted.');
        } catch (CheckoutException $error) {
            $this->assertSame('held_transaction', $error->reason);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_original_row_withdrawal_during_extraction_cannot_return_identity(): void
    {
        $f = $this->paidV1();
        $this->inV1($f, function ($source, $reader) {
            $encrypter = Crypt::getFacadeRoot();
            $calls = 0;
            $withdrawn = false;
            Crypt::shouldReceive('decryptString')->andReturnUsing(function (string $cipher) use ($encrypter, &$calls, &$withdrawn) {
                $calls++;
                // Every source line has a private retained payment open. Withdrawal
                // can also occur in proof; either path must prevent value publication.
                if (! $withdrawn) {
                    $withdrawn = true;
                    DB::table('production_checkout_payments')->update(['outcome' => 'late']);
                }

                return $encrypter->decryptString($cipher);
            });
            try {
                FinancialExpectation::fromV1($source, $reader);
                $this->fail('Changed original payment rows were admitted.');
            } catch (CheckoutException $error) {
                $this->assertTrue($withdrawn);
                $this->assertGreaterThan(0, $calls);
                $this->assertSame(1, DB::transactionLevel());
            } finally {
                Crypt::swap($encrypter);
            }
        });
    }

    public function test_candidates_and_expectations_refuse_clone_serialize_json_and_unserialize_reconstruction(): void
    {
        $expected = $this->expectation();
        $candidate = FinancialObservation::capture($expected, $this->envelope($expected));
        $this->assertInstanceOf(FinancialCandidate::class, $candidate);
        foreach ([$expected, $candidate] as $value) {
            foreach ([fn () => clone $value, fn () => serialize($value), fn () => json_encode($value, JSON_THROW_ON_ERROR),
                fn () => unserialize('O:'.strlen($value::class).':"'.$value::class.'":0:{}', ['allowed_classes' => [$value::class]])] as $operation) {
                try {
                    $operation();
                    $this->fail('Internal value reconstruction was accepted.');
                } catch (LogicException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
        }
        config(['production-financial-inspection.enabled' => false]);
        $this->refuses(fn () => $expected->identity(), 'disabled');
        $this->refuses(fn () => $candidate->summary(), 'disabled');
        $this->refuses(fn () => FinancialInspectionPolicy::capture(), 'disabled');
    }
}
