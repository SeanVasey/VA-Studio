<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\QuoteException;
use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Contracts\ContractWork;
use App\Domain\Contracts\DispatchTestContract;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use App\Jobs\IssueTestContractJob;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Real retained grant/queue/filesystem orchestration; synthetic rendering except explicit integration. */
class TestContractIssuanceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;
    private ContractRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $this->gateway = PaymentFixtures::gateway(); $this->renderer = F::renderer();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, $this->renderer);
    }

    public function test_request_captures_the_exact_paid_grant_and_render_effect_once(): void
    {
        $f = F::paid($this->gateway); $before = FinalizationFixtures::retained(); $calls = $this->gateway->calls;
        $request = app(RequestTestContract::class)->handle($f['grant']->id)->refresh();
        $this->assertSame($f['grant']->id, $request->license_grant_id);
        $this->assertSame(FulfillmentOutbox::sole()->id, $request->fulfillment_outbox_id);
        $this->assertSame($f['grant']->render_input_hash, $request->input_hash);
        $this->assertSame(CanonicalJson::VERSION, $request->canonicalization_version);
        $this->assertSame(ContractRenderProfile::hash($request->profile), $request->profile_hash);
        $this->assertTrue(Str::isUuid($request->public_id)); $this->assertTrue(Str::isUuid($request->document_public_id));
        $original = $request->getAttributes(); $this->travelTo(now()->addDay());
        $retried = app(RequestTestContract::class)->handle($f['grant']->id)->refresh();
        $this->assertSame($original, $retried->getAttributes()); $this->assertDatabaseCount('contract_render_requests', 1);
        $this->assertDatabaseCount('grant_contracts', 0); $this->assertSame([], $this->renderer->calls);
        $this->assertSame($before, FinalizationFixtures::retained()); $this->assertSame($calls, $this->gateway->calls);
    }

    public function test_one_original_pdf_and_completed_work_preserve_pending_fulfillment(): void
    {
        $f = F::paid($this->gateway); $before = FinalizationFixtures::retained(); $calls = $this->gateway->calls;
        $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertDatabaseCount('contract_render_work', 1);
        $contract = GrantContract::sole(); $work = ContractRenderWork::sole();
        $this->assertSame($request->document_public_id, $contract->public_id);
        $this->assertSame($request->id, $contract->contract_render_request_id);
        $this->assertSame($f['grant']->id, $contract->license_grant_id);
        $this->assertSame($request->input_hash, $contract->input_hash); $this->assertSame($request->profile_hash, $contract->profile_hash);
        $this->assertSame('local', $contract->disk);
        $this->assertSame('contracts/test/'.$request->public_id.'/'.$contract->claim_token.'/original.pdf', $contract->storage_path);
        $bytes = app(ContractFiles::class)->verify($contract);
        $this->assertSame(hash('sha256', $bytes), $contract->pdf_hash); $this->assertSame(strlen($bytes), $contract->size_bytes);
        $this->assertSame('completed', $work->state); $this->assertSame(1, $work->attempts);
        $this->assertNull($work->claim_token); $this->assertNull($work->lease_expires_at); $this->assertNull($work->reason);
        $this->assertSame([0], array_column($this->renderer->calls, 'transaction_level'));
        $this->assertSame('pending', PendingEntitlement::sole()->state); $this->assertSame('pending', FulfillmentOutbox::sole()->state);
        $this->assertSame($before, FinalizationFixtures::retained()); $this->assertSame($calls, $this->gateway->calls);
        $retained = F::retained(); $audits = DB::table('audit_events')->count();
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($retained, F::retained()); $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertCount(1, $this->renderer->calls);
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('issued', $view['contractStatus']); $this->assertSame('pending_activation', $view['fulfillmentStatus']);
        $this->assertArrayNotHasKey('downloadUrl', $view);
    }

    public function test_real_isolated_renderer_can_issue_one_private_pdf_from_an_actual_paid_grant(): void
    {
        $f = F::paid($this->gateway); $before = FinalizationFixtures::retained();
        $this->app->forgetInstance(ContractRenderer::class);
        $this->assertInstanceOf(IsolatedContractRenderer::class, app(ContractRenderer::class));
        $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $contract = GrantContract::sole(); $bytes = app(ContractFiles::class)->verify($contract);
        $this->assertStringStartsWith('%PDF-', $bytes); $this->assertGreaterThan(1000, strlen($bytes));
        $this->assertGreaterThanOrEqual(1, $contract->page_count);
        $this->assertSame($request->profile_hash, $contract->profile_hash);
        $this->assertSame($before, FinalizationFixtures::retained());
        $this->assertSame('pending', PendingEntitlement::sole()->state);
    }

    public function test_order_status_accepts_a_contract_completed_between_its_work_and_original_reads(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertNotNull(app(ContractWork::class)->claim($request->id));
        $this->travelTo(ContractRenderWork::sole()->lease_expires_at);
        $before = FinalizationFixtures::retained(); $calls = $this->gateway->calls;
        $interleaved = false; $observedState = null; $winner = null;
        // Complete through the real worker after the reader has hydrated old work, before it reads the original.
        Event::listen('eloquent.retrieved: '.ContractRenderWork::class, function ($work) use ($request, &$interleaved, &$observedState, &$winner): void {
            if ($interleaved || $work->contract_render_request_id !== $request->id) { return; }
            $interleaved = true; $observedState = $work->state;
            $winner = app(RenderTestContract::class)->handle($request->id);
        });
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertTrue($interleaved); $this->assertSame('processing', $observedState); $this->assertSame('ready', $winner);
        $this->assertSame('issued', $view['contractStatus']); $this->assertSame('pending_activation', $view['fulfillmentStatus']);
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertSame('completed', ContractRenderWork::sole()->state);
        $this->assertSame(2, ContractRenderWork::sole()->attempts); $this->assertCount(1, $this->renderer->calls);
        $this->assertSame($before, FinalizationFixtures::retained()); $this->assertSame($calls, $this->gateway->calls);
    }

    public static function originalAvailabilityAfterConcurrentCompletion(): array
    {
        return [[false, 'ready'], [true, 'original_unavailable']];
    }

    #[DataProvider('originalAvailabilityAfterConcurrentCompletion')]
    public function test_worker_that_loses_its_claim_to_completed_issuance_verifies_the_winning_original(bool $removeOriginal, string $expected): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $before = FinalizationFixtures::retained(); $calls = $this->gateway->calls;
        $observedWork = false; $interleaved = false; $winner = null; $retainedWinner = null;
        Event::listen('eloquent.retrieved: '.ContractRenderWork::class, function ($work) use ($request, &$observedWork): void {
            if ($work->contract_render_request_id === $request->id) { $observedWork = true; }
        });
        // The contender has observed uncompleted work. A winner commits before the contender takes its locks.
        Event::listen('eloquent.retrieved: '.ContractRenderRequest::class,
            function ($record) use ($request, $removeOriginal, &$observedWork, &$interleaved, &$winner, &$retainedWinner): void {
                if ($interleaved || ! $observedWork || $record->id !== $request->id || DB::transactionLevel() !== 0) { return; }
                $interleaved = true;
                $winner = app(RenderTestContract::class)->handle($request->id);
                $retainedWinner = F::retained();
                if ($removeOriginal && $winner === 'ready') {
                    $contract = GrantContract::sole();
                    $this->assertTrue(unlink(Storage::disk('local')->path($contract->storage_path)));
                }
            });
        $this->assertSame($expected, app(RenderTestContract::class)->handle($request->id));
        $this->assertTrue($interleaved); $this->assertSame('ready', $winner);
        $this->assertSame($retainedWinner, F::retained()); $this->assertDatabaseCount('grant_contracts', 1);
        $this->assertSame('completed', ContractRenderWork::sole()->state); $this->assertSame(1, ContractRenderWork::sole()->attempts);
        $this->assertCount(1, $this->renderer->calls); $this->assertSame($before, FinalizationFixtures::retained());
        $this->assertSame($calls, $this->gateway->calls);
    }

    public function test_missing_original_is_not_regenerated_and_exact_restore_recovers_verification(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $contract = GrantContract::sole(); $bytes = app(ContractFiles::class)->verify($contract);
        $before = F::retained(); $path = Storage::disk($contract->disk)->path($contract->storage_path);
        $this->assertTrue(unlink($path));
        $this->assertSame('original_unavailable', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($before, F::retained()); $this->assertCount(1, $this->renderer->calls);
        $this->assertSame('completed', ContractRenderWork::sole()->state);
        $this->assertSame(strlen($bytes), file_put_contents($path, $bytes)); $this->assertTrue(chmod($path, 0400));
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($before, F::retained()); $this->assertCount(1, $this->renderer->calls);
    }

    public function test_corrupt_original_is_never_overwritten_by_a_fresh_render(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $contract = GrantContract::sole(); $original = app(ContractFiles::class)->verify($contract); $before = F::retained();
        $path = Storage::disk('local')->path($contract->storage_path); $changed = $original; $changed[12] = $changed[12] === 'X' ? 'Y' : 'X';
        $this->assertTrue(chmod($path, 0600)); $this->assertSame(strlen($changed), file_put_contents($path, $changed)); $this->assertTrue(chmod($path, 0400));
        $this->assertSame('original_unavailable', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($changed, file_get_contents($path)); $this->assertSame($before, F::retained()); $this->assertCount(1, $this->renderer->calls);
        $this->assertTrue(chmod($path, 0600)); $this->assertSame(strlen($original), file_put_contents($path, $original)); $this->assertTrue(chmod($path, 0400));
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
    }

    public function test_transient_render_failure_uses_due_backoff_before_one_successful_retry(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->renderer->onRender = fn () => throw new ContractIssuanceException('render_failed');
        $this->assertSame('retry', app(RenderTestContract::class)->handle($request->id));
        $work = ContractRenderWork::sole(); $this->assertSame('retry', $work->state); $this->assertSame('render_failed', $work->reason);
        $this->assertTrue($work->next_attempt_at->greaterThanOrEqualTo(now()->addSeconds(60)));
        $this->assertDatabaseCount('grant_contracts', 0); $this->assertCount(1, $this->renderer->calls);
        $this->assertSame('pending', app(RenderTestContract::class)->handle($request->id)); $this->assertCount(1, $this->renderer->calls);
        $this->travelTo($work->next_attempt_at); $this->renderer->onRender = null;
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame(2, ContractRenderWork::sole()->attempts); $this->assertDatabaseCount('grant_contracts', 1);
    }

    public static function mismatchedRendererEvidence(): array
    {
        return [['profile_hash'], ['text_digest']];
    }

    #[DataProvider('mismatchedRendererEvidence')]
    public function test_renderer_result_must_match_the_frozen_profile_and_complete_text_before_any_private_write(string $field): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $before = FinalizationFixtures::retained(); $audits = DB::table('audit_events')->count();
        $this->renderer->onRender = function (array $input, array $profile) use ($field): RenderedContract {
            $valid = F::syntheticResult($input, $profile);
            return new RenderedContract($valid->pdfBytes, $valid->sha256, $valid->sizeBytes, $valid->pageCount,
                $field === 'text_digest' ? str_repeat('0', 64) : $valid->textDigest,
                $field === 'profile_hash' ? str_repeat('0', 64) : $valid->profileHash);
        };
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        $work = ContractRenderWork::sole(); $this->assertSame('invalid_pdf', $work->reason); $this->assertSame(1, $work->attempts);
        $this->assertDatabaseCount('grant_contracts', 0); $this->assertSame([], Storage::disk('local')->allFiles('contracts/test'));
        $this->assertSame($before, FinalizationFixtures::retained()); $this->assertSame($audits, DB::table('audit_events')->count());
        $retained = F::retained(); $this->renderer->onRender = null;
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($retained, F::retained()); $this->assertCount(1, $this->renderer->calls);
    }

    public function test_private_storage_failure_retries_after_backoff_without_publishing_or_changing_paid_evidence(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $before = FinalizationFixtures::retained(); $audits = DB::table('audit_events')->count();
        config(['filesystems.disks.local.serve' => true]);
        try {
            $this->assertSame('retry', app(RenderTestContract::class)->handle($request->id));
            $this->assertSame('storage_failed', ContractRenderWork::sole()->reason);
            $this->assertDatabaseCount('grant_contracts', 0); $this->assertSame([], Storage::disk('local')->allFiles('contracts/test'));
            $this->assertSame($before, FinalizationFixtures::retained()); $this->assertSame($audits, DB::table('audit_events')->count());
            $this->assertSame('pending', app(RenderTestContract::class)->handle($request->id)); $this->assertCount(1, $this->renderer->calls);
        } finally { config(['filesystems.disks.local.serve' => false]); }
        $this->travelTo(ContractRenderWork::sole()->next_attempt_at);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $contract = GrantContract::sole(); $this->assertSame($contract->pdf_hash, hash('sha256', app(ContractFiles::class)->verify($contract)));
        $this->assertSame(2, ContractRenderWork::sole()->attempts); $this->assertSame('completed', ContractRenderWork::sole()->state);
        $this->assertSame($before, FinalizationFixtures::retained()); $this->assertCount(2, $this->renderer->calls);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.contract.test_issued')->count());
    }

    public static function permanentRendererFailures(): array
    {
        return [['unsupported_input'], ['invalid_pdf'], ['profile_changed']];
    }

    #[DataProvider('permanentRendererFailures')]
    public function test_permanent_render_failure_is_quarantined_without_automatic_replay(string $reason): void
    {
        $f = F::paid($this->gateway); $before = FinalizationFixtures::retained();
        $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->renderer->onRender = fn () => throw new ContractIssuanceException($reason);
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        $work = ContractRenderWork::sole(); $this->assertSame('quarantined', $work->state); $this->assertSame($reason, $work->reason);
        $this->travelTo(now()->addDays(2)); $this->renderer->onRender = null;
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame(1, ContractRenderWork::sole()->attempts); $this->assertCount(1, $this->renderer->calls);
        $this->assertDatabaseCount('grant_contracts', 0); $this->assertSame($before, FinalizationFixtures::retained());
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('attention', $view['contractStatus']); $this->assertSame('blocked', $view['fulfillmentStatus']);
    }

    public function test_five_transient_failures_exhaust_retries_without_replacing_payment_or_grant_evidence(): void
    {
        $f = F::paid($this->gateway); $before = FinalizationFixtures::retained();
        $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->renderer->onRender = fn () => throw new ContractIssuanceException('render_failed');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertSame($attempt === 5 ? 'quarantined' : 'retry', app(RenderTestContract::class)->handle($request->id));
            $work = ContractRenderWork::sole(); $this->assertSame($attempt, $work->attempts);
            if ($attempt < 5) { $this->travelTo($work->next_attempt_at); }
        }
        $this->assertSame('retry_exhausted', ContractRenderWork::sole()->reason);
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        $this->assertCount(5, $this->renderer->calls); $this->assertSame($before, FinalizationFixtures::retained());
    }

    public function test_database_failure_after_private_write_leaves_an_orphan_and_retry_uses_a_new_claim_path(): void
    {
        $f = F::paid($this->gateway); $before = FinalizationFixtures::retained();
        $request = app(RequestTestContract::class)->handle($f['grant']->id); $armed = true;
        Event::listen('eloquent.created: '.GrantContract::class, function () use (&$armed): void {
            if ($armed) { $armed = false; throw new RuntimeException('SYNTHETIC-POST-WRITE-ROLLBACK'); }
        });
        $this->assertSame('retry', app(RenderTestContract::class)->handle($request->id));
        $this->assertDatabaseCount('grant_contracts', 0);
        $files = Storage::disk('local')->allFiles('contracts/test'); $this->assertCount(1, $files);
        $orphanBytes = Storage::disk('local')->get($files[0]);
        $this->travelTo(ContractRenderWork::sole()->next_attempt_at);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertCount(2, Storage::disk('local')->allFiles('contracts/test'));
        $this->assertNotSame($files[0], GrantContract::sole()->storage_path);
        $this->assertSame($orphanBytes, Storage::disk('local')->get($files[0]));
        $this->assertSame($before, FinalizationFixtures::retained());
    }

    public function test_a_lease_that_expires_during_render_cannot_publish_its_document(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->renderer->onRender = function (array $input, array $profile) {
            $this->travelTo(now()->addSeconds(301));
            return F::syntheticResult($input, $profile);
        };
        $this->assertSame('stale', app(RenderTestContract::class)->handle($request->id));
        $work = ContractRenderWork::sole(); $this->assertSame('processing', $work->state); $this->assertSame(1, $work->attempts);
        $this->assertDatabaseCount('grant_contracts', 0); $this->renderer->onRender = null;
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame(2, ContractRenderWork::sole()->attempts); $this->assertDatabaseCount('grant_contracts', 1);
    }

    public function test_last_attempt_worker_crash_is_quarantined_by_expired_lease_recovery(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $claims = app(ContractWork::class);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $claim = $claims->claim($request->id); $this->assertNotNull($claim);
            $this->assertSame($attempt, ContractRenderWork::sole()->attempts);
            $this->travelTo(ContractRenderWork::sole()->lease_expires_at);
        }
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame('retry_exhausted', ContractRenderWork::sole()->reason);
        $this->assertSame([], $this->renderer->calls); $this->assertDatabaseCount('grant_contracts', 0);
    }

    public function test_mixed_cart_only_reports_issued_when_every_grant_has_an_original_document(): void
    {
        $f = F::paid($this->gateway, true); $before = FinalizationFixtures::retained();
        $first = app(RequestTestContract::class)->handle($f['grants'][0]->id);
        $second = app(RequestTestContract::class)->handle($f['grants'][1]->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($first->id));
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('pending', $view['contractStatus']); $this->assertSame('pending_contracts', $view['fulfillmentStatus']);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($second->id));
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('issued', $view['contractStatus']); $this->assertSame('pending_activation', $view['fulfillmentStatus']);
        $this->assertDatabaseCount('grant_contracts', 2); $this->assertDatabaseCount('contract_render_requests', 2);
        $this->assertSame($before, FinalizationFixtures::retained());
    }

    public function test_issuance_uses_retained_grant_input_after_new_commerce_is_disabled(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id); $before = FinalizationFixtures::retained();
        config(['payments.stripe.checkout_enabled' => false, 'payments.stripe.processing_enabled' => false,
            'payments.stripe.finalization_enabled' => false, 'payments.stripe.finalization_policy' => null,
            'commerce.test_order_policy' => null, 'commerce.test_checkout_policy' => null]);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $input = json_decode(Crypt::decryptString($f['grant']->render_input_ciphertext), true, 128, JSON_THROW_ON_ERROR);
        $this->assertSame($input, $this->renderer->calls[0]['input']);
        $this->assertSame($before, FinalizationFixtures::retained());
    }

    public function test_owner_status_is_metadata_only_read_only_and_private_even_when_original_storage_is_unavailable(): void
    {
        $f = F::finalize(FinalizationFixtures::ownedHttp($this, $this->gateway));
        $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id)); $contract = GrantContract::sole();
        $this->assertTrue(unlink(Storage::disk('local')->path($contract->storage_path)));
        $before = F::retained(); $calls = $this->gateway->calls; $audits = DB::table('audit_events')->count();
        foreach (['/status', '/checkout'] as $suffix) {
            $response = $this->getJson('/orders/'.$f['order']->public_id.$suffix)->assertOk();
            $prefix = $suffix === '/status' ? 'order' : 'checkout';
            $response->assertJsonPath($prefix.'.contractStatus', 'issued')->assertJsonPath($prefix.'.fulfillmentStatus', 'pending_activation');
            $response->assertHeader('Cache-Control', 'no-store, private');
            foreach ([...array_values(OrderFixtures::buyer()), CheckoutFixtures::ACCOUNT, PaymentFixtures::PAYMENT,
                $contract->storage_path, $contract->pdf_hash, $request->input_hash, $f['order']->owner_key,
                $f['grant']->render_input_ciphertext, 'downloadUrl'] as $private) { $response->assertDontSee($private, false); }
        }
        $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->gateway->calls);
        $this->assertSame($audits, DB::table('audit_events')->count()); $this->assertCount(1, $this->renderer->calls);
        $this->flushSession();
        $foreign = $this->getJson('/orders/'.$f['order']->public_id.'/status')->assertNotFound();
        $unknown = $this->getJson('/orders/'.Str::uuid().'/status')->assertNotFound();
        $this->assertSame($foreign->json(), $unknown->json());
    }

    public static function unavailableIssuancePolicies(): array
    {
        return [['disabled'], ['string_enabled'], ['missing_policy'], ['extra_policy'], ['live'], ['production'], ['staging']];
    }

    #[DataProvider('unavailableIssuancePolicies')]
    public function test_strict_issuance_policy_is_separate_from_payment_and_finalization_flags(string $scenario): void
    {
        $f = F::paid($this->gateway); $before = F::retained(); $environment = $this->app->environment();
        $policy = F::policy(); $policy['unapproved'] = true;
        try {
            match ($scenario) {
                'disabled' => config(['contracts.test_issuance_enabled' => false]),
                'string_enabled' => config(['contracts.test_issuance_enabled' => 'true']),
                'missing_policy' => config(['contracts.test_issuance_policy' => null]),
                'extra_policy' => config(['contracts.test_issuance_policy' => json_encode($policy, JSON_THROW_ON_ERROR)]),
                'live' => config(['payments.stripe.mode' => 'live']),
                'production', 'staging' => $this->app->detectEnvironment(fn () => $scenario),
            };
            $this->assertIssuanceRejected(fn () => app(RequestTestContract::class)->handle($f['grant']->id), 'unavailable');
            $this->assertSame($before, F::retained()); $this->assertSame([], $this->renderer->calls);
        } finally { $this->app->detectEnvironment(fn () => $environment); }
    }

    public function test_nonexistent_grant_and_wrong_account_cannot_create_a_render_request(): void
    {
        $f = F::paid($this->gateway); $before = F::retained();
        $this->assertIssuanceRejected(fn () => app(RequestTestContract::class)->handle(999999999), 'unavailable');
        config(['payments.stripe.account_id' => 'acct_ANOTHERACCOUNT']);
        $this->assertIssuanceRejected(fn () => app(RequestTestContract::class)->handle($f['grant']->id), 'unavailable');
        $this->assertSame($before, F::retained()); $this->assertDatabaseCount('contract_render_requests', 0);
    }

    public function test_request_rejects_reencrypted_semantically_changed_grant_before_any_render_or_write(): void
    {
        $f = F::paid($this->gateway); $before = F::retained();
        Event::listen('eloquent.retrieved: '.LicenseGrant::class, function ($grant): void {
            $input = json_decode(Crypt::decryptString($grant->render_input_ciphertext), true, 128, JSON_THROW_ON_ERROR);
            $input['buyer']['identity'] = 'verified_customer';
            $cipher = Crypt::encryptString(CanonicalJson::encode($input));
            $grant->render_input_ciphertext = $cipher; $grant->render_input_hash = hash('sha256', $cipher);
        });
        $this->assertIssuanceRejected(fn () => app(RequestTestContract::class)->handle($f['grant']->id));
        $this->assertSame($before, F::retained()); $this->assertSame([], $this->renderer->calls);
    }

    public function test_missing_render_outbox_never_infers_permission_from_a_paid_flag_alone(): void
    {
        $f = F::paid($this->gateway); $before = F::retained(); $scopes = FulfillmentOutbox::getAllGlobalScopes();
        FulfillmentOutbox::addGlobalScope('synthetic-missing-render-effect', fn ($query) => $query->whereRaw('1 = 0'));
        try { $this->assertIssuanceRejected(fn () => app(RequestTestContract::class)->handle($f['grant']->id)); }
        finally { FulfillmentOutbox::setAllGlobalScopes($scopes); }
        $this->assertSame($before, F::retained()); $this->assertDatabaseCount('contract_render_requests', 0);
    }

    public function test_missing_work_evidence_is_not_repaired_or_exposed_as_pending_issuance(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $before = F::retained(); $scopes = ContractRenderWork::getAllGlobalScopes(); $created = 0;
        Event::listen('eloquent.creating: '.ContractRenderWork::class, function () use (&$created): void { $created++; });
        ContractRenderWork::addGlobalScope('synthetic-missing-work', fn ($query) => $query->whereRaw('1 = 0'));
        try {
            $this->assertIssuanceRejected(fn () => app(RequestTestContract::class)->handle($f['grant']->id), 'evidence_changed');
            $this->assertSame('changed', app(RenderTestContract::class)->handle($request->id));
            try {
                app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
                $this->fail('Missing issuance work was exposed as an intact contract status.');
            } catch (QuoteException $error) { $this->assertSame('ORDER_CHANGED', $error->errorCode); }
        } finally { ContractRenderWork::setAllGlobalScopes($scopes); }
        $this->assertSame(0, $created); $this->assertSame($before, F::retained());
        $this->assertSame([], $this->renderer->calls); $this->assertSame([], Storage::disk('local')->allFiles('contracts/test'));
    }

    private function assertIssuanceRejected(callable $operation, ?string $reason = null): void
    {
        try { $operation(); $this->fail('Unsafe contract issuance was accepted.'); }
        catch (ContractIssuanceException $error) {
            if ($reason !== null) { $this->assertSame($reason, $error->reason); }
            foreach ([OrderFixtures::buyer()['email'], PaymentFixtures::PAYMENT, 'SQLSTATE'] as $private) {
                $this->assertStringNotContainsString($private, $error->getMessage());
            }
        }
    }

    public function test_caller_transaction_prevents_both_request_and_render_io(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id); $before = F::retained();
        DB::beginTransaction();
        try {
            $this->assertIssuanceRejected(fn () => app(RequestTestContract::class)->handle($f['grant']->id), 'unavailable');
            $this->assertSame('unavailable', app(RenderTestContract::class)->handle($request->id));
        } finally { DB::rollBack(); }
        $this->assertSame($before, F::retained()); $this->assertSame([], $this->renderer->calls);
        $this->assertSame([], Storage::disk('local')->allFiles('contracts/test'));
    }

    public function test_later_catalog_edits_and_configuration_withdrawal_preserve_original_pdf_metadata(): void
    {
        $f = F::finalize(FinalizationFixtures::confirmed($this->gateway));
        $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $contract = GrantContract::sole(); $before = $contract->getAttributes(); $bytes = app(ContractFiles::class)->verify($contract);
        $offer = app(SaveOfferDraft::class)->handle($f['offer'], ['price_minor' => 7999], $f['actor']);
        app(PublishOffer::class)->handle($offer, $f['actor']);
        config(['contracts.test_issuance_enabled' => false, 'contracts.test_issuance_policy' => null]);
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('issued', $view['contractStatus']); $this->assertSame('pending_activation', $view['fulfillmentStatus']);
        $this->assertSame($before, GrantContract::sole()->getAttributes()); $this->assertSame($bytes, app(ContractFiles::class)->verify($contract));
        $this->assertCount(1, $this->renderer->calls);
    }

    public function test_async_id_only_dispatch_waits_for_commit_and_is_discarded_on_rollback(): void
    {
        $f = F::paid($this->gateway); Queue::fake(); config(['queue.default' => 'database']);
        DB::beginTransaction(); app(DispatchTestContract::class)->handle($f['grant']->id);
        Queue::assertNothingPushed(); DB::rollBack(); Queue::assertNothingPushed();
        DB::transaction(function () use ($f): void { app(DispatchTestContract::class)->handle($f['grant']->id); Queue::assertNothingPushed(); });
        Queue::assertPushed(IssueTestContractJob::class, function ($job) use ($f): bool {
            $this->assertSame($f['grant']->id, $job->grantId); $this->assertTrue($job->afterCommit);
            $this->assertSame('contracts', $job->queue); $this->assertSame(1, $job->tries); $this->assertSame(90, $job->timeout);
            foreach ([OrderFixtures::buyer()['email'], $f['grant']->render_input_ciphertext, $f['order']->owner_key] as $private) {
                $this->assertStringNotContainsString($private, serialize($job));
            }
            return true;
        });
        $this->assertDatabaseCount('grant_contracts', 0); $this->assertSame([], $this->renderer->calls);
    }

    public function test_sync_and_failed_dispatch_are_recovered_by_the_grant_scanner(): void
    {
        $f = F::paid($this->gateway); Queue::fake(); config(['queue.default' => 'sync']);
        app(DispatchTestContract::class)->handle($f['grant']->id); Queue::assertNothingPushed();
        config(['queue.default' => 'database']); Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('SYNTHETIC-PRIVATE-QUEUE-FAILURE'));
        app(DispatchTestContract::class)->handle($f['grant']->id);
        $this->assertDatabaseCount('grant_contracts', 0);
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertDatabaseCount('contract_render_requests', 1);
    }

    public function test_after_commit_dispatch_keeps_the_original_async_connection_when_default_changes(): void
    {
        $f = F::paid($this->gateway); Queue::fake(); config(['queue.default' => 'database']);
        DB::transaction(function () use ($f): void {
            app(DispatchTestContract::class)->handle($f['grant']->id);
            config(['queue.default' => 'sync']); Queue::assertNothingPushed();
        });
        Queue::assertPushed(IssueTestContractJob::class, fn ($job) => $job->connection === 'database' && $job->grantId === $f['grant']->id);
        $this->assertSame([], $this->renderer->calls); $this->assertDatabaseCount('contract_render_requests', 0);
    }

    public function test_after_commit_dispatch_rechecks_withdrawn_issuance_policy(): void
    {
        $f = F::paid($this->gateway); Queue::fake(); config(['queue.default' => 'database']);
        DB::transaction(function () use ($f): void {
            app(DispatchTestContract::class)->handle($f['grant']->id);
            config(['contracts.test_issuance_enabled' => false]); Queue::assertNothingPushed();
        });
        Queue::assertNothingPushed(); $this->assertSame([], $this->renderer->calls);
        $this->assertDatabaseCount('contract_render_requests', 0);
    }

    public function test_scanner_skips_an_active_claim_then_recovers_its_expired_lease(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $claim = app(ContractWork::class)->claim($request->id); $this->assertNotNull($claim); $before = F::retained();
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
        $this->assertStringNotContainsString('NEXT_AFTER=', Artisan::output()); $this->assertSame($before, F::retained());
        $this->assertSame([], $this->renderer->calls);
        $this->travelTo(ContractRenderWork::sole()->lease_expires_at);
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
        $this->assertStringContainsString($f['grant']->public_id.' ready', Artisan::output());
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertSame(2, ContractRenderWork::sole()->attempts);
    }

    public function test_scanner_does_not_replay_quarantined_work(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->renderer->onRender = fn () => throw new ContractIssuanceException('unsupported_input');
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id)); $before = F::retained();
        $this->travelTo(now()->addDay());
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
        $this->assertStringNotContainsString('NEXT_AFTER=', Artisan::output());
        $this->assertSame($before, F::retained()); $this->assertCount(1, $this->renderer->calls);
    }

    public function test_scanner_rejects_unknown_and_foreign_account_locators_without_private_output(): void
    {
        $f = F::paid($this->gateway); $before = F::retained();
        $this->assertNotSame(0, Artisan::call('vasey:issue-test-contracts', ['--after' => (string) Str::uuid()]));
        config(['payments.stripe.account_id' => 'acct_ANOTHERACCOUNT']);
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
        $this->assertStringNotContainsString($f['grant']->public_id, Artisan::output());
        foreach ([['grant' => $f['grant']->public_id], ['--after' => $f['grant']->public_id]] as $arguments) {
            $this->assertNotSame(0, Artisan::call('vasey:issue-test-contracts', $arguments));
            $this->assertStringNotContainsString($f['grant']->public_id, Artisan::output());
            $this->assertStringNotContainsString(OrderFixtures::buyer()['email'], Artisan::output());
        }
        $this->assertSame($before, F::retained()); $this->assertSame([], $this->renderer->calls);
    }

    public function test_scanner_advances_past_missing_work_evidence_without_recreating_it(): void
    {
        $f = F::paid($this->gateway, true); $first = $f['grants'][0]; $second = $f['grants'][1];
        $request = app(RequestTestContract::class)->handle($first->id); $before = F::retained();
        $scopes = ContractRenderWork::getAllGlobalScopes(); $created = 0;
        Event::listen('eloquent.creating: '.ContractRenderWork::class, function () use (&$created): void { $created++; });
        ContractRenderWork::addGlobalScope('synthetic-missing-first-work', fn ($query) => $query->where('contract_render_request_id', '!=', $request->id));
        try {
            $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
            $this->assertStringContainsString($first->public_id.' changed', Artisan::output());
            $this->assertStringContainsString('NEXT_AFTER='.$first->public_id, Artisan::output());
            $this->assertSame(0, $created); $this->assertSame($before, F::retained()); $this->assertSame([], $this->renderer->calls);
            $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1', '--after' => $first->public_id]));
            $this->assertStringContainsString($second->public_id.' ready', Artisan::output());
            $this->assertStringContainsString('NEXT_AFTER='.$second->public_id, Artisan::output());
        } finally { ContractRenderWork::setAllGlobalScopes($scopes); }
        $this->assertSame(1, $created); $this->assertSame($second->id, GrantContract::sole()->license_grant_id);
        $this->assertSame('pending', ContractRenderWork::where('contract_render_request_id', $request->id)->sole()->state);
        $this->assertSame(0, ContractRenderWork::where('contract_render_request_id', $request->id)->sole()->attempts);
    }

    public function test_command_cursor_advances_past_failed_first_grant_and_only_prints_safe_locators(): void
    {
        $f = F::paid($this->gateway, true); $first = $f['grants'][0]; $second = $f['grants'][1];
        $this->renderer->onRender = function (array $input, array $profile) use ($first) {
            if ($input['grant_id'] === $first->public_id) { throw new ContractIssuanceException('render_failed'); }
            return F::syntheticResult($input, $profile);
        };
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1'])); $output = Artisan::output();
        $this->assertStringContainsString('retry', $output); $this->assertStringContainsString('NEXT_AFTER='.$first->public_id, $output);
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1', '--after' => $first->public_id]));
        $this->assertStringContainsString('NEXT_AFTER='.$second->public_id, Artisan::output());
        $this->assertSame($second->id, GrantContract::sole()->license_grant_id);
        foreach ([...array_values(OrderFixtures::buyer()), $f['order']->owner_key, PaymentFixtures::PAYMENT,
            $first->render_input_hash, 'contracts/test/', CheckoutFixtures::ACCOUNT] as $private) {
            $this->assertStringNotContainsString($private, $output.Artisan::output());
        }
        $this->renderer->onRender = null; $this->travelTo(ContractRenderWork::where('state', 'retry')->sole()->next_attempt_at);
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
        $this->assertDatabaseCount('grant_contracts', 2);
        $this->assertSame(0, Artisan::call('vasey:issue-test-contracts', ['--limit' => '1']));
        $this->assertStringNotContainsString('NEXT_AFTER=', Artisan::output());
        foreach ([['grant' => '1'], ['--after' => 'bad'], ['--limit' => '0'], ['--limit' => '101'],
            ['grant' => $first->public_id, '--after' => $first->public_id]] as $arguments) {
            $this->assertNotSame(0, Artisan::call('vasey:issue-test-contracts', $arguments));
        }
    }

    public function test_duplicate_id_only_jobs_verify_the_same_original_without_rendering_twice(): void
    {
        $f = F::paid($this->gateway); $job = new IssueTestContractJob($f['grant']->id);
        $this->app->call([$job, 'handle']);
        $this->assertDatabaseCount('grant_contracts', 1); $before = F::retained();
        $this->app->call([(new IssueTestContractJob($f['grant']->id)), 'handle']);
        $this->assertSame($before, F::retained()); $this->assertCount(1, $this->renderer->calls);
        $this->assertSame('pending', PendingEntitlement::sole()->state);
    }

    public static function changedRequestEvidence(): array
    {
        return [['input_hash'], ['profile_hash'], ['profile_semantics'], ['outbox_binding'], ['canonicalization']];
    }

    #[DataProvider('changedRequestEvidence')]
    public function test_changed_retained_render_request_never_reaches_renderer_or_private_storage(string $scenario): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $before = FinalizationFixtures::retained();
        Event::listen('eloquent.retrieved: '.ContractRenderRequest::class, function ($record) use ($scenario): void {
            match ($scenario) {
                'input_hash' => $record->input_hash = str_repeat('0', 64),
                'profile_hash' => $record->profile_hash = str_repeat('0', 64),
                'outbox_binding' => $record->fulfillment_outbox_id = 999999999,
                'canonicalization' => $record->canonicalization_version = 'untrusted-canonicalization',
                'profile_semantics' => (function () use ($record): void {
                    $profile = $record->profile; $profile['remote_resources'] = true;
                    $record->profile = $profile; $record->profile_hash = CanonicalJson::hash($profile);
                })(),
            };
        });
        $this->assertSame('changed', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame([], $this->renderer->calls); $this->assertDatabaseCount('grant_contracts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('contracts/test'));
        $this->assertSame($before, FinalizationFixtures::retained());
    }

    public function test_completed_result_metadata_corruption_cannot_be_hidden_by_valid_pdf_bytes(): void
    {
        $f = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($f['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id)); $before = F::retained();
        Event::listen('eloquent.retrieved: '.GrantContract::class, function ($contract): void { $contract->input_hash = str_repeat('0', 64); });
        $this->assertSame('changed', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($before, F::retained()); $this->assertCount(1, $this->renderer->calls);
        try { app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER); $this->fail('Corrupt original metadata was exposed as issued.'); }
        catch (QuoteException $error) { $this->assertSame('ORDER_CHANGED', $error->errorCode); }
    }

    public function test_fresh_paid_mixed_cart_dispatches_each_grant_once_after_finalization_commit(): void
    {
        $confirmed = FinalizationFixtures::confirmedMixedCart($this->gateway); Queue::fake(); config(['queue.default' => 'database']);
        $f = F::finalize($confirmed);
        Queue::assertPushed(IssueTestContractJob::class, 2);
        foreach ($f['grants'] as $grant) {
            Queue::assertPushed(IssueTestContractJob::class, function ($job) use ($grant): bool {
                $this->assertSame(0, DB::transactionLevel());
                return $job->grantId === $grant->id;
            });
        }
        $this->assertSame('paid', app(\App\Domain\Commerce\Finalization\FinalizeTestPayment::class)->handle($f['payment']->id));
        Queue::assertPushed(IssueTestContractJob::class, 2);
        $this->assertDatabaseCount('contract_render_requests', 0); $this->assertDatabaseCount('grant_contracts', 0);
    }
}
