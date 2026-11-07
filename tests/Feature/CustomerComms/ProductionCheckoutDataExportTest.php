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
        $this->assertSame($fixture['order']['orderId'], $order['id']);
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

        // Sealed rows filed under this buyer's origin whose own binding names another origin or another account, then one whose
        // seal is broken. The immutability triggers are dropped only inside this test's database, to plant rows the application
        // never writes.
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
        $otherAccount = [...$body, 'public_id' => '00000000-0000-4000-8000-000000000002', 'buyer' => [...$body['buyer'], 'account_id' => $body['buyer']['account_id'] + 1]];
        DB::table('production_tax_checkout_orders')->insert([...$row, 'id' => $row['id'] + 2, 'public_id' => $otherAccount['public_id'],
            'request_key' => str_repeat('c', 64), ...Evidence::seal($otherAccount)]);
        $this->refusesExport(self::account($fixture['buyer']));

        DB::table('production_tax_checkout_orders')->where('id', $row['id'] + 2)->delete();
        DB::table('production_tax_checkout_orders')->where('id', $row['id'])->update(['payload_hash' => str_repeat('0', 64)]);
        $this->refusesExport(self::account($fixture['buyer']));
    }

    private function refusesExport(int $account, int $status = 503, string $reason = 'production_record_unavailable'): void
    {
        try {
            app(ProductionCheckoutExportSection::class)->build($account);
            $this->fail('Expected the export to refuse');
        } catch (DataRightsException $e) {
            $this->assertSame([$status, $reason], [$e->status, $e->reason]);
        }
    }

    public function test_more_records_than_the_cap_refuse_before_any_payload_is_opened(): void
    {
        $fixture = $this->taxOrder();
        $row = (array) DB::table('production_tax_checkout_orders')->first();
        foreach (DB::getDriverName() === 'mysql'
            ? array_column(DB::select("SHOW TRIGGERS WHERE `Table` = 'production_tax_checkout_orders'"), 'Trigger')
            : array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'production_tax_checkout_orders'"), 'name') as $trigger) {
            DB::statement('DROP TRIGGER '.$trigger);
        }
        // Padding rows carry unreadable payloads: the cap must refuse on the count alone, before any of them is opened.
        $padding = [];
        for ($i = 1; $i <= ProductionCheckoutExportSection::MAX_RECORDS; $i++) {
            $padding[] = [...$row, 'id' => $row['id'] + $i, 'public_id' => sprintf('00000000-0000-4000-8000-%012d', $i),
                'request_key' => hash('sha256', 'pad'.$i), 'payload_ciphertext' => 'x', 'payload_hash' => str_repeat('0', 64)];
        }
        foreach (array_chunk($padding, 100) as $chunk) {
            DB::table('production_tax_checkout_orders')->insert($chunk);
        }
        $this->refusesExport(self::account($fixture['buyer']), 413, 'export_too_large');
    }

    public function test_a_missing_production_table_refuses_instead_of_reporting_no_records(): void
    {
        $fixture = $this->taxOrder();
        DB::getSchemaBuilder()->drop('production_tax_checkout_bindings');
        DB::getSchemaBuilder()->drop('production_tax_checkout_reviewed_sessions');
        DB::getSchemaBuilder()->drop('production_tax_checkout_requests');
        DB::getSchemaBuilder()->drop('production_tax_checkout_orders');
        $this->refusesExport(self::account($fixture['buyer']));
        try {
            ProductionCheckoutExportSection::counts(self::account($fixture['buyer']));
            $this->fail('Expected the counts to refuse');
        } catch (DataRightsException $e) {
            $this->assertSame([503, 'production_record_unavailable'], [$e->status, $e->reason]);
        }
    }

    public function test_the_export_document_carries_the_production_sections(): void
    {
        $fixture = $this->taxOrder();
        $user = $fixture['buyer']['user']->fresh();
        $account = \App\Domain\Customers\Models\CustomerAccount::findOrFail(self::account($fixture['buyer']));
        // The data-rights entry point still admits only test-account sessions (D-30 step 3b), so the document is built directly.
        config(['customer.test_accounts_enabled' => true]);
        $principal = app(\App\Domain\Customers\CustomerAccess::class)->principal($user);
        $document = app(\App\Domain\Customers\DataRights\CustomerDataExportDocument::class)
            ->build($principal, $user, $account, '00000000-0000-4000-8000-0000000000aa', \Carbon\CarbonImmutable::now('UTC'));

        $this->assertSame(2, $document['exportSchema']);
        $this->assertSame([$fixture['order']['orderId']], array_column($document['productionTaxCheckoutOrders'], 'id'));
        $this->assertSame([[], []], [$document['productionCheckoutReviews'], $document['productionCheckoutOrders']]);
    }

    public function test_deletion_keeps_every_production_checkout_record_that_holds_buyer_data(): void
    {
        foreach (array_keys(ProductionCheckoutExportSection::RECORDS) as $table) {
            $this->assertSame('financial_records_years', CustomerDataRights::RETAINED[$table] ?? null, $table);
        }
    }
}
