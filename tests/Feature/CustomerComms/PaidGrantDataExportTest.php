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
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /** One download authorization of the paid line through the genuine chain, redeemed when asked. */
    private function download(array $f, string $kind, bool $redeem): void
    {
        $downloads = new PaidGrantDownloads;
        $auth = $downloads->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], ['requestKey' => (string) Str::uuid(),
            'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))],
            $f['buyer']['principal'], $f['buyer']['user']);
        if ($redeem) {
            $downloads->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user'])->writeTo(static function (string $chunk): void {});
        }
    }

    /** Count keys in the order PaidGrantExportSection::counts() reports them. */
    private static function counts(array $v1, array $tax): array
    {
        $keys = ['order_origins', 'grant_origins', 'originals', 'authorizations', 'redemptions'];

        return array_combine(array_map(fn (string $k): string => 'paid_'.$k, $keys), $v1)
            + array_combine(array_map(fn (string $k): string => 'paid_tax_'.$k, $keys), $tax);
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
        $this->download($f, 'master_wav', true);
        $this->download($f, 'contract', false);
        $other = $this->enrollThroughLocalSmtp('other.buyer@example.test');
        $account = self::account($f['buyer']);

        $sections = app(PaidGrantExportSection::class)->build($account);
        // Exact shapes: no storage path, claim, token, request key, provider or staff identifier can appear.
        $this->assertSame(['id', 'orderId', 'createdAt', 'lines'], array_keys($sections['paidGrantOrders'][0]));
        $this->assertSame(['id', 'position', 'title', 'buyerDeclarations', 'contractOriginal', 'downloads'], array_keys($sections['paidGrantOrders'][0]['lines'][0]));
        $this->assertSame(['createdAt', 'sha256', 'sizeBytes'], array_keys($sections['paidGrantOrders'][0]['lines'][0]['contractOriginal']));
        $downloads = $sections['paidGrantOrders'][0]['lines'][0]['downloads'];
        $this->assertSame([['kind', 'authorizedAt', 'redeemedAt'], ['kind', 'authorizedAt', 'redeemedAt']], array_map('array_keys', $downloads));
        $this->assertSame(['master_wav', 'contract'], array_column($downloads, 'kind'));
        $this->assertIsString($downloads[0]['redeemedAt']);
        $this->assertNull($downloads[1]['redeemedAt']);
        $json = json_encode($sections, JSON_THROW_ON_ERROR);
        $authorization = (array) DB::table('paid_authorizations')->first();
        foreach (['storage_path', 'claim_id', 'contracts/paid/', (string) DB::table('paid_originals')->value('claim_id'),
            $authorization['request_key'], $authorization['token_hash'], $authorization['public_id']] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
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
        $this->assertSame(self::counts([1, 1, 1, 2, 1], [0, 0, 0, 0, 0]), PaidGrantExportSection::counts($account));

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
        $this->assertSame(self::counts([0, 0, 0, 0, 0], [1, 1, 1, 0, 0]), PaidGrantExportSection::counts($account));
        $this->assertSame(['id', 'orderId', 'createdAt', 'lines'], array_keys($batch));
        $this->assertSame(['id', 'position', 'title', 'buyerDeclarations', 'contractOriginal', 'downloads'], array_keys($batch['lines'][0]));
        $this->assertSame(['createdAt', 'sha256', 'sizeBytes'], array_keys($batch['lines'][0]['contractOriginal']));
        $json = json_encode($sections, JSON_THROW_ON_ERROR);
        $row = (array) DB::table('paid_tax_order_origins')->sole();
        // Provider session, payment and account identifiers are deliberately not exported.
        foreach (['storage_path', 'contracts/paid-tax-v2/', $row['provider_session_id'], $row['provider_payment_id'], $row['provider_account']] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
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

    public function test_v1_paid_records_that_do_not_match_refuse_the_export(): void
    {
        $f = $this->paidV1();
        $this->download($f, 'contract', true);
        $account = self::account($f['buyer']);
        $this->dropTriggers(['paid_order_origins', 'paid_grant_origins', 'paid_originals', 'paid_authorizations']);
        $restored = fn () => $this->assertCount(1, app(PaidGrantExportSection::class)->build($account)['paidGrantOrders']);
        $batchRow = (array) DB::table('paid_order_origins')->sole();
        $lineRow = (array) DB::table('paid_grant_origins')->sole();
        $batch = PaidGrantRecords::decode($batchRow);
        $line = PaidGrantRecords::decode($lineRow);
        $buyer = $batch['original_buyer'];

        // The sealed binding, repeated consistently on its line, names another origin.
        $elsewhere = [...$buyer, 'origin_id' => '00000000-0000-4000-8000-0000000000ff'];
        DB::table('paid_order_origins')->update(PaidGrantRecords::encode([...$batch, 'original_buyer' => $elsewhere]));
        DB::table('paid_grant_origins')->update(PaidGrantRecords::encode([...$line, 'source' => [...$line['source'], 'buyer' => $elsewhere]]));
        $this->refusesExport($account);
        DB::table('paid_order_origins')->update(PaidGrantRecords::encode($batch));
        DB::table('paid_grant_origins')->update(PaidGrantRecords::encode($line));
        $restored();

        // An original whose manifest names another line.
        $originalRow = (array) DB::table('paid_originals')->sole();
        $manifest = PaidGrantRecords::decode($originalRow);
        DB::table('paid_originals')->update(PaidGrantRecords::encode([...$manifest, 'origin_hash' => str_repeat('a', 64)]));
        $this->refusesExport($account);
        DB::table('paid_originals')->update(PaidGrantRecords::encode($manifest));
        $restored();

        // A download authorization whose seal names another line.
        $authorizationRow = (array) DB::table('paid_authorizations')->sole();
        $authorization = PaidGrantRecords::decode($authorizationRow);
        DB::table('paid_authorizations')->update(PaidGrantRecords::encode([...$authorization, 'origin_hash' => str_repeat('a', 64)]));
        $this->refusesExport($account);
        DB::table('paid_authorizations')->update(PaidGrantRecords::encode($authorization));
        $restored();

        // The order claims more lines than are retained.
        DB::table('paid_order_origins')->update(['line_count' => 2]);
        $this->refusesExport($account);
        DB::table('paid_order_origins')->update(['line_count' => 1]);
        $restored();

        // Paid orders remain after the account's production origin is gone (foreign keys off only inside this test).
        $this->dropTriggers(['production_identity_origins']);
        DB::statement(DB::getDriverName() === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys = OFF');
        try {
            DB::table('production_identity_origins')->where('account_id', $account)->delete();
            $this->refusesExport($account);
        } finally {
            DB::statement(DB::getDriverName() === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys = ON');
        }
    }

    public function test_counts_and_export_stay_scoped_when_another_account_has_paid_rows(): void
    {
        $f = $this->paidV1();
        $this->download($f, 'contract', true);
        $account = self::account($f['buyer']);
        $other = $this->enrollThroughLocalSmtp('other.buyer@example.test');
        $otherAccount = self::account($other);
        $this->dropTriggers(['paid_order_origins', 'paid_grant_origins', 'paid_originals', 'paid_authorizations', 'paid_redemptions']);
        // A copy of the whole graph filed under the other account.
        $batch = (array) DB::table('paid_order_origins')->sole();
        $line = (array) DB::table('paid_grant_origins')->sole();
        $original = (array) DB::table('paid_originals')->sole();
        $authorization = (array) DB::table('paid_authorizations')->sole();
        $redemption = (array) DB::table('paid_redemptions')->sole();
        DB::table('paid_order_origins')->insert([...$batch, 'id' => $batch['id'] + 1, 'public_id' => '00000000-0000-4000-8000-000000000011',
            'order_public_id' => '00000000-0000-4000-8000-000000000012', 'account_id' => $otherAccount, 'actor_id' => $other['user']->id]);
        DB::table('paid_grant_origins')->insert([...$line, 'id' => $line['id'] + 1, 'public_id' => '00000000-0000-4000-8000-000000000013', 'batch_id' => $batch['id'] + 1]);
        DB::table('paid_originals')->insert([...$original, 'id' => $original['id'] + 1, 'origin_id' => $line['id'] + 1, 'claim_id' => '00000000-0000-4000-8000-000000000014']);
        DB::table('paid_authorizations')->insert([...$authorization, 'id' => $authorization['id'] + 1, 'public_id' => '00000000-0000-4000-8000-000000000015',
            'origin_id' => $line['id'] + 1, 'account_id' => $otherAccount, 'token_hash' => str_repeat('c', 64)]);
        DB::table('paid_redemptions')->insert([...$redemption, 'id' => $redemption['id'] + 1, 'authorization_id' => $authorization['id'] + 1]);

        $this->assertSame(self::counts([1, 1, 1, 1, 1], [0, 0, 0, 0, 0]), PaidGrantExportSection::counts($account));
        $this->assertSame(self::counts([1, 1, 1, 1, 1], [0, 0, 0, 0, 0]), PaidGrantExportSection::counts($otherAccount));
        $sections = app(PaidGrantExportSection::class)->build($account);
        $this->assertSame([$f['batch']['id']], array_column($sections['paidGrantOrders'], 'id'));
        $this->assertCount(1, $sections['paidGrantOrders'][0]['lines'][0]['downloads']);
        // The copy's seal still names this buyer, so the other account's export refuses it rather than leaking it.
        $this->refusesExport($otherAccount);
    }

    public function test_more_paid_lines_or_downloads_than_the_caps_refuse_before_any_payload_is_opened(): void
    {
        $f = $this->paidV1();
        $this->download($f, 'contract', false);
        $account = self::account($f['buyer']);
        $this->dropTriggers(['paid_grant_origins', 'paid_authorizations']);
        $authorization = (array) DB::table('paid_authorizations')->sole();
        $padding = [];
        for ($i = 1; $i <= PaidGrantExportSection::MAX_DOWNLOADS; $i++) {
            $padding[] = [...$authorization, 'id' => $authorization['id'] + $i, 'public_id' => sprintf('00000000-0000-4000-8000-%012d', $i),
                'request_key' => sprintf('00000000-0000-4000-9000-%012d', $i), 'token_hash' => hash('sha256', 'pad'.$i),
                'payload' => 'x', 'payload_hash' => str_repeat('0', 64)];
        }
        foreach (array_chunk($padding, 50) as $chunk) {
            DB::table('paid_authorizations')->insert($chunk);
        }
        $this->refusesExport($account, 413, 'export_too_large');
        DB::table('paid_authorizations')->where('id', '>', $authorization['id'])->delete();
        $this->assertCount(1, app(PaidGrantExportSection::class)->build($account)['paidGrantOrders']);

        $line = (array) DB::table('paid_grant_origins')->sole();
        $padding = [];
        for ($i = 1; $i <= PaidGrantExportSection::MAX_LINES; $i++) {
            $padding[] = [...$line, 'id' => $line['id'] + $i, 'public_id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'position' => 1 + $i,
                'line_public_id' => sprintf('00000000-0000-4000-9000-%012d', $i), 'payload' => 'x', 'payload_hash' => str_repeat('0', 64)];
        }
        foreach (array_chunk($padding, 50) as $chunk) {
            DB::table('paid_grant_origins')->insert($chunk);
        }
        $this->refusesExport($account, 413, 'export_too_large');
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
