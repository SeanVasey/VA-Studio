<?php

namespace Tests\Feature;

use App\Domain\Commerce\ContractIdentity\ContractIdentityStatements;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\ContractIdentityHolds;
use App\Domain\Grants\ContractRenderHolds;
use App\Domain\Grants\PaidTax\PaidTaxActor;
use App\Domain\Grants\PaidTax\PaidTaxCleanup;
use App\Domain\Grants\PaidTax\PaidTaxCommands;
use App\Domain\Grants\PaidTax\PaidTaxDeadline;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxFiles;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxPolicy;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxReads;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxRendererProcess;
use App\Domain\Grants\PaidTax\PaidTaxRenderInput;
use App\Domain\Grants\PaidTax\PaidTaxRenderProfile;
use App\Domain\Grants\PaidTax\PaidTaxRuntime;
use App\Domain\Grants\PaidTax\PaidTaxTransaction;
use App\Domain\Grants\VaseyLicenseIssuance;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionTaxCheckoutContractIdentityForgery;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\Support\VaseyLicenseIssuanceFixtures;
use Tests\TestCase;

/** Actual local SMTP enrollment and retained synthetic Tax payment; no host/provider acceptance claim. */
class PaidTaxGrantJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;
    use ProductionTaxCheckoutContractIdentityForgery;
    use VaseyLicenseIssuanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
    }

    protected function paid(string $behavior = 'exclusive', int $tax = 437, bool $twoLines = false): array
    {
        return $this->paidTaxOrder($behavior, $tax, $twoLines);
    }

    public static function amounts(): array
    {
        return ['exclusive' => ['exclusive', 437, 5436], 'inclusive' => ['inclusive', 437, 4999], 'zero tax' => ['exclusive', 0, 4999]];
    }

    #[DataProvider('amounts')]
    public function test_authenticated_tax_origin_retains_original_nested_amounts_and_replays_once(string $behavior, int $tax, int $total): void
    {
        $f = $this->paid($behavior, $tax);
        $projectionRead = PaidTaxProjectionRead::begin();
        $first = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'], $projectionRead);
        $projectionRead->proveBeforeBytes();
        $this->assertSame([2, 'paid-tax-license-grant', false, $f['order']['orderId']],
            [$first['schemaVersion'], $first['purpose'], $first['fulfilled'], $first['orderId']]);
        $this->assertSame([4999, $tax, $total, $behavior], [$first['lines'][0]['tax']['line_subtotal_minor'],
            $first['lines'][0]['tax']['line_tax_minor'], $first['lines'][0]['tax']['line_total_minor'], $first['lines'][0]['tax']['tax_behavior']]);
        $this->assertSame([], $first['lines'][0]['files']);
        $this->assertSame($first, (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        foreach (['paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_document_work'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        foreach (['paid_order_origins', 'paid_grant_origins', 'paid_originals', 'paid_tax_originals', 'paid_tax_fulfillments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $source = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole())['source'];
        $this->assertSame($first['orderId'], $source['order_id']);
        $this->assertSame($first['lines'][0]['tax'], $source['tax']);
        foreach (['payment_id', 'payment_hash', 'amounts', 'line_amount_minor', 'line_tax_minor'] as $v1Field) {
            $this->assertArrayNotHasKey($v1Field, $source);
        }
        // Actual original inspector compatibility, including the new ordinary user/account FK consumers.
        $ownership = (new IdentityMigrationOwnership)->inspect(DB::getPdo(), DB::getDriverName());
        $this->assertNotEmpty($ownership);
    }

    public function test_actual_tax_original_is_private_deterministic_idempotent_and_restore_only(): void
    {
        $f = $this->paid();
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $documents = new PaidTaxDocuments;
        $projectionRead = PaidTaxProjectionRead::begin();
        $complete = $documents->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user'], $projectionRead);
        $projectionRead->proveBeforeBytes();
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame(['complete', 1], [$complete['lines'][0]['documentStatus'], $complete['lines'][0]['attempts']]);
        $this->assertCount(2, $complete['lines'][0]['files']);
        $manifestRow = (array) DB::table('paid_tax_originals')->sole();
        $manifest = PaidTaxRecords::decode($manifestRow);
        $path = Storage::disk('local')->path($manifest['artifact']['storage_path']);
        $this->assertStringStartsWith('contracts/paid-tax-v2/synthetic_rehearsal/', $manifest['artifact']['storage_path']);
        $this->assertSame(0400, fileperms($path) & 0777);
        $bytes = (new PaidTaxFiles)->verify($manifest['artifact']);
        $this->assertSame(hash('sha256', $bytes), $complete['lines'][0]['files'][0]['sha256']);
        $body = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole());
        $rendered = app(PaidTaxRendererProcess::class)->render(PaidTaxRenderInput::fromOrigin($body), $body['profile']);
        $this->assertSame($bytes, $rendered->pdfBytes, 'The genuine isolated renderer changed the same retained original.');
        $extract = new Process(['pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
        $extract->mustRun();
        $text = $extract->getOutput();
        foreach (['source.tax.line_subtotal_minor' => '4999', 'source.tax.line_tax_minor' => '437',
            'source.tax.line_total_minor' => '5436', 'source.tax.tax_behavior' => 'exclusive',
            'source.tax.calculator' => 'stripe_checkout_automatic_tax'] as $label => $value) {
            $this->assertMatchesRegularExpression('/'.preg_quote($label, '/').'\s+'.preg_quote($value, '/').'/', $text);
        }
        $this->assertSame($complete, $documents->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertSame($manifestRow, (array) DB::table('paid_tax_originals')->sole());
        rename($path, $path.'.retained-away');
        try {
            // Historical fulfillment is a past observation; this batch adds no delivery endpoint.
            $this->assertSame($complete, $documents->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']));
            $this->assertSame($manifestRow, (array) DB::table('paid_tax_originals')->sole());
            $this->assertSame(1, (int) DB::table('paid_tax_document_work')->sole()->attempts);
        } finally {
            rename($path.'.retained-away', $path);
        }
        $this->assertSame($bytes, (new PaidTaxFiles)->verify($manifest['artifact']));
        $this->assertDatabaseCount('paid_tax_fulfillments', 1);
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    /**
     * D-30 step 5 (before activation): with the V2 tax checkout's contract identity gate on, a v2 order is initiated
     * (re-verified), reconciled, carried by the paid-tax grant origin and rendered. The renderer takes the declaration as an
     * opaque object; the contract binder (step 7) is what will place its fields.
     */
    public function test_a_v2_contract_identity_order_pays_and_renders_through_the_paid_tax_grant(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->taxOrder('exclusive', 2500, ['licenseeType' => 'individual', 'legalName' => 'Zoë Émile', 'legalNameConfirmed' => true,
            'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000'],
            'statementVersion' => ContractIdentityStatements::offered()['individual']['statementVersion']]);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->lineTax = 437;
        $f['transport']->paid = true;
        $this->assertSame('verified', $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        $body = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole());
        $declarations = $body['source']['buyer_declarations'];
        $this->assertSame(['buyer-contract-identity-v2', 'individual', 'Zoë Émile'], [$declarations['schema'], $declarations['licensee_type'], $declarations['legal_name']]);
        $complete = (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame('complete', $complete['lines'][0]['documentStatus']);
        $this->assertCount(2, $complete['lines'][0]['files']);
        $manifest = PaidTaxRecords::decode((array) DB::table('paid_tax_originals')->sole());
        $bytes = (new PaidTaxFiles)->verify($manifest['artifact']);
        $rendered = app(PaidTaxRendererProcess::class)->render(PaidTaxRenderInput::fromOrigin($body), $body['profile']);
        $this->assertSame($bytes, $rendered->pdfBytes, 'The genuine isolated renderer changed the same retained original.');
        $this->assertDatabaseCount('paid_tax_originals', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    /** A paid V2 tax order on a synthetic schema 5 license with a v2 declaration, finalized. */
    private function paidV2TaxOrder(string $legalName = 'Ștefan Țepeș'): array
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->taxOrder('exclusive', 2500, ['licenseeType' => 'individual', 'legalName' => $legalName, 'legalNameConfirmed' => true,
            'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000'],
            'statementVersion' => ContractIdentityStatements::offered()['individual']['statementVersion']], self::v2LicenseTerms(), self::v2LicenseContent());
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->lineTax = 437;
        $f['transport']->paid = true;
        $this->assertSame('verified', $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['retained'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    /**
     * Contract increment 5 wiring: with the selection gate on, a paid-tax v2 order issues a `vasey-license-pdf-v2` original
     * (the pre-tax fee, the Latin Extended name), under the profile its origin recorded even after the gate flips off.
     */
    public function test_with_the_v2_issuance_gate_on_a_v2_tax_order_issues_a_v2_license_original_under_its_recorded_profile(): void
    {
        $this->enableV2Issuance();
        $f = $this->paidV2TaxOrder();
        $originRow = (array) DB::table('paid_tax_grant_origins')->sole();
        $body = PaidTaxRecords::decode($originRow);
        $this->assertTrue(VaseyLicenseIssuance::selected($body['profile']));
        $this->assertSame([VaseyLicenseV2Profile::VERSION, 'paid-tax-license-grant'], [$body['profile']['version'], $body['profile']['purpose']]);
        // Recorded by hash; the template and renderer profile are stored once each, outside the origin.
        $this->assertSame(hash('sha256', self::SYNTHETIC_TEMPLATE), $body['profile']['contract']['template_sha256']);
        $this->assertDatabaseCount('vasey_license_contract_texts', 2);
        $this->assertSame(['selected'], json_decode(DB::table('audit_events')->where('action', 'paid_tax_origin.retained')->sole()->context, true)['contract_profile_selection']);

        config(['contracts.vasey_license_v2_issuance' => false]);
        $complete = (new PaidTaxDocuments)->prepare($f['retained']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $manifest = PaidTaxRecords::decode((array) DB::table('paid_tax_originals')->sole());
        $bytes = (new PaidTaxFiles)->verify($manifest['artifact']);
        $effective = VaseyLicenseIssuance::artifactProfileHash($body['profile']);
        $this->assertSame([$effective, CanonicalJson::hash($body['profile'])], [$manifest['artifact']['profile_hash'], $manifest['profile_hash']]);
        $this->assertStringContainsString('vasey-license-pdf-v2,'.$effective, $bytes);
        $text = $this->extractPdfText($bytes);
        $this->assertSame(2, substr_count($text, 'Ștefan Țepeș'), $text);
        // The contract states the pre-tax fee (the line subtotal), not the taxed total.
        $this->assertStringContainsString('fee $49.99 (forty-nine dollars and ninety-nine cents)', $text);

        config(['contracts.vasey_license_v2_issuance' => true]);
        $this->assertSame($complete, (new PaidTaxDocuments)->prepare($f['retained']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertSame($f['retained']['id'], (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['id']);
        $this->assertSame($originRow, (array) DB::table('paid_tax_grant_origins')->sole());
        $this->assertDatabaseCount('paid_tax_originals', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    /** Gate off: the same v2-capable tax order keeps the existing paid-tax profile and its exact bytes. */
    public function test_with_the_v2_issuance_gate_off_the_existing_paid_tax_profile_and_bytes_are_unchanged(): void
    {
        $this->enableV2Issuance(false);
        $f = $this->paidV2TaxOrder();
        $body = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole());
        $this->assertSame(CanonicalJson::encode(PaidTaxRenderProfile::current('synthetic_rehearsal')), CanonicalJson::encode($body['profile']));
        $this->assertSame(['origin_hash'], array_keys(json_decode(DB::table('audit_events')->where('action', 'paid_tax_origin.retained')->sole()->context, true)));
        $this->assertDatabaseCount('vasey_license_contract_texts', 0);
        config(['contracts.vasey_license_v2_issuance' => true]);
        $this->assertTrue((new PaidTaxDocuments)->prepare($f['retained']['id'], $f['buyer']['principal'], $f['buyer']['user'])['fulfilled']);
        $manifest = PaidTaxRecords::decode((array) DB::table('paid_tax_originals')->sole());
        $bytes = (new PaidTaxFiles)->verify($manifest['artifact']);
        $this->assertSame($bytes, app(PaidTaxRendererProcess::class)->render(PaidTaxRenderInput::fromOrigin($body), $body['profile'])->pdfBytes);
        $this->assertStringNotContainsString('vasey-license-pdf-v2', $bytes);
    }

    /** Prepares until the line's attempts are spent, with its master away; returns each failure's class and reason. */
    private function exhaustAttempts(array $f): array
    {
        $master = Storage::disk('local')->path($f['catalog']['media']['master_wav']->storage_path);
        rename($master, $master.'.away');
        $failures = [];
        try {
            for ($attempt = 1; $attempt <= PaidTaxDocuments::MAX_ATTEMPTS + 1; $attempt++) {
                try {
                    (new PaidTaxDocuments)->prepare($f['retained']['id'], $f['buyer']['principal'], $f['buyer']['user']);
                    $this->fail('Preparation succeeded without its master.');
                } catch (DeliveryException|PaidTaxException $error) {
                    $failures[] = [$error::class, $error->reason ?? $error->status ?? null,
                        DB::table('audit_events')->where('action', ContractRenderHolds::ACTION)->count()];
                }
            }
        } finally {
            rename($master.'.away', $master);
        }

        return $failures;
    }

    /**
     * Activation blocker "Post-payment render refusals": a v2 tax line whose attempts are all spent on failures a retry could
     * have fixed (here its master is away after each render) leaves one staff hold on the last attempt, not before; spent
     * attempts then refuse as before, without another record. Gate off, the same failures leave no render hold.
     */
    public function test_a_v2_tax_line_that_spends_its_attempts_leaves_one_staff_hold_on_the_last(): void
    {
        $this->enableV2Issuance();
        $f = $this->paidV2TaxOrder();
        $this->assertTrue(VaseyLicenseIssuance::selected(PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole())['profile']));
        $failures = $this->exhaustAttempts($f);
        $this->assertSame([0, 0, 0, 0, 1, 1], array_column($failures, 2));
        $this->assertSame(array_fill(0, PaidTaxDocuments::MAX_ATTEMPTS, DeliveryException::class), array_slice(array_column($failures, 0), 0, PaidTaxDocuments::MAX_ATTEMPTS));
        $this->assertSame(PaidTaxException::class, $failures[PaidTaxDocuments::MAX_ATTEMPTS][0]);
        $hold = (array) DB::table('audit_events')->where('action', ContractRenderHolds::ACTION)->sole();
        $this->assertSame(['production_tax_checkout_order', ['chain' => 'production_tax_checkout_v2', 'reason' => ContractRenderHolds::ATTEMPTS_EXHAUSTED,
            'order_public_id' => $f['order']['orderId']]], [$hold['subject_type'], json_decode($hold['context'], true)]);
        $this->assertSame((int) DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $f['order']['orderId'])->value('id'), (int) $hold['subject_id']);
    }

    public function test_with_the_v2_issuance_gate_off_spent_tax_attempts_leave_no_render_hold(): void
    {
        $this->enableV2Issuance(false);
        $f = $this->paidV2TaxOrder();
        $this->assertFalse(VaseyLicenseIssuance::selected(PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole())['profile']));
        $failures = $this->exhaustAttempts($f);
        $this->assertSame([0, 0, 0, 0, 0, 0], array_column($failures, 2));
        $this->assertSame(PaidTaxException::class, $failures[PaidTaxDocuments::MAX_ATTEMPTS][0]);
        $this->assertSame(PaidTaxDocuments::MAX_ATTEMPTS, (int) DB::table('paid_tax_document_work')->sole()->attempts);
    }

    /**
     * D-30 (decided 2026-11-08): reconciliation records the payment of an order whose sealed v2 declaration no longer verifies,
     * and paid-tax finalization holds it: a distinct refusal, no grant row, and exactly one durable staff record, written by
     * reconcile and kept at one however often finalization is retried. Initiate still refuses the order before any provider call.
     */
    public function test_a_paid_v2_order_whose_declaration_no_longer_verifies_is_recorded_and_held_at_finalization(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->taxOrder('exclusive', 2500, ['licenseeType' => 'individual', 'legalName' => 'Zoë Émile', 'legalNameConfirmed' => true,
            'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000'],
            'statementVersion' => ContractIdentityStatements::offered()['individual']['statementVersion']]);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $forged = self::forgedUnprintableTaxOrder();
        try {
            $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $forged);
            $this->fail('Initiate re-verifies the declaration.');
        } catch (CheckoutException $error) {
            $this->assertSame('changed', $error->reason);
        }
        $this->assertSame([], $f['transport']->calls);
        $f['transport']->params = self::forgedTaxSession($forged, RecordingTaxCheckoutTransport::SESSION);
        $f['transport']->paid = true;

        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $forged);
        $this->assertSame(['complete', 'verified', 'pending_fulfillment'], [$paid['checkoutStatus'], $paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $reviewed = (array) DB::table(TaxCheckoutSchema::TABLES['reviewed'])->sole();
        $this->assertSame([RecordingTaxCheckoutTransport::SESSION, RecordingTaxCheckoutTransport::PAYMENT], [$reviewed['provider_session_id'], $reviewed['provider_payment_id']]);
        $this->assertSame(['retrieve', 'paymentIntent'], array_column($f['transport']->calls, 0));
        // Reconcile alone has already left the one staff record (a buyer may never finalize).
        $this->assertSame(1, DB::table('audit_events')->where('action', ContractIdentityHolds::ACTION)->count());

        $audits = DB::table('audit_events')->count();
        foreach ([1, 2] as $attempt) {
            try {
                (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $forged);
                $this->fail('No grant origin may be created for a declaration that no longer verifies.');
            } catch (PaidTaxException $error) {
                $this->assertSame([409, ContractIdentityHolds::REASON, 'Paid grant request unavailable.'], [$error->status, $error->reason, $error->getMessage()]);
            }
            foreach (['paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_document_work', 'paid_tax_originals', 'paid_tax_fulfillments', 'license_grants'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertSame($audits, DB::table('audit_events')->count(), 'attempt '.$attempt);
        }
        $hold = (array) DB::table('audit_events')->where('action', ContractIdentityHolds::ACTION)->sole();
        $orderRow = DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $forged)->sole();
        $this->assertSame(['production_tax_checkout_order', (int) $orderRow->id, null], [$hold['subject_type'], (int) $hold['subject_id'], $hold['actor_id']]);
        $this->assertSame(['chain' => 'production_tax_checkout_v2', 'reason' => 'contract_identity_held', 'order_public_id' => $forged],
            json_decode($hold['context'], true, 4, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('王小明', $hold['context']);
        // The payment stays recorded and visible to the buyer; nothing was charged again.
        $this->assertSame('verified', $f['checkout']->status($f['buyer']['principal'], $f['buyer']['user'], $forged)['paymentStatus']);
        $this->assertSame(['retrieve', 'paymentIntent'], array_column($f['transport']->calls, 0));
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 1);
    }

    public function test_failed_second_tax_line_retains_first_original_until_exact_physical_retry(): void
    {
        $f = $this->paid(twoLines: true);
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertCount(2, $retained['lines']);
        $secondPath = Storage::disk('local')->path($f['second']['media']['master_wav']->storage_path);
        $renders = 0;
        $moved = false;
        app()->afterResolving(PaidTaxRendererProcess::class, static function () use ($secondPath, &$renders, &$moved): void {
            if (++$renders === 2) {
                rename($secondPath, $secondPath.'.retained-away');
                $moved = true;
            }
        });
        $failure = null;
        try {
            try {
                (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
            } catch (DeliveryException $error) {
                $failure = $error;
            }
        } finally {
            if ($moved) {
                rename($secondPath.'.retained-away', $secondPath);
            }
        }
        $this->assertTrue($moved);
        $this->assertInstanceOf(DeliveryException::class, $failure);
        $this->assertSame('asset_unavailable', $failure->reason);
        $this->assertSame(['complete', 'failed'], DB::table('paid_tax_document_work')->orderBy('id')->pluck('state')->all());
        $this->assertDatabaseCount('paid_tax_originals', 1);
        $this->assertDatabaseCount('paid_tax_fulfillments', 0);
        $first = (array) DB::table('paid_tax_originals')->sole();
        $artifact = PaidTaxRecords::decode($first)['artifact'];
        $firstBytes = (new PaidTaxFiles)->verify($artifact);
        $complete = (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame([1, 2], array_column($complete['lines'], 'attempts'));
        $this->assertSame($first, (array) DB::table('paid_tax_originals')->where('id', $first['id'])->sole());
        $this->assertSame($firstBytes, (new PaidTaxFiles)->verify($artifact));
        $this->assertSame(3, $renders, 'Retry regenerated an already completed original.');
        $this->assertDatabaseCount('paid_tax_originals', 2);
        $this->assertDatabaseCount('paid_tax_fulfillments', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public static function actorMetadata(): array
    {
        return ['raw original' => ['original'], 'raw attributes' => ['attributes']];
    }

    #[DataProvider('actorMetadata')]
    public function test_late_nonarray_actor_storage_refuses_without_pure_offset_code_or_retained_origin(string $property): void
    {
        $f = $this->paid();
        $actor = $f['buyer']['user'];
        $metadata = new \ReflectionProperty(Model::class, $property);
        $original = $metadata->getValue($actor);
        $changed = new PaidTaxMetadataOffsetFixture($original);
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($metadata, $actor, $changed, &$calls): void {
            if ($calls++ === 0) {
                $metadata->setValue($actor, $changed);
            }
        });
        $failure = null;
        try {
            try {
                (new PaidTaxGrants)->finalize($f['buyer']['principal'], $actor, $f['order']['orderId']);
            } catch (PaidTaxException $error) {
                $failure = $error;
            }
        } finally {
            $metadata->setValue($actor, $original);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $changed->calls, 'Pure actor admission dispatched raw model offset code.');
        $this->assertInstanceOf(PaidTaxException::class, $failure);
        $this->assertSame(403, $failure->status);
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    /** Runs on both drivers: the bounded index's locking read, owner closure and terminal proof under one original frame. */
    public function test_history_index_lists_only_current_owner_batches_and_refuses_late_withdrawal_before_bytes(): void
    {
        $f = $this->paid();
        $first = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $second = $this->secondPaidTaxOrder($f, 'cs_test_historyindexnative', 'pi_historyindexnative');
        $newest = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $second['order']['orderId']);
        $foreign = $this->enrollThroughLocalSmtp('foreign-history-index@example.test');
        $read = PaidTaxProjectionRead::begin();
        $list = (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user'], $read);
        $read->proveBeforeBytes();
        $rows = DB::table('paid_tax_order_origins')->orderByDesc('id')->get()->map(fn ($row): array => (array) $row)->all();
        $this->assertSame([$newest['id'], $first['id']], array_column($rows, 'public_id'));
        $this->assertSame(['schemaVersion' => 2, 'originLimit' => 20, 'hasMore' => false, 'origins' => array_map(fn (array $row): array => [
            'id' => $row['public_id'], 'orderId' => $row['order_public_id'], 'createdAt' => str_replace(' ', 'T', $row['created_at']).'Z',
            'lineCount' => 1, 'provenance' => 'synthetic_rehearsal'], $rows)], $list);
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $read = PaidTaxProjectionRead::begin();
        $this->assertSame([], (new PaidTaxReads)->index($foreign['principal'], $foreign['user'], $read)['origins']);
        $read->proveBeforeBytes();
        $read = PaidTaxProjectionRead::begin();
        (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user'], $read);
        DB::table('customer_accounts')->where('id', $f['buyer']['principal']->accountId)->update(['active' => 0, 'access_version' => DB::raw('access_version + 1')]);
        try {
            $read->proveBeforeBytes();
            $this->fail('A withdrawn owner received index bytes.');
        } catch (PaidTaxException $error) {
            $this->assertSame(403, $error->status);
        }
        try {
            (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('A withdrawn owner was listed.');
        } catch (PaidTaxException $error) {
            $this->assertSame(403, $error->status);
        }
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('paid_tax_order_origins', 2);
    }

    public function test_late_current_owner_withdrawal_rolls_back_origin_and_allows_a_fresh_authorized_retry(): void
    {
        $f = $this->paid();
        $pdo = DB::getPdo();
        $id = $f['buyer']['principal']->accountId;
        $before = (array) DB::table('customer_accounts')->where('id', $id)->sole();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($pdo, $id, &$calls): void {
            if ($calls++ === 0) {
                $statement = $pdo->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?');
                $statement->execute([$id]);
            }
        });
        $failure = null;
        try {
            (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        } catch (PaidTaxException $error) {
            $failure = $error;
        }
        $this->assertSame(1, $calls);
        $this->assertInstanceOf(PaidTaxException::class, $failure);
        $this->assertSame(403, $failure->status);
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertSame($before, (array) DB::table('customer_accounts')->where('id', $id)->sole());
        $this->assertFalse($pdo->inTransaction());
        $retry = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame($f['order']['orderId'], $retry['orderId']);
        $this->assertDatabaseCount('paid_tax_order_origins', 1);
    }

    public function test_late_tax_purpose_withdrawal_rolls_back_without_adopting_new_authority(): void
    {
        $f = $this->paid();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use (&$calls): void {
            $calls++;
            config(['production-tax-checkout.enabled' => false]);
        });
        $failure = null;
        try {
            try {
                (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            } catch (PaidTaxException $error) {
                $failure = $error;
            }
        } finally {
            config(['production-tax-checkout.enabled' => true]);
        }
        $this->assertSame(1, $calls);
        $this->assertInstanceOf(PaidTaxException::class, $failure);
        $this->assertSame(409, $failure->status);
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertFalse(DB::getPdo()->inTransaction());
    }

    public function test_throwing_managed_after_commit_refuses_observation_and_exact_replay_preserves_durable_origin(): void
    {
        $f = $this->paid();
        $registered = 0;
        $executed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use (&$registered, &$executed): void {
            if ($registered++ === 0) {
                DB::afterCommit(static function () use (&$executed): void {
                    $executed++;
                    throw new \RuntimeException('Synthetic ordinary afterCommit failure.');
                });
            }
        });
        $failure = null;
        try {
            (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        $this->assertSame(1, $executed);
        $this->assertSame(1, $registered);
        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertSame('Synthetic ordinary afterCommit failure.', $failure->getMessage());
        $this->assertDatabaseCount('paid_tax_order_origins', 1);
        $this->assertDatabaseCount('paid_tax_grant_origins', 1);
        $this->assertFalse(DB::getPdo()->inTransaction());
        $before = (array) DB::table('paid_tax_order_origins')->sole();
        $retry = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame($before['public_id'], $retry['id']);
        $this->assertSame($before, (array) DB::table('paid_tax_order_origins')->sole());
        $this->assertSame(1, $executed);
    }

    public function test_original_shared_account_heavy_lock_yields_without_spending_any_tax_claim(): void
    {
        $f = $this->paid();
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $lock = Cache::lock('paid-grant-heavy:'.hash('sha256', 'paid-heavy-v1:'.$f['buyer']['principal']->accountId), 660);
        $this->assertTrue($lock->get());
        try {
            $read = PaidTaxProjectionRead::begin();
            $busy = null;
            $yield = (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user'], $read, $busy);
            $read->proveBeforeBytes();
            $this->assertTrue($busy);
            $this->assertSame($retained, $yield);
            $this->assertSame(0, (int) DB::table('paid_tax_document_work')->sole()->attempts);
            $this->assertDatabaseCount('paid_tax_originals', 0);
            $this->assertDatabaseCount('paid_tax_fulfillments', 0);
        } finally {
            $lock->release();
        }
    }

    public function test_pre_source_direct_commit_reopen_refuses_original_frame_and_preserves_the_unrelated_transaction(): void
    {
        $f = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->exec('CREATE TABLE paid_tax_reopen_probe (id INTEGER PRIMARY KEY)');
        $calls = 0;
        app()->afterResolving(PaidTaxPolicy::class, static function () use ($pdo, &$calls): void {
            if ($calls === 0) {
                $calls++;
                $pdo->commit();
                $pdo->beginTransaction();
                $pdo->exec('INSERT INTO paid_tax_reopen_probe VALUES (123)');
            }
        });
        $failure = null;
        try {
            try {
                (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            } catch (PaidTaxException $error) {
                $failure = $error;
            }
            $this->assertSame(1, $calls);
            $this->assertInstanceOf(PaidTaxException::class, $failure, 'A replaced pre-source physical frame produced a positive grant.');
            $this->assertSame(503, $failure->status);
            $this->assertTrue($pdo->inTransaction(), 'The command committed or rolled back a different reopened transaction.');
            $this->assertSame([123], $pdo->query('SELECT id FROM paid_tax_reopen_probe')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM paid_tax_order_origins')->fetchColumn());
        } finally {
            // Only the fixture owns this reopened direct-PDO transaction and its deliberate framework mismatch.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    public function test_delayed_claim_wall_clock_cannot_extend_the_original_physical_lease_budget(): void
    {
        $f = $this->paid();
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $originalClock = CarbonImmutable::getTestNow();
        $delayed = 0;
        $entryBound = null;
        $renderSeen = false;
        $publicationBudget = null;
        CarbonImmutable::setTestNow(static function ($instant) use (&$delayed, &$entryBound): CarbonImmutable {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if ($delayed === 0 && ($frame['class'] ?? null) === PaidTaxDocuments::class
                    && str_starts_with($frame['function'] ?? '', '{closure')) {
                    $delayed++;
                    $entryBound = hrtime(true) + 300_000_000_000 - (int) $instant->format('u') * 1000;
                    // Real callback delay, returning the wall instant supplied BEFORE that delay.
                    usleep(2_000_000);
                    break;
                }
            }

            return CarbonImmutable::instance($instant);
        });
        app()->afterResolving(PaidTaxRendererProcess::class, static function () use (&$renderSeen): void {
            $renderSeen = true;
        });
        app()->afterResolving(PaidTaxPolicy::class, static function () use (&$renderSeen, &$publicationBudget): void {
            if (! $renderSeen || $publicationBudget !== null) {
                return;
            }
            foreach (debug_backtrace() as $frame) {
                if (($frame['class'] ?? null) === PaidTaxCommands::class && ($frame['function'] ?? null) === 'run'
                    && ($frame['args'][4] ?? null) instanceof PaidTaxDeadline) {
                    $publicationBudget = $frame['args'][4]->value();
                    break;
                }
            }
        });
        try {
            $complete = (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
        } finally {
            CarbonImmutable::setTestNow($originalClock);
        }
        $this->assertSame(1, $delayed, 'The real claim clock callback did not execute exactly once.');
        $this->assertTrue($renderSeen);
        $this->assertIsInt($entryBound);
        $this->assertIsInt($publicationBudget);
        $this->assertLessThanOrEqual($entryBound, $publicationBudget, 'Publication restarted time spent in the original claim clock.');
        $this->assertTrue($complete['fulfilled']);
    }

    public static function begunFailures(): array
    {
        return ['ordinary throw' => ['throw'], 'purpose withdrawal' => ['withdraw']];
    }

    #[DataProvider('begunFailures')]
    public function test_begin_delegate_refusal_closes_only_the_original_frame_and_discards_its_callbacks(string $behavior): void
    {
        $f = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $delegate = $connection->getEventDispatcher();
        $manager = (new \ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        $calls = 0;
        $callbacks = 0;
        $delegate->listen(TransactionBeginning::class, static function () use ($behavior, &$calls, &$callbacks): void {
            if ($calls++ !== 0) {
                return;
            }
            DB::afterCommit(static function () use (&$callbacks): void {
                $callbacks++;
            });
            DB::afterRollBack(static function () use (&$callbacks): void {
                $callbacks++;
            });
            if ($behavior === 'throw') {
                throw new \RuntimeException('Synthetic ordinary begin failure.');
            }
            config(['paid-tax-grants.rehearsal_enabled' => false]);
        });
        $failure = null;
        try {
            try {
                (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            } catch (\Throwable $error) {
                $failure = $error;
            }
            $this->assertSame(1, $calls);
            $this->assertInstanceOf($behavior === 'throw' ? \RuntimeException::class : PaidTaxException::class, $failure);
            if ($behavior === 'throw') {
                $this->assertSame('Synthetic ordinary begin failure.', $failure->getMessage());
            } else {
                $this->assertSame(503, $failure->status);
            }
            $this->assertFalse($pdo->inTransaction(), 'An original transaction leaked after an ordinary begun delegate refused.');
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertCount(0, $manager->getPendingTransactions());
            $this->assertCount(0, $manager->getCommittedTransactions());
            $this->assertSame(0, $callbacks, 'Owned deferred callbacks ran during refusal cleanup.');
            $this->assertSame($delegate, $connection->getEventDispatcher());
            $this->assertDatabaseCount('paid_tax_order_origins', 0);
        } finally {
            config(['paid-tax-grants.rehearsal_enabled' => true]);
            // Baseline reproduction can leak; only the fixture closes its own leftover transaction.
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $retry = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame($f['order']['orderId'], $retry['orderId']);
        $this->assertSame(0, $callbacks);
        $this->assertDatabaseCount('paid_tax_order_origins', 1);
    }

    public function test_begin_delegate_direct_reopen_and_throw_preserves_unrelated_work_and_replacement_dispatcher(): void
    {
        $f = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->exec('CREATE TABLE paid_tax_begin_reopen_probe (id INTEGER PRIMARY KEY)');
        $manager = (new \ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        $replacement = new Dispatcher(app());
        $callbacks = 0;
        $calls = 0;
        $connection->getEventDispatcher()->listen(TransactionBeginning::class, static function () use ($connection, $pdo, $replacement, &$callbacks, &$calls): void {
            $calls++;
            DB::afterCommit(static function () use (&$callbacks): void {
                $callbacks++;
            });
            DB::afterRollBack(static function () use (&$callbacks): void {
                $callbacks++;
            });
            $pdo->commit();
            $pdo->beginTransaction();
            $pdo->exec('INSERT INTO paid_tax_begin_reopen_probe VALUES (456)');
            $connection->setEventDispatcher($replacement);
            throw new \RuntimeException('Synthetic begun reopen failure.');
        });
        $failure = null;
        try {
            try {
                (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            } catch (\Throwable $error) {
                $failure = $error;
            }
            $this->assertSame(1, $calls);
            $this->assertInstanceOf(\RuntimeException::class, $failure);
            $this->assertSame('Synthetic begun reopen failure.', $failure->getMessage());
            $this->assertTrue($pdo->inTransaction(), 'Cleanup rolled back unrelated reopened physical work.');
            $this->assertSame([456], $pdo->query('SELECT id FROM paid_tax_begin_reopen_probe')->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertSame($replacement, $connection->getEventDispatcher());
            $this->assertCount(0, $manager->getPendingTransactions());
            $this->assertCount(0, $manager->getCommittedTransactions());
            $this->assertSame(0, $callbacks);
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM paid_tax_order_origins')->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $this->assertSame(0, $callbacks);
    }

    public function test_live_budget_shortened_in_begin_delegate_closes_original_without_renewal(): void
    {
        $f = $this->paid();
        $budget = PaidTaxDeadline::start();
        $runtime = PaidTaxRuntime::capture($budget);
        $originalEnd = $budget->value();
        $connection = $runtime->connection;
        $delegate = $connection->getEventDispatcher();
        $calls = 0;
        $ran = false;
        $delegate->listen(TransactionBeginning::class, static function () use ($budget, &$calls): void {
            if ($calls++ === 0) {
                $budget->shortenTo(hrtime(true) - 1);
            }
        });
        $original = null;
        $failure = null;
        try {
            try {
                PaidTaxTransaction::run($runtime, PaidTaxActor::capture($f['buyer']['user']), static function () use (&$ran): array {
                    $ran = true;

                    return [];
                }, $original);
            } catch (PaidTaxException $error) {
                $failure = $error;
                PaidTaxCleanup::abort(null, null, $original);
            }
            $this->assertSame(1, $calls);
            $this->assertInstanceOf(PaidTaxException::class, $failure);
            $this->assertSame(410, $failure->status);
            $this->assertFalse($ran);
            $this->assertLessThan($originalEnd, $budget->value());
            $this->assertFalse($runtime->primary->inTransaction());
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame($delegate, $connection->getEventDispatcher());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $retry = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame($f['order']['orderId'], $retry['orderId']);
    }

    public static function prestartMutations(): array
    {
        return ['dispatcher replacement' => ['dispatcher'], 'appended hook' => ['append'], 'replaced hooks' => ['replace']];
    }

    #[DataProvider('prestartMutations')]
    public function test_prestart_displacement_refuses_before_physical_begin_and_preserves_replacement(string $kind): void
    {
        $f = $this->paid();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $delegate = $connection->getEventDispatcher();
        $replacement = new Dispatcher(app());
        $hooks = new \ReflectionProperty(Connection::class, 'beforeStartingTransaction');
        $before = $hooks->getValue($connection);
        $mutated = null;
        $calls = 0;
        $connection->beforeStartingTransaction(static function () use ($kind, $connection, $replacement, $hooks, &$calls, &$mutated): void {
            if ($calls++ !== 0) {
                return;
            }
            if ($kind === 'dispatcher') {
                $connection->setEventDispatcher($replacement);
            } elseif ($kind === 'append') {
                $connection->beforeStartingTransaction(static function (): void {});
                $mutated = $hooks->getValue($connection);
            } else {
                $hooks->setValue($connection, []);
                $mutated = [];
            }
        });
        $failure = null;
        try {
            try {
                (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            } catch (\Throwable $error) {
                $failure = $error;
            }
            $this->assertSame(1, $calls);
            $this->assertInstanceOf(PaidTaxException::class, $failure);
            $this->assertSame(503, $failure->status);
            $this->assertFalse($pdo->inTransaction(), 'A displaced pre-start witness was refused only after opening an unowned transaction.');
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame($kind === 'dispatcher' ? $replacement : $delegate, $connection->getEventDispatcher());
            if ($kind !== 'dispatcher') {
                $this->assertSame($mutated, $hooks->getValue($connection), 'Conditional restoration overwrote a delegate-owned hook replacement.');
            }
            $this->assertDatabaseCount('paid_tax_order_origins', 0);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        try {
            $retry = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->assertSame($f['order']['orderId'], $retry['orderId']);
        } finally {
            $hooks->setValue($connection, $before);
            $connection->setEventDispatcher($delegate);
        }
    }
}

final class PaidTaxMetadataOffsetFixture extends \ArrayObject
{
    public int $calls = 0;

    public function offsetGet(mixed $key): mixed
    {
        $this->calls++;

        return parent::offsetGet($key);
    }
}
