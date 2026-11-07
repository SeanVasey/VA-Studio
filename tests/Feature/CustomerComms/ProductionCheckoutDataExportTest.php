<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\ProductionCheckoutExportSection;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** D-30 step 3: production checkout records and their buyer declarations enter the requester's export and retained list. */
class ProductionCheckoutDataExportTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture, ProductionTaxCheckoutFixtures {
        ProductionCheckoutJourneyFixture::identitySetup insteadof ProductionTaxCheckoutFixtures;
        ProductionCheckoutJourneyFixture::enrollThroughLocalSmtp insteadof ProductionTaxCheckoutFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('h', 32))]);
        $this->identitySetup();
        config(['production_checkout.fresh_checkout_enabled' => true]);
    }

    private static function account(array $buyer): int
    {
        return (int) $buyer['principal']->durableBinding()['account_id'];
    }

    public function test_tax_checkout_orders_export_with_their_declarations_for_their_buyer_only(): void
    {
        $fixture = $this->taxOrder();
        $other = $this->enrollThroughLocalSmtp('other.buyer@example.test');
        $account = self::account($fixture['buyer']);

        $sections = app(ProductionCheckoutExportSection::class)->build($account);
        $this->assertSame([], $sections['productionCheckoutReviews']);
        $this->assertSame([], $sections['productionCheckoutOrders']);
        $this->assertCount(1, $sections['productionTaxCheckoutOrders']);
        $order = $sections['productionTaxCheckoutOrders'][0];
        $this->assertSame($fixture['order']['orderId'] ?? DB::table('production_tax_checkout_orders')->value('public_id'), $order['id']);
        $this->assertEquals(['legal_name' => 'Declared synthetic buyer', 'identity_meaning' => 'buyer_declared_legal_name'], $order['buyerDeclarations']);
        $this->assertSame('USD', $order['currency']);
        $this->assertSame((int) DB::table('production_tax_checkout_orders')->value('subtotal_minor'), $order['subtotalMinor']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $order['createdAt']);
        $this->assertSame(['production_checkout_reviews' => 0, 'production_checkout_orders' => 0, 'production_tax_checkout_orders' => 1],
            ProductionCheckoutExportSection::counts($account));

        // Another enrolled buyer, and an account with no production identity origin, see nothing of it.
        $empty = ['productionCheckoutReviews' => [], 'productionCheckoutOrders' => [], 'productionTaxCheckoutOrders' => []];
        $this->assertSame($empty, app(ProductionCheckoutExportSection::class)->build(self::account($other)));
        $this->assertSame($empty, app(ProductionCheckoutExportSection::class)->build(PHP_INT_MAX));
        $this->assertSame(0, array_sum(ProductionCheckoutExportSection::counts(self::account($other))));
    }

    public function test_checkout_reviews_and_orders_export_with_their_declarations(): void
    {
        $fixture = $this->payable();
        $account = self::account($fixture['buyer']);
        $declarations = ['legal_name' => 'Declared synthetic buyer', 'identity_meaning' => 'buyer_declared_legal_name'];

        $sections = app(ProductionCheckoutExportSection::class)->build($account);
        $this->assertSame([$fixture['review']['reviewId']], array_column($sections['productionCheckoutReviews'], 'id'));
        $this->assertEquals($declarations, $sections['productionCheckoutReviews'][0]['buyerDeclarations']);
        $this->assertSame([DB::table('production_checkout_orders')->value('public_id')], array_column($sections['productionCheckoutOrders'], 'id'));
        $this->assertEquals($declarations, $sections['productionCheckoutOrders'][0]['buyerDeclarations']);
        $this->assertSame((int) DB::table('production_checkout_orders')->value('total_minor'), $sections['productionCheckoutOrders'][0]['totalMinor']);
        $this->assertSame([], $sections['productionTaxCheckoutOrders']);
        $this->assertSame(['production_checkout_reviews' => 1, 'production_checkout_orders' => 1, 'production_tax_checkout_orders' => 0],
            ProductionCheckoutExportSection::counts($account));
    }

    public function test_a_row_whose_seal_or_buyer_binding_does_not_match_refuses_the_export(): void
    {
        $fixture = $this->taxOrder();
        $origin = $fixture['buyer']['principal']->durableBinding()['origin_id'];
        $row = (array) DB::table('production_tax_checkout_orders')->first();
        $body = Evidence::open($row, 'production_tax_checkout_order');

        // A sealed row filed under this buyer's origin whose own binding names someone else, then one whose seal is broken.
        // The immutability triggers are dropped only inside this test's database, to plant rows the application never writes.
        $triggers = DB::getDriverName() === 'mysql'
            ? array_column(DB::select("SHOW TRIGGERS WHERE `Table` = 'production_tax_checkout_orders'"), 'Trigger')
            : array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'production_tax_checkout_orders'"), 'name');
        $this->assertNotSame([], $triggers);
        foreach ($triggers as $trigger) {
            DB::statement('DROP TRIGGER '.$trigger);
        }
        $misfiled = [...$body, 'public_id' => '00000000-0000-4000-8000-000000000001', 'buyer' => [...$body['buyer'], 'origin_id' => '00000000-0000-4000-8000-0000000000ff']];
        DB::table('production_tax_checkout_orders')->insert([...$row, 'id' => $row['id'] + 1, 'public_id' => $misfiled['public_id'],
            'request_key' => str_repeat('b', 64), ...Evidence::seal($misfiled)]);
        $this->assertSame($origin, DB::table('production_tax_checkout_orders')->where('id', $row['id'] + 1)->value('buyer_origin_id'));
        $this->refusesExport(self::account($fixture['buyer']));

        DB::table('production_tax_checkout_orders')->where('id', $row['id'] + 1)->delete();
        DB::table('production_tax_checkout_orders')->where('id', $row['id'])->update(['payload_hash' => str_repeat('0', 64)]);
        $this->refusesExport(self::account($fixture['buyer']));
    }

    private function refusesExport(int $account): void
    {
        try {
            app(ProductionCheckoutExportSection::class)->build($account);
            $this->fail('Expected the export to refuse');
        } catch (DataRightsException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('production_record_unavailable', $e->reason);
        }
    }

    public function test_deletion_keeps_every_production_checkout_record_that_holds_buyer_data(): void
    {
        foreach (array_keys(ProductionCheckoutExportSection::RECORDS) as $table) {
            $this->assertSame('financial_records_years', CustomerDataRights::RETAINED[$table] ?? null, $table);
        }
    }
}
