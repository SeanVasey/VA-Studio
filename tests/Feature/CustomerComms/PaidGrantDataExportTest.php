<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\DataRights\CustomerDataExportDocument;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\PaidGrantExportSection;
use App\Domain\Customers\DataRights\ProductionCheckoutExportSection;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * D-30 before activation: the paid-grant copies of the buyer declarations (V1 paid and V2 paid-tax chains, local and testing
 * only) and the buyer's exemption attestation enter the requester's export and the deletion's retained list.
 */
class PaidGrantDataExportTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use PaidTaxGrantFixtures, ProductionCheckoutJourneyFixture {
        ProductionCheckoutJourneyFixture::identitySetup insteadof PaidTaxGrantFixtures;
        ProductionCheckoutJourneyFixture::enrollThroughLocalSmtp insteadof PaidTaxGrantFixtures;
    }

    private const DECLARATIONS = ['legal_name' => 'Declared synthetic buyer', 'identity_meaning' => 'buyer_declared_legal_name'];

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        config(['production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
    }

    private static function account(array $buyer): int
    {
        return (int) $buyer['principal']->durableBinding()['account_id'];
    }

    /** A V1 paid order with its grant origin and rendered original, through the genuine chain. */
    private function paidV1(): array
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['batch'] = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertTrue((new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['fulfilled']);

        return $f;
    }

    /** A V2 paid-tax order with its grant origin; its original is rendered only when asked. */
    private function paidTax(bool $original = false): array
    {
        $f = $this->paidTaxOrder();
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        if ($original) {
            $this->assertTrue((new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['fulfilled']);
        }

        return $f;
    }

    public function test_v1_paid_copies_original_and_exemption_basis_export_for_their_buyer_only(): void
    {
        $f = $this->paidV1();
        $other = $this->enrollThroughLocalSmtp('other.buyer@example.test');
        $account = self::account($f['buyer']);

        $sections = app(PaidGrantExportSection::class)->build($account);
        $this->assertSame([], $sections['paidTaxGrantOrders']);
        $this->assertCount(1, $sections['paidGrantOrders']);
        $batch = $sections['paidGrantOrders'][0];
        $this->assertSame([$f['batch']['id'], $f['order']['orderId']], [$batch['id'], $batch['orderId']]);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $batch['createdAt']);
        $this->assertCount(1, $batch['lines']);
        $line = $batch['lines'][0];
        $this->assertSame([$f['batch']['lines'][0]['id'], 1], [$line['id'], $line['position']]);
        $this->assertEquals(self::DECLARATIONS, $line['buyerDeclarations']);
        $manifest = PaidGrantRecords::decode((array) DB::table('paid_originals')->sole());
        $this->assertSame([$manifest['artifact']['pdf_hash'], $manifest['artifact']['size_bytes']],
            [$line['contractOriginal']['sha256'], $line['contractOriginal']['sizeBytes']]);
        $this->assertSame(['paid_order_origins' => 1, 'paid_grant_origins' => 1, 'paid_originals' => 1,
            'paid_tax_order_origins' => 0, 'paid_tax_grant_origins' => 0, 'paid_tax_originals' => 0], PaidGrantExportSection::counts($account));

        // The buyer's exemption attestation, opened from its seal, with no staff identifier.
        $bases = app(ProductionCheckoutExportSection::class)->build($account)['productionCheckoutExemptionBases'];
        $this->assertSame([$f['basis']['public_id']], array_column($bases, 'id'));
        $this->assertSame('synthetic:buyer-bound-qualification', $bases[0]['attestation']['reference']);
        $this->assertEqualsCanonicalizing(['qualified_exemption_confirmed', 'reference', 'source_sha256', 'effective_from', 'effective_until'],
            array_keys($bases[0]['attestation']));
        $this->assertSame(0, $bases[0]['taxMinor']);
        $this->assertSame(1, ProductionCheckoutExportSection::counts($account)['production_checkout_exemption_bases']);

        // Another enrolled buyer, and an account with no production identity origin, see nothing of it.
        $empty = ['paidGrantOrders' => [], 'paidTaxGrantOrders' => []];
        $this->assertSame($empty, app(PaidGrantExportSection::class)->build(self::account($other)));
        $this->assertSame($empty, app(PaidGrantExportSection::class)->build(PHP_INT_MAX));
        $this->assertSame(0, array_sum(PaidGrantExportSection::counts(self::account($other))));
        $this->assertSame([], app(ProductionCheckoutExportSection::class)->build(self::account($other))['productionCheckoutExemptionBases']);
        $this->assertSame(0, ProductionCheckoutExportSection::counts(self::account($other))['production_checkout_exemption_bases']);
    }

    public function test_paid_tax_copies_and_original_export_with_their_declarations(): void
    {
        $f = $this->paidTax(true);
        $account = self::account($f['buyer']);

        $sections = app(PaidGrantExportSection::class)->build($account);
        $this->assertSame([], $sections['paidGrantOrders']);
        $this->assertSame([$f['batch']['id']], array_column($sections['paidTaxGrantOrders'], 'id'));
        $batch = $sections['paidTaxGrantOrders'][0];
        $this->assertSame($f['order']['orderId'], $batch['orderId']);
        $this->assertEquals(self::DECLARATIONS, $batch['lines'][0]['buyerDeclarations']);
        $manifest = PaidTaxRecords::decode((array) DB::table('paid_tax_originals')->sole());
        $this->assertSame($manifest['artifact']['pdf_hash'], $batch['lines'][0]['contractOriginal']['sha256']);
        $this->assertSame(['paid_order_origins' => 0, 'paid_grant_origins' => 0, 'paid_originals' => 0,
            'paid_tax_order_origins' => 1, 'paid_tax_grant_origins' => 1, 'paid_tax_originals' => 1], PaidGrantExportSection::counts($account));
    }

    public function test_a_paid_copy_whose_seal_or_buyer_binding_does_not_match_refuses_the_export(): void
    {
        $f = $this->paidTax();
        $account = self::account($f['buyer']);
        $this->dropTriggers(['paid_tax_order_origins', 'paid_tax_grant_origins']);
        $batchRow = (array) DB::table('paid_tax_order_origins')->sole();
        $lineRow = (array) DB::table('paid_tax_grant_origins')->sole();
        $batch = PaidTaxRecords::decode($batchRow);
        $line = PaidTaxRecords::decode($lineRow);
        $reseal = function (array $batchBuyer, array $lineBuyer) use ($batch, $line, $batchRow, $lineRow): void {
            DB::table('paid_tax_order_origins')->where('id', $batchRow['id'])->update(PaidTaxRecords::encode([...$batch, 'original_buyer' => $batchBuyer]));
            DB::table('paid_tax_grant_origins')->where('id', $lineRow['id'])->update(PaidTaxRecords::encode([...$line, 'source' => [...$line['source'], 'buyer' => $lineBuyer]]));
        };
        $buyer = $batch['original_buyer'];

        // A copy filed under another buyer's account: that buyer's export refuses it, and this buyer's export never reads it.
        $other = $this->enrollThroughLocalSmtp('other.buyer@example.test');
        DB::table('paid_tax_order_origins')->insert([...$batchRow, 'id' => $batchRow['id'] + 1, 'public_id' => '00000000-0000-4000-8000-000000000001',
            'order_public_id' => '00000000-0000-4000-8000-000000000002', 'provider_payment_id' => 'misfiled',
            'account_id' => self::account($other), 'actor_id' => $other['user']->id]);
        $this->refusesExport(self::account($other));
        $this->assertSame([$f['batch']['id']], array_column(app(PaidGrantExportSection::class)->build($account)['paidTaxGrantOrders'], 'id'));
        DB::table('paid_tax_order_origins')->where('id', $batchRow['id'] + 1)->delete();

        // Filed under this account, but the sealed binding (repeated consistently on its line) names another origin or account.
        $elsewhere = [...$buyer, 'origin_id' => '00000000-0000-4000-8000-0000000000ff'];
        $reseal($elsewhere, $elsewhere);
        $this->refusesExport($account);
        $otherAccount = [...$buyer, 'account_id' => $buyer['account_id'] + 1];
        $reseal($otherAccount, $otherAccount);
        $this->refusesExport($account);
        // The line repeats a different binding from its batch.
        $reseal($buyer, [...$buyer, 'user_id' => $buyer['user_id'] + 1]);
        $this->refusesExport($account);
        // Restored, it exports again; then a broken seal refuses.
        $reseal($buyer, $buyer);
        $this->assertCount(1, app(PaidGrantExportSection::class)->build($account)['paidTaxGrantOrders']);
        DB::table('paid_tax_grant_origins')->where('id', $lineRow['id'])->update(['payload_hash' => str_repeat('0', 64)]);
        $this->refusesExport($account);
    }

    public function test_an_exemption_basis_whose_sealed_buyer_does_not_match_refuses_the_export(): void
    {
        $f = $this->payable(false);
        $account = self::account($f['buyer']);
        $this->dropTriggers(['production_checkout_exemption_bases']);
        $row = (array) DB::table('production_checkout_exemption_bases')->sole();
        $body = Evidence::open($row, 'production_checkout_exemption_basis');
        foreach (['origin_id' => '00000000-0000-4000-8000-0000000000ff', 'account_id' => $body['request']['buyer']['account_id'] + 1] as $key => $value) {
            $planted = [...$body, 'request' => [...$body['request'], 'buyer' => [...$body['request']['buyer'], $key => $value]]];
            DB::table('production_checkout_exemption_bases')->where('id', $row['id'])->update(Evidence::seal($planted));
            $this->assertSame($row['buyer_origin_id'], DB::table('production_checkout_exemption_bases')->value('buyer_origin_id'));
            $this->refusesBases($account);
        }
        DB::table('production_checkout_exemption_bases')->where('id', $row['id'])->update(Evidence::seal($body));
        $this->assertCount(1, app(ProductionCheckoutExportSection::class)->build($account)['productionCheckoutExemptionBases']);
        DB::table('production_checkout_exemption_bases')->where('id', $row['id'])->update(['payload_hash' => str_repeat('0', 64)]);
        $this->refusesBases($account);
    }

    public function test_more_paid_orders_than_the_cap_refuse_before_any_payload_is_opened(): void
    {
        $f = $this->paidTax();
        $this->dropTriggers(['paid_tax_order_origins']);
        $row = (array) DB::table('paid_tax_order_origins')->sole();
        // Padding batches carry unreadable payloads: the cap must refuse on the count alone, before any of them is opened.
        $padding = [];
        for ($i = 1; $i <= PaidGrantExportSection::MAX_RECORDS; $i++) {
            $padding[] = [...$row, 'id' => $row['id'] + $i, 'public_id' => sprintf('00000000-0000-4000-8000-%012d', $i),
                'order_public_id' => sprintf('00000000-0000-4000-9000-%012d', $i), 'provider_payment_id' => 'pad'.$i,
                'payload' => 'x', 'payload_hash' => str_repeat('0', 64)];
        }
        foreach (array_chunk($padding, 100) as $chunk) {
            DB::table('paid_tax_order_origins')->insert($chunk);
        }
        $this->refusesExport(self::account($f['buyer']), 413, 'export_too_large');
    }

    public function test_a_missing_paid_table_refuses_instead_of_reporting_no_records(): void
    {
        $f = $this->paidTax();
        // A leaf table: no foreign key references the originals, so it drops alone on MySQL too.
        DB::getSchemaBuilder()->drop('paid_originals');
        $this->refusesExport(self::account($f['buyer']));
        try {
            PaidGrantExportSection::counts(self::account($f['buyer']));
            $this->fail('Expected the counts to refuse');
        } catch (DataRightsException $e) {
            $this->assertSame([503, 'production_record_unavailable'], [$e->status, $e->reason]);
        }
    }

    public function test_the_export_document_carries_the_paid_and_exemption_sections(): void
    {
        $f = $this->paidTax();
        $user = $f['buyer']['user']->fresh();
        $account = CustomerAccount::findOrFail(self::account($f['buyer']));
        // The data-rights entry point still admits only test-account sessions (D-30 step 3b), so the document is built directly.
        config(['customer.test_accounts_enabled' => true]);
        $principal = app(CustomerAccess::class)->principal($user);
        $document = app(CustomerDataExportDocument::class)->build($principal, $user, $account, '00000000-0000-4000-8000-0000000000aa', CarbonImmutable::now('UTC'));

        $this->assertSame(3, $document['exportSchema']);
        $this->assertSame([$f['batch']['id']], array_column($document['paidTaxGrantOrders'], 'id'));
        $this->assertSame([[], []], [$document['paidGrantOrders'], $document['productionCheckoutExemptionBases']]);
        $this->assertSame([$f['order']['orderId']], array_column($document['productionTaxCheckoutOrders'], 'id'));
    }

    public function test_deletion_keeps_every_paid_copy_and_the_exemption_basis(): void
    {
        foreach (PaidGrantExportSection::CHAINS as $chain) {
            foreach ($chain['tables'] as $table) {
                $this->assertSame('financial_records_years', CustomerDataRights::RETAINED[$table] ?? null, $table);
            }
        }
        $this->assertSame('financial_records_years', CustomerDataRights::RETAINED['production_checkout_exemption_bases'] ?? null);
        $this->assertArrayHasKey('production_checkout_exemption_bases', ProductionCheckoutExportSection::RECORDS);
    }

    /** The immutability and insert guards are dropped only inside this test's database, to plant rows the application never writes. */
    private function dropTriggers(array $tables): void
    {
        foreach ($tables as $table) {
            $triggers = DB::getDriverName() === 'mysql'
                ? array_column(DB::select("SHOW TRIGGERS WHERE `Table` = '".$table."'"), 'Trigger')
                : array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [$table]), 'name');
            $this->assertNotSame([], $triggers, $table);
            foreach ($triggers as $trigger) {
                DB::statement('DROP TRIGGER '.$trigger);
            }
        }
    }

    private function refusesExport(int $account, int $status = 503, string $reason = 'production_record_unavailable'): void
    {
        try {
            app(PaidGrantExportSection::class)->build($account);
            $this->fail('Expected the export to refuse');
        } catch (DataRightsException $e) {
            $this->assertSame([$status, $reason], [$e->status, $e->reason]);
        }
    }

    private function refusesBases(int $account): void
    {
        try {
            app(ProductionCheckoutExportSection::class)->build($account);
            $this->fail('Expected the export to refuse');
        } catch (DataRightsException $e) {
            $this->assertSame([503, 'production_record_unavailable'], [$e->status, $e->reason]);
        }
    }
}
