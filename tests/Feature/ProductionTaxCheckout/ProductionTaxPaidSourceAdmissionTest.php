<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** Actual retained synthetic paid order: consumer admission requires its original held frame and target. */
class ProductionTaxPaidSourceAdmissionTest extends TestCase
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

    private function paid(): array
    {
        $fixture = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $fixture['checkout']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
        $fixture['transport']->paid = true;
        $fixture['checkout']->reconcile($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);

        return $fixture;
    }

    private function source(array $fixture, Records $rows, ProductionTaxPaidOrderLocatorV2 $locator): ProductionTaxPaidOrderSourceV2
    {
        return ProductionTaxPaidOrderSourceV2::lockedRead($locator, $rows->current,
            $fixture['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current));
    }

    private function refuses(ProductionTaxPaidOrderSourceV2 $source, CurrentRows $reader, string $order, string $reason): void
    {
        try {
            ProductionTaxPaidLineAdapterV2::accept($source, 1, $source->line(1), 'synthetic_rehearsal', $reader, $order);
            $this->fail('The V2 adapter admitted a source outside its held frame or expected order.');
        } catch (CheckoutException $error) {
            $this->assertSame([$reason, 409], [$error->reason, $error->status]);
        }
    }

    public function test_a_source_from_a_closed_transaction_cannot_be_accepted_in_autocommit(): void
    {
        $fixture = $this->paid();
        $locator = ProductionTaxPaidOrderLocatorV2::locate($fixture['order']['orderId']);
        [$source, $reader] = CommandTransaction::run(fn (Records $rows): array => [$this->source($fixture, $rows, $locator), $rows->current]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $before = DB::table(TaxCheckoutSchema::TABLES['reviewed'])->get()->toJson();
        $this->refuses($source, $reader, $fixture['order']['orderId'], 'held_transaction');
        $this->assertSame($before, DB::table(TaxCheckoutSchema::TABLES['reviewed'])->get()->toJson());
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_a_fresh_transaction_cannot_reuse_a_source_from_the_closed_frame(): void
    {
        $fixture = $this->paid();
        $locator = ProductionTaxPaidOrderLocatorV2::locate($fixture['order']['orderId']);
        $source = CommandTransaction::run(fn (Records $rows) => $this->source($fixture, $rows, $locator));
        CommandTransaction::run(function (Records $rows) use ($source, $fixture): void {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->refuses($source, $rows->current, $fixture['order']['orderId'], 'held_transaction');
        });
        $this->assertDatabaseCount('paid_order_origins', 0);
    }

    public function test_the_current_source_is_bound_to_the_callers_expected_order(): void
    {
        $fixture = $this->paid();
        $locator = ProductionTaxPaidOrderLocatorV2::locate($fixture['order']['orderId']);
        CommandTransaction::run(function (Records $rows) use ($fixture, $locator): void {
            $source = $this->source($fixture, $rows, $locator);
            $line = $source->line(1);
            $this->refuses($source, $rows->current, (string) Str::uuid(), 'source_order');
            $this->assertSame($line, ProductionTaxPaidLineAdapterV2::accept($source, 1, $line, 'synthetic_rehearsal',
                $rows->current, $fixture['order']['orderId']));
        });
        $this->assertDatabaseCount('paid_order_origins', 0);
    }

    public function test_a_current_source_cannot_be_accepted_with_another_primary_reader_or_nested_frame(): void
    {
        $fixture = $this->paid();
        $locator = ProductionTaxPaidOrderLocatorV2::locate($fixture['order']['orderId']);
        CommandTransaction::run(function (Records $rows) use ($fixture, $locator): void {
            $source = $this->source($fixture, $rows, $locator);
            $other = new CurrentRows(new PDO('sqlite::memory:'), 'sqlite');
            $this->refuses($source, $other, $fixture['order']['orderId'], 'held_transaction');
            DB::transaction(function () use ($source, $rows, $fixture): void {
                $this->assertSame(2, DB::transactionLevel());
                $this->refuses($source, $rows->current, $fixture['order']['orderId'], 'held_transaction');
            });
            $this->assertSame($source->line(1), ProductionTaxPaidLineAdapterV2::accept($source, 1, $source->line(1),
                'synthetic_rehearsal', $rows->current, $fixture['order']['orderId']));
        });
        $this->assertDatabaseCount('paid_order_origins', 0);
    }
}
