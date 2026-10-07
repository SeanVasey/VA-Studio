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
        $this->assertSame($line, ProductionTaxPaidLineAdapterV2::accept($line, 'synthetic_rehearsal'));
        try {
            ProductionTaxPaidLineAdapterV2::accept($line, 'verified_production');
            $this->fail('A rehearsal line was accepted as production.');
        } catch (CheckoutException $error) {
            $this->assertSame(409, $error->status);
        }
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
