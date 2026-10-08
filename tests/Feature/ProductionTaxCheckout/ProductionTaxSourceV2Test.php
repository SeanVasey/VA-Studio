<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** SourceV2 round trip on an actual enrolled, paid, provider-taxed order; adapter acceptance of the produced line. */
class ProductionTaxSourceV2Test extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    private function paid(): array
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    private function read(array $f): array
    {
        $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);

        return CommandTransaction::run(function (Records $rows) use ($locator, $f): array {
            $historical = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
            $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current, $historical);
            $lines = [];
            for ($position = 1; $position <= $source->lineCount(); $position++) {
                $lines[] = $source->line($position);
            }
            $source->proveRetainedCurrent($rows->current);
            $this->assertSame(CanonicalJson::encode($lines), CanonicalJson::encode([$source->line(1)]), 'Reading twice yields identical bytes.');

            return $lines;
        });
    }

    public function test_source_v2_round_trip_carries_retained_provider_tax_beside_the_order_and_the_v2_adapter_accepts_it(): void
    {
        $f = $this->paid();
        $lines = $this->read($f);
        $this->assertCount(1, $lines);
        $line = $lines[0];
        $this->assertSame([2, 'production_tax_checkout_v2', 'synthetic_rehearsal', 'test', 'synthetic_rehearsal'],
            [$line['schema_version'], $line['producer'], $line['provenance'], $line['funds_mode'], $line['payment_evidence_origin']]);
        $this->assertSame('production_tax_checkout_v2:'.$f['order']['orderId'].':'.$line['line_id'], $line['origin_key']);
        $this->assertSame(['currency' => 'USD', 'line_amount_minor' => 4999, 'order_subtotal_minor' => 4999], $line['pre_tax']);
        $this->assertSame(['authority' => 'provider_calculated_buyer_reviewed', 'calculator' => 'stripe_checkout_automatic_tax',
            'automatic_tax' => ['enabled' => true, 'provider' => 'stripe', 'status' => 'complete'], 'tax_behavior' => 'exclusive', 'currency' => 'USD',
            'line_subtotal_minor' => 4999, 'line_tax_minor' => 437, 'line_total_minor' => 5436,
            'order_subtotal_minor' => 4999, 'order_tax_minor' => 437, 'order_total_minor' => 5436], $line['tax']);
        // Tax facts sit beside the order evidence, never inside V1 field names.
        foreach (['line_tax_minor', 'line_amount_minor', 'amounts', 'payment_id', 'payment_hash'] as $v1Field) {
            $this->assertArrayNotHasKey($v1Field, $line);
        }
        $reviewed = (array) DB::table(TaxCheckoutSchema::TABLES['reviewed'])->sole();
        $this->assertSame([$reviewed['public_id'], $reviewed['payload_hash'], 'cs_test_SYNTHETICTAX', 'pi_SYNTHETICTAX'],
            [$line['reviewed_session_id'], $line['reviewed_session_hash'], $line['provider_session_id'], $line['provider_payment_id']]);
        $source = $line;
        unset($source['source_hash']);
        $this->assertSame(CanonicalJson::hash($source), $line['source_hash']);
        $this->assertSame(CanonicalJson::encode($f['buyer']['binding']), CanonicalJson::encode($line['buyer']));
        $this->assertTrue($line['assent']['accepted']);
        $this->assertSame($f['catalog']['revision']->license_version_id, $line['license_version_id']);

        $this->assertTrue(ProductionTaxPaidLineAdapterV2::isV2($line));
        $this->inSource($f, function (ProductionTaxPaidOrderSourceV2 $source) use ($line): void {
            $this->assertSame($line, self::adapt($source, 1, $source->line(1), 'synthetic_rehearsal'));
            $this->assertSame($line, self::adapt($source, 1, $line, 'synthetic_rehearsal'), 'The line read earlier is the same bytes.');
        });
    }

    /** Runs $use with a freshly minted source inside the held transaction, the only place a source exists. */
    private function inSource(array $f, \Closure $use): void
    {
        $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);
        CommandTransaction::run(function (Records $rows) use ($locator, $f, $use): void {
            $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current,
                $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current));
            $use($source);
            $source->proveRetainedCurrent($rows->current);
        });
    }

    /** The single call site of the adapter in this class. */
    private static function adapt(ProductionTaxPaidOrderSourceV2 $source, int $position, array $line, string $provenance): array
    {
        return ProductionTaxPaidLineAdapterV2::accept($source, $position, $line, $provenance);
    }

    private static function resealed(array $line): array
    {
        unset($line['source_hash']);

        return [...$line, 'source_hash' => CanonicalJson::hash($line)];
    }

    private function assertAdapterRefuses(ProductionTaxPaidOrderSourceV2 $source, int $position, array $line, string $provenance, string $reason, string $message): void
    {
        try {
            self::adapt($source, $position, $line, $provenance);
            $this->fail($message);
        } catch (CheckoutException $error) {
            $this->assertSame([$reason, 409], [$error->reason, $error->status], $message);
        }
    }

    /** Reviewer finding R-1(a): a genuine line moved to an order that does not exist, with its tax zeroed and the hash resealed. */
    public function test_a_resealed_line_moved_to_another_order_with_zero_tax_is_refused_and_the_genuine_line_is_accepted(): void
    {
        $f = $this->paid();
        $this->inSource($f, function (ProductionTaxPaidOrderSourceV2 $source): void {
            $genuine = $source->line(1);
            $order = (string) Str::uuid();
            $forged = self::resealed([...$genuine, 'order_id' => $order, 'origin_key' => 'production_tax_checkout_v2:'.$order.':'.$genuine['line_id'],
                'tax' => [...$genuine['tax'], 'line_tax_minor' => 0, 'line_total_minor' => $genuine['tax']['line_subtotal_minor'],
                    'order_tax_minor' => 0, 'order_total_minor' => $genuine['tax']['order_subtotal_minor']]]);
            $this->assertNotSame($genuine['source_hash'], $forged['source_hash']);
            $this->assertAdapterRefuses($source, 1, $forged, 'synthetic_rehearsal', 'source_binding', 'A resealed forged line was accepted.');
            // Any single changed byte of the genuine line is refused, even when every self-check still holds.
            $this->assertAdapterRefuses($source, 1, self::resealed([...$genuine, 'observed_at' => '2026-10-07T00:00:01Z']), 'synthetic_rehearsal',
                'source_binding', 'A resealed line with another observation time was accepted.');
            $this->assertSame($genuine, self::adapt($source, 1, $genuine, 'synthetic_rehearsal'));
            // A line that does not exist in the held source has no position to bind to.
            $this->assertAdapterRefuses($source, 2, $genuine, 'synthetic_rehearsal', 'changed', 'A line was accepted at a position the source does not hold.');
        });
    }

    /** Reviewer finding R-1(b): a resealed verified_production / live / own_account_sdk line that no producer in this lane can mint. */
    public function test_a_resealed_live_line_is_refused_until_a_reviewed_live_producer_exists(): void
    {
        $f = $this->paid();
        $this->inSource($f, function (ProductionTaxPaidOrderSourceV2 $source): void {
            $genuine = $source->line(1);
            $buyer = [...$genuine['buyer'], 'provenance' => 'verified_production'];
            $forged = self::resealed([...$genuine, 'provenance' => 'verified_production', 'funds_mode' => 'live', 'payment_evidence_origin' => 'own_account_sdk',
                'provider_session_id' => 'cs_live_FORGED', 'buyer' => $buyer, 'buyer_binding_hash' => CanonicalJson::hash($buyer),
                'execution_context' => [...$genuine['execution_context'], 'funds_mode' => 'live', 'provenance' => 'verified_production']]);
            $this->assertAdapterRefuses($source, 1, $forged, 'verified_production', 'live_unsupported', 'A resealed live line was accepted.');
            $this->assertAdapterRefuses($source, 1, $genuine, 'verified_production', 'live_unsupported', 'A genuine rehearsal line was accepted as production.');
            $this->assertAdapterRefuses($source, 1, $forged, 'synthetic_rehearsal', 'source_binding', 'A live line was accepted as a rehearsal.');
        });
    }

    public function test_every_retained_tax_record_is_immutable_and_the_source_never_serializes(): void
    {
        $f = $this->paid();
        $this->read($f);
        foreach (TaxCheckoutSchema::TABLES as $table) {
            $before = DB::table($table)->get()->toJson();
            foreach ([fn () => DB::table($table)->update(['created_at' => '2026-10-07T00:00:00Z']), fn () => DB::table($table)->delete()] as $mutation) {
                try {
                    $mutation();
                    $this->fail($table.' accepted a mutation.');
                } catch (QueryException $error) {
                    $this->assertStringContainsString('Invalid or immutable production tax checkout evidence', $error->getMessage());
                }
            }
            $this->assertSame($before, DB::table($table)->get()->toJson());
        }
        $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);
        CommandTransaction::run(function (Records $rows) use ($locator, $f): void {
            $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current,
                $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current));
            foreach ([fn () => serialize($source), fn () => json_encode($source, JSON_THROW_ON_ERROR), fn () => serialize($locator)] as $leak) {
                try {
                    $leak();
                    $this->fail('A paid tax source escaped its frame.');
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->assertSame(['schema_version' => 2, 'authority' => 'retained_paid_tax_source'], $source->__debugInfo());
        });
    }

    public function test_unpaid_orders_have_no_source_and_the_v1_locator_never_reads_a_v2_order(): void
    {
        $f = $this->taxOrder();
        $locator = ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId']);
        try {
            CommandTransaction::run(fn (Records $rows) => ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current,
                $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current)));
            $this->fail('An unpaid order produced a paid source.');
        } catch (CheckoutException $error) {
            $this->assertSame('payment_required', $error->reason);
        }
        try {
            ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
            $this->fail('The frozen V1 locator read a V2 order.');
        } catch (CheckoutException) {
            $this->addToAssertionCount(1);
        }
        // A source outside a held transaction is refused before any read.
        try {
            ProductionTaxPaidOrderSourceV2::lockedRead($locator, new CurrentRows(DB::connection()->getPdo(), DB::getDriverName()), []);
            $this->fail('A source was minted in autocommit.');
        } catch (CheckoutException $error) {
            $this->assertSame('held_transaction', $error->reason);
        }
    }
}
