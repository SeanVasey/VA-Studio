<?php

namespace Tests\Feature;

use App\Domain\Commerce\ContractIdentity\ContractIdentityStatements;
use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Contracts\Production\VaseyLicenseV2RendererProcess;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\ContractIdentityHolds;
use App\Domain\Grants\ContractRenderHolds;
use App\Domain\Grants\Paid\PaidGrantCommands;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantDownloads;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantFiles;
use App\Domain\Grants\Paid\PaidGrantProjectionRead;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use App\Domain\Grants\Paid\PaidGrantRenderInput;
use App\Domain\Grants\Paid\PaidGrantRenderProfile;
use App\Domain\Grants\Paid\PaidGrantRows;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\Paid\PaidGrantSchema;
use App\Domain\Grants\VaseyLicenseContractSource;
use App\Domain\Grants\VaseyLicenseIssuance;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutContractIdentityForgery;
use Tests\Support\ProductionCheckoutFixtures;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\ProductionCheckoutProviderFixtures;
use Tests\Support\ProductionTrackPreparationFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\VaseyLicenseIssuanceFixtures;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantDocumentJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutContractIdentityForgery;
    use ProductionCheckoutJourneyFixture;
    use VaseyLicenseIssuanceFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', $this->paidDependencyPath('tests/Support/production_identity_smtp_sink.py'), $mode, $capture], timeout: 20);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n/', $process->getOutput()) === 1);
            app()->instance(IdentityNoticeTransport::class, new LoopbackSmtp((int) trim($process->getOutput())));
            (new WorkIdentityNotice)->process($noticeId);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return filesize($capture) > 0 ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
        } finally {
            $process->stop(0);
            unlink($capture);
        }
    }

    private function retained(bool $twoLines = false): array
    {
        if (! $twoLines) {
            $f = $this->payable();
        } else {
            $catalog = ProductionCheckoutFixtures::catalog();
            $second = QuoteFixtures::selection(3499);
            $items = [...$catalog['items'], ...$second['items']];
            $buyer = $this->enrollThroughLocalSmtp();
            $access = new ProductionCustomerAccess;
            $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-two-lines-policy', $catalog['actor']);
            $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $items,
                ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:two-lines-buyer', 'source_sha256' => hash('sha256', 'NONBINDING TWO-LINE QUALIFICATION'),
                    'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                    'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-two-lines-qualification', $catalog['actor']);
            $checkout = new ProductionCheckout($access);
            $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items, $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-two-lines-review');
            $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-two-lines-accept');
            $gateway = new ProductionCheckoutGatewayFixture;
            $hosted = new HostedCheckout($access, $gateway);
            $f = compact('catalog', 'buyer', 'order', 'gateway', 'hosted', 'second');
        }
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['batch'] = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    public function test_actual_claim_render_original_and_complete_order_are_idempotent_and_missing_original_is_restore_only(): void
    {
        $f = $this->retained();
        $documents = new PaidGrantDocuments;
        $complete = $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame('complete', $complete['lines'][0]['documentStatus']);
        $this->assertSame(1, $complete['lines'][0]['attempts']);
        $this->assertCount(2, $complete['lines'][0]['files']);
        $before = $this->ownedRows();
        $this->assertSame($complete, $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertSame($before, $this->ownedRows());
        $manifest = PaidGrantRecords::decode((array) DB::table('paid_originals')->sole());
        $path = Storage::disk('local')->path($manifest['artifact']['storage_path']);
        $bytes = file_get_contents($path);
        rename($path, $path.'.retained-away');
        try {
            // Independent review A10-L1: preparation no longer re-verifies a fulfilled order, so it does not report the
            // missing original. It still never causes a replacement claim, and the download refuses the missing original
            // before any attempt is recorded (each redemption re-verifies the exact bytes).
            $this->assertSame($complete, $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
            $this->assertSame($before, $this->ownedRows());
            $auth = (new PaidGrantDownloads)->authorize($complete['id'], $complete['lines'][0]['id'],
                ['requestKey' => (string) Str::uuid(), 'originHash' => $complete['lines'][0]['originHash'], 'kind' => 'contract', 'nonce' => bin2hex(random_bytes(32))],
                $f['buyer']['principal'], $f['buyer']['user']);
            try {
                (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
                $this->fail('A missing original must never be delivered.');
            } catch (DeliveryException $error) {
                $this->assertSame('target_unavailable', $error->reason);
            }
            $this->assertDatabaseCount('paid_redemptions', 0);
        } finally {
            rename($path.'.retained-away', $path);
        }
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame($complete, $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $after = $this->ownedRows();
        $this->assertCount(1, $after['paid_authorizations']);
        unset($before['paid_authorizations'], $after['paid_authorizations']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('license_grants', 0);
    }

    /**
     * D-30 step 8: with V1 contract identity capture on, a v2 order is initiated (re-verified), reconciled, carried by the
     * frozen V1 paid source and rendered by the paid grant. The paid source and the renderer take the declaration as an
     * opaque object; the contract binder (step 7) is what will place its fields.
     */
    public function test_a_v2_contract_identity_order_pays_through_the_v1_paid_source_and_renders(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $catalog = ProductionCheckoutFixtures::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $catalog['items'],
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification', 'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-qualified-buyer', $catalog['actor']);
        $checkout = new ProductionCheckout($access);
        $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $basis['public_id'],
            ['licenseeType' => 'individual', 'legalName' => 'Zoë Émile', 'legalNameConfirmed' => true,
                'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000'],
                'statementVersion' => ContractIdentityStatements::offered()['individual']['statementVersion']], 'synthetic-v2-review');
        $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-v2-accept');
        $gateway = new ProductionCheckoutGatewayFixture;
        $hosted = new HostedCheckout($access, $gateway);
        $hosted->initiate($buyer['principal'], $buyer['user'], $order['orderId']);
        $gateway->paid = true;
        $this->assertSame('verified', $hosted->reconcile($buyer['principal'], $buyer['user'], $order['orderId'])['paymentStatus']);
        $batch = (new PaidGrants)->finalize($buyer['principal'], $buyer['user'], $order['orderId']);

        $origin = PaidGrantRecords::decode((array) DB::table('paid_grant_origins')->sole());
        $declarations = $origin['source']['buyer_declarations'];
        $this->assertSame(['buyer-contract-identity-v2', 'individual', 'Zoë Émile'], [$declarations['schema'], $declarations['licensee_type'], $declarations['legal_name']]);
        $complete = (new PaidGrantDocuments)->prepare($batch['id'], $buyer['principal'], $buyer['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame('complete', $complete['lines'][0]['documentStatus']);
        $this->assertCount(2, $complete['lines'][0]['files']);
        $this->assertDatabaseCount('paid_originals', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    /**
     * The V1 catalog of ProductionCheckoutFixtures::catalog(), with a synthetic schema 5 license version (the binder binds
     * only schema 5 terms) and the V1 contract identity capture on, paid with a v2 declaration and finalized.
     */
    private function paidV2Order(string $legalName = 'Łukasz Żółć'): array
    {
        config(['production_checkout.contract_identity_v2' => true]);
        ProductionCheckoutProviderFixtures::configure();
        $fixture = ProductionTrackPreparationFixtures::prepared(licenseTerms: self::v2LicenseTerms(), licenseContent: self::v2LicenseContent());
        $machine = ProductionCheckoutProviderFixtures::machine();
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed(
            app(PrepareProductionTrackCapabilities::class)->review($fixture['candidate'], $fixture['source'], $machine, $fixture['actor']), $fixture['actor']);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($candidate, $fixture['reviewer']),
            ProductionTrackPreparationFixtures::reference('software_choices_reviewed'), $fixture['reviewer']);
        config(['production_checkout.exemption_authoring_enabled' => true, 'production_checkout.exemption_policy_owner_ids' => [$fixture['actor']->id]]);
        $catalog = array_replace($fixture, compact('machine', 'candidate'));
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $catalog['items'],
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification', 'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-qualified-buyer', $catalog['actor']);
        $checkout = new ProductionCheckout($access);
        $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $basis['public_id'],
            ['licenseeType' => 'individual', 'legalName' => $legalName, 'legalNameConfirmed' => true,
                'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000'],
                'statementVersion' => ContractIdentityStatements::offered()['individual']['statementVersion']], 'synthetic-v2-review');
        $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-v2-accept');
        $gateway = new ProductionCheckoutGatewayFixture;
        $hosted = new HostedCheckout($access, $gateway);
        $hosted->initiate($buyer['principal'], $buyer['user'], $order['orderId']);
        $gateway->paid = true;
        $this->assertSame('verified', $hosted->reconcile($buyer['principal'], $buyer['user'], $order['orderId'])['paymentStatus']);
        $batch = (new PaidGrants)->finalize($buyer['principal'], $buyer['user'], $order['orderId']);

        return compact('buyer', 'order', 'batch');
    }

    /**
     * Contract increment 5 wiring: with the selection gate on, a v2 declaration on a schema 5 license with a contract source
     * issues a `vasey-license-pdf-v2` original. The origin records the selection; a later gate flip does not change the
     * render, the retry or the replay, and the original downloads by its stored bytes.
     */
    public function test_with_the_v2_issuance_gate_on_a_v2_order_issues_a_v2_license_original_under_its_recorded_profile(): void
    {
        $this->enableV2Issuance();
        $f = $this->paidV2Order();
        $origin = PaidGrantRecords::decode((array) DB::table('paid_grant_origins')->sole());
        $this->assertTrue(VaseyLicenseIssuance::selected($origin['profile']));
        $this->assertSame([VaseyLicenseV2Profile::VERSION, 'paid-license-grant', self::SYNTHETIC_TEMPLATE],
            [$origin['profile']['version'], $origin['profile']['purpose'], $origin['profile']['contract']['template']]);
        $originRow = (array) DB::table('paid_grant_origins')->sole();
        $this->assertSame(['selected'], $this->retainedSelection('paid_origin.retained'));

        // The gate flips off before the render: the recorded profile still decides it.
        config(['contracts.vasey_license_v2_issuance' => false]);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $manifest = PaidGrantRecords::decode((array) DB::table('paid_originals')->sole());
        $bytes = app(PaidGrantFiles::class)->verify($manifest['artifact']);
        $effective = VaseyLicenseIssuance::artifactProfileHash($origin['profile']);
        $this->assertSame([$effective, CanonicalJson::hash($origin['profile'])], [$manifest['artifact']['profile_hash'], $manifest['profile_hash']]);
        $this->assertSame(VaseyLicenseIssuance::expected($origin['profile']), ['input_hash' => $manifest['input_hash'], 'text_digest' => $manifest['text_digest']]);
        $this->assertStringContainsString('vasey-license-pdf-v2,'.$effective, $bytes);
        $this->assertStringNotContainsString('VASEY.AUDIO paid grant profile v1', $bytes);
        $text = $this->extractPdfText($bytes);
        $this->assertSame(2, substr_count($text, 'Łukasz Żółć'), $text);
        $this->assertStringContainsString('SYNTHETIC TEST TEMPLATE', $text);

        // Replays: preparation, finalization and the origin are unchanged with the gate in either state.
        $this->assertSame($complete, (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        config(['contracts.vasey_license_v2_issuance' => true]);
        $this->assertSame($f['batch']['id'], (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['id']);
        $this->assertSame($originRow, (array) DB::table('paid_grant_origins')->sole());
        $this->assertDatabaseCount('paid_originals', 1);
        $this->assertSame(1, (int) DB::table('paid_document_work')->sole()->attempts);

        $auth = (new PaidGrantDownloads)->authorize($complete['id'], $complete['lines'][0]['id'],
            ['requestKey' => (string) Str::uuid(), 'originHash' => $complete['lines'][0]['originHash'], 'kind' => 'contract', 'nonce' => bin2hex(random_bytes(32))],
            $f['buyer']['principal'], $f['buyer']['user']);
        $transfer = (new PaidGrantDownloads)->redeem($auth['id'], $auth['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $downloaded = '';
        $transfer->writeTo(static function (string $chunk) use (&$downloaded): void {
            $downloaded .= $chunk;
        });
        $this->assertSame($bytes, $downloaded);
        $this->assertDatabaseCount('license_grants', 0);
    }

    /**
     * Activation blocker "Post-payment render refusals": a v2 line whose render refuses after payment (here a signature
     * rotated between selection and render) fails its attempt exactly as before, and the order gets one staff-visible render
     * hold, written outside the failed attempt and kept at one across retries. Restoring the pinned signature lets a later
     * attempt issue the original; the append-only record stays.
     */
    public function test_a_v2_render_refused_after_payment_leaves_one_staff_hold_across_retries(): void
    {
        $this->enableV2Issuance();
        $f = $this->paidV2Order();
        $pinned = [config('contracts.vasey_license_v1.signature_path'), config('contracts.vasey_license_v1.signature_sha256')];
        $this->installSyntheticSignature('contracts/signature/rotated-signature.png', 255);
        foreach ([1, 2] as $attempt) {
            try {
                (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
                $this->fail('A v2 render under a rotated signature succeeded.');
            } catch (ContractIssuanceException $error) {
                $this->assertSame('signature_changed', $error->reason);
            }
            $this->assertSame(['failed', $attempt], [DB::table('paid_document_work')->sole()->state, (int) DB::table('paid_document_work')->sole()->attempts]);
            $hold = (array) DB::table('audit_events')->where('action', ContractRenderHolds::ACTION)->sole();
            $this->assertSame(['production_checkout_order', (int) DB::table(CheckoutSchema::TABLES['order'])->where('public_id', $f['order']['orderId'])->value('id'), null],
                [$hold['subject_type'], (int) $hold['subject_id'], $hold['actor_id']]);
            // Only the chain, the refusal code and the order's public id: no buyer data.
            $this->assertSame(['chain' => 'production_checkout_v1', 'reason' => 'signature_changed', 'order_public_id' => $f['order']['orderId']],
                json_decode($hold['context'], true));
        }
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertSame(0, DB::table('audit_events')->where('action', ContractIdentityHolds::ACTION)->count());

        // Staff see it in the existing hold list; the buyer cannot read it.
        $this->actingAs($f['buyer']['user']);
        try {
            ContractIdentityHolds::staffQuery()->get();
            $this->fail('A buyer read the staff hold list.');
        } catch (AuthorizationException) {
        }
        $this->actingAs(LicenseFixtures::admin());
        $this->assertSame([$f['order']['orderId']], ContractIdentityHolds::staffQuery()->get()->pluck('context.order_public_id')->all());

        config(['contracts.vasey_license_v1.signature_path' => $pinned[0], 'contracts.vasey_license_v1.signature_sha256' => $pinned[1]]);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame(3, (int) DB::table('paid_document_work')->sole()->attempts);
        $this->assertSame(1, DB::table('audit_events')->where('action', ContractRenderHolds::ACTION)->count());
    }

    /**
     * Review P2: a v2 render that fails in a way a retry can fix (here the child cannot be started once, `render_failed`)
     * leaves no staff hold; the next attempt issues the original.
     */
    public function test_a_transient_v2_render_failure_then_success_leaves_no_staff_hold(): void
    {
        $this->enableV2Issuance();
        $f = $this->paidV2Order();
        $starts = 0;
        $factory = static function (array $command, string $cwd, array $environment, string $payload) use (&$starts): ?Process {
            return ++$starts === 1 ? null : new Process($command, $cwd, $environment, $payload, 60);
        };
        $this->app->bind(VaseyLicenseV2RendererProcess::class, static fn () => new VaseyLicenseV2RendererProcess($factory));
        try {
            (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('The first attempt rendered.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('render_failed', $error->reason);
        }
        $this->assertSame(0, DB::table('audit_events')->where('action', ContractRenderHolds::ACTION)->count());
        $this->assertTrue((new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['fulfilled']);
        $this->assertSame([2, 2], [$starts, (int) DB::table('paid_document_work')->sole()->attempts]);
        $this->assertSame(0, DB::table('audit_events')->where('action', ContractRenderHolds::ACTION)->count());
    }

    /**
     * Gate off (the shipped literal): the same v2-capable order keeps the existing paid profile and its exact bytes, and a
     * gate flipped on after the origin exists does not move it to v2. Name-only orders keep it with the gate on.
     */
    public function test_with_the_v2_issuance_gate_off_the_existing_paid_profile_and_bytes_are_unchanged(): void
    {
        $this->enableV2Issuance(false);
        $f = $this->paidV2Order();
        $origin = PaidGrantRecords::decode((array) DB::table('paid_grant_origins')->sole());
        $this->assertSame(CanonicalJson::encode(PaidGrantRenderProfile::current('synthetic_rehearsal')), CanonicalJson::encode($origin['profile']));
        // Gate off: the audit context is exactly what it was before the wiring.
        $this->assertSame(['origin_hash'], array_keys(json_decode(DB::table('audit_events')->where('action', 'paid_origin.retained')->sole()->context, true)));

        config(['contracts.vasey_license_v2_issuance' => true]);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $manifest = PaidGrantRecords::decode((array) DB::table('paid_originals')->sole());
        $bytes = app(PaidGrantFiles::class)->verify($manifest['artifact']);
        $this->assertSame($bytes, app(PaidGrantRendererProcess::class)->render(PaidGrantRenderInput::fromOrigin($origin), $origin['profile'])->pdfBytes);
        $this->assertStringContainsString('VASEY.AUDIO paid grant profile v1', $bytes);
        $this->assertStringNotContainsString('vasey-license-pdf-v2', $bytes);
    }

    /** The selection branch recorded in the `paid_origin.retained` audit context, one code per line, or null when absent. */
    private function retainedSelection(string $action): ?array
    {
        return json_decode(DB::table('audit_events')->where('action', $action)->sole()->context, true)['contract_profile_selection'] ?? null;
    }

    /**
     * Review P2-b: selection runs inside the locked origin transaction after payment, so a contract source that throws
     * never refuses the paid order: finalization succeeds with the existing profile and records why.
     */
    public function test_a_throwing_contract_source_keeps_the_existing_paid_profile_and_records_the_branch(): void
    {
        $this->enableV2Issuance();
        app()->instance(VaseyLicenseContractSource::class, new class implements VaseyLicenseContractSource
        {
            public function contract(array $license): ?array
            {
                throw new \RuntimeException('synthetic contract source failure');
            }
        });
        $f = $this->paidV2Order();
        $origin = PaidGrantRecords::decode((array) DB::table('paid_grant_origins')->sole());
        $this->assertSame(CanonicalJson::encode(PaidGrantRenderProfile::current('synthetic_rehearsal')), CanonicalJson::encode($origin['profile']));
        $this->assertSame(['no_contract_source'], $this->retainedSelection('paid_origin.retained'));
        $this->assertTrue((new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['fulfilled']);
    }

    public function test_a_name_only_order_keeps_the_existing_paid_profile_with_the_v2_issuance_gate_on(): void
    {
        $this->enableV2Issuance();
        $f = $this->retained();
        $origin = PaidGrantRecords::decode((array) DB::table('paid_grant_origins')->sole());
        $this->assertSame(['identity_meaning', 'legal_name'], array_keys($origin['source']['buyer_declarations']));
        $this->assertSame(['name_only_declaration'], $this->retainedSelection('paid_origin.retained'));
        $this->assertSame(CanonicalJson::encode(PaidGrantRenderProfile::current('synthetic_rehearsal')), CanonicalJson::encode($origin['profile']));
        $this->assertTrue((new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['fulfilled']);
    }

    /**
     * D-30 (decided 2026-11-08): reconciliation records the payment of an order whose sealed v2 declaration no longer
     * verifies (the frozen evidence checks it only for equality), but paid-grant finalization holds it: a distinct refusal,
     * no origin or work row, and exactly one durable staff record (ContractIdentityHolds). A valid v2 order still finalizes
     * (test_a_v2_contract_identity_order_pays_through_the_v1_paid_source_and_renders); the synthetic gateway binds one
     * provider session id, so one test pays one order.
     */
    public function test_a_paid_v2_order_whose_declaration_no_longer_verifies_is_held_at_finalization(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $catalog = ProductionCheckoutFixtures::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $catalog['items'],
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification', 'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-qualified-buyer', $catalog['actor']);
        $checkout = new ProductionCheckout($access);
        $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $basis['public_id'],
            ['licenseeType' => 'individual', 'legalName' => 'Zoë Émile', 'legalNameConfirmed' => true,
                'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000'],
                'statementVersion' => ContractIdentityStatements::offered()['individual']['statementVersion']], 'synthetic-v2-review');
        $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-v2-accept');
        $forged = self::forgedUnprintableOrder();
        $gateway = new ProductionCheckoutGatewayFixture;
        $gateway->params = self::forgedIntent($forged);
        $gateway->paid = true;
        $hosted = new HostedCheckout($access, $gateway);
        $this->assertSame('verified', $hosted->reconcile($buyer['principal'], $buyer['user'], $forged, 'cs_test_SYNTHETIC')['paymentStatus']);
        $this->assertSame([], $gateway->creates);
        // Reconcile alone has already left the one staff record (a buyer may never finalize).
        $this->assertSame(1, DB::table('audit_events')->where('action', ContractIdentityHolds::ACTION)->count());

        $before = $this->ownedRows();
        $audits = DB::table('audit_events')->count();
        foreach ([1, 2] as $attempt) {
            try {
                (new PaidGrants)->finalize($buyer['principal'], $buyer['user'], $forged);
                $this->fail('A paid order whose v2 declaration no longer verifies must be held.');
            } catch (PaidGrantException $error) {
                $this->assertSame([409, 'contract_identity_held', 'Paid grant request unavailable.'], [$error->status, $error->reason, $error->getMessage()]);
            }
            $this->assertSame($before, $this->ownedRows());
            // Still exactly one durable staff record (written by reconcile), however often finalization is retried; no origin audit row.
            $this->assertSame($audits, DB::table('audit_events')->count(), 'attempt '.$attempt);
        }
        $this->assertSame([], array_filter($before));
        $hold = (array) DB::table('audit_events')->where('action', ContractIdentityHolds::ACTION)->sole();
        $orderRow = DB::table(CheckoutSchema::TABLES['order'])->where('public_id', $forged)->sole();
        $this->assertSame(['production_checkout_order', (int) $orderRow->id, null], [$hold['subject_type'], (int) $hold['subject_id'], $hold['actor_id']]);
        $this->assertSame(['chain' => 'production_checkout_v1', 'reason' => 'contract_identity_held', 'order_public_id' => $forged],
            json_decode($hold['context'], true, 4, JSON_THROW_ON_ERROR));
        // The payment stays recorded: the order is paid, with fulfillment held for staff.
        $this->assertSame('verified', $hosted->status($buyer['principal'], $buyer['user'], $forged)['paymentStatus']);
    }

    public function test_failed_second_line_retains_first_original_without_any_activation_until_exact_fresh_retry(): void
    {
        $f = $this->retained(true);
        $second = Storage::disk('local')->path($f['second']['media']['master_wav']->storage_path);
        $renders = 0;
        $moved = false;
        app()->afterResolving(PaidGrantRendererProcess::class, function () use ($second, &$renders, &$moved): void {
            if (++$renders === 2) {
                rename($second, $second.'.retained-away');
                $moved = true;
            }
        });
        try {
            (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('Incomplete physical deliverables cannot activate any line of a complete paid order.');
        } catch (DeliveryException $error) {
            $this->assertTrue($moved, $error->getMessage());
            $this->assertSame('asset_unavailable', $error->reason);
            $this->assertSame(['complete', 'failed'], DB::table('paid_document_work')->orderBy('id')->pluck('state')->all());
            $this->assertDatabaseCount('paid_originals', 1);
            $this->assertDatabaseCount('paid_fulfillments', 0);
        } finally {
            if ($moved) {
                rename($second.'.retained-away', $second);
            }
        }
        $first = (array) DB::table('paid_originals')->sole();
        $firstBytes = (new PaidGrantFiles)->verify(PaidGrantRecords::decode($first)['artifact']);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertCount(2, $complete['lines']);
        $this->assertSame([1, 2], array_column($complete['lines'], 'attempts'));
        $this->assertSame($first, (array) DB::table('paid_originals')->where('id', $first['id'])->sole());
        $this->assertSame($firstBytes, (new PaidGrantFiles)->verify(PaidGrantRecords::decode($first)['artifact']));
        $this->assertDatabaseCount('paid_originals', 2);
        $this->assertDatabaseCount('paid_fulfillments', 1);
    }

    public function test_busy_original_claim_returns_only_a_fresh_one_use_body_receipt_without_new_claim_or_activation(): void
    {
        $f = $this->retained();
        $expires = CarbonImmutable::now('UTC')->addSeconds(PaidGrantDocuments::LEASE_SECONDS)->format('Y-m-d H:i:s');
        (new PaidGrantCommands)->run($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'],
            function (array $graph, PaidGrantRows $rows) use ($expires): array {
                $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'claimed', attempts = 1, claim_id = ?, expires_at = ? WHERE id = ?",
                    [(string) Str::uuid(), $expires, $graph['lines'][0]['work']['id']]);

                return [];
            });
        $before = $this->ownedRows();
        $read = PaidGrantProjectionRead::begin();
        $waiting = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'], $read);
        $this->assertFalse($waiting['fulfilled']);
        $this->assertSame('claimed', $waiting['lines'][0]['documentStatus']);
        $this->assertSame(1, $waiting['lines'][0]['attempts']);
        $this->assertSame([], $waiting['lines'][0]['files']);
        $this->assertSame($before, $this->ownedRows());
        $read->proveBeforeBytes();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        try {
            $read->proveBeforeBytes();
            $this->fail('The captured original body receipt cannot be reused.');
        } catch (PaidGrantException) {
            $this->assertSame($before, $this->ownedRows());
        }
    }

    public function test_credential_withdrawal_during_real_render_cannot_publish_original_or_extend_claim_authority(): void
    {
        $f = $this->retained();
        $fired = false;
        app()->afterResolving(PaidGrantRendererProcess::class, function () use ($f, &$fired): void {
            $fired = true;
            DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('Withdrawn while paid original renders')]);
        });
        try {
            (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('Original rendering does not renew the withdrawn buyer session.');
        } catch (PaidGrantException $error) {
            $this->assertSame(403, $error->status);
            $this->assertTrue($fired);
        }
        $work = (array) DB::table('paid_document_work')->sole();
        $this->assertSame('claimed', $work['state']);
        $this->assertSame(1, (int) $work['attempts']);
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertDatabaseCount('paid_fulfillments', 0);
        $this->assertDatabaseCount('paid_grant_origins', 1);
    }

    private function ownedRows(): array
    {
        $result = [];
        foreach (array_keys(PaidGrantSchema::specs()) as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }
}
