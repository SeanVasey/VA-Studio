<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\IsolatedContractRenderer;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\ReadGrantContract;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use App\Support\CanonicalJson;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ContractFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestContractSuccessorActivationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, new IsolatedContractRenderer);
    }

    private function successorRuntime(): bool
    {
        return InstalledVersions::getReference('tecnickcom/tc-lib-pdf') === 'd417129fad37d49dc9fc0d1e229e9740e1c774a3'
            && InstalledVersions::getReference('tecnickcom/tc-lib-pdf-font') === 'a78b8e0ac9284d1594b6ba68488cf65c3e958f7b';
    }

    /** Synthetic retained v1 record over a real fixture paid-grant/outbox, without pretending v1 runtime exists. */
    private function retainedV1Request(array $paid): ContractRenderRequest
    {
        $profile = ContractRenderProfileRegistry::metadata('test-buyer-pdf-v1');
        $request = ContractRenderRequest::create([
            'public_id' => (string) Str::uuid(), 'license_grant_id' => $paid['grant']->id,
            'fulfillment_outbox_id' => FulfillmentOutbox::where('license_grant_id', $paid['grant']->id)->sole()->id,
            'input_hash' => $paid['grant']->render_input_hash, 'profile' => $profile,
            'profile_hash' => CanonicalJson::hash($profile), 'canonicalization_version' => CanonicalJson::VERSION,
            'document_public_id' => (string) Str::uuid(), 'created_at' => now(),
        ]);
        ContractRenderWork::create(['contract_render_request_id' => $request->id, 'state' => 'pending',
            'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $request;
    }

    public function test_explicit_legacy_policy_is_refused_before_current_request_capture(): void
    {
        $paid = F::paid($this->gateway);
        $before = F::retained();
        config(['contracts.test_issuance_policy' => json_encode(ContractIssuancePolicy::V1_CONTRACT, JSON_THROW_ON_ERROR)]);
        try {
            app(RequestTestContract::class)->handle($paid['grant']->id);
            $this->fail('Legacy config cannot authorize new v2 issuance.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('unavailable', $error->reason);
        }
        $this->assertSame($before, F::retained());
        $this->assertDatabaseCount('contract_render_requests', 0);
    }

    public function test_exact_v2_policy_captures_real_request_and_worker_preserves_one_original(): void
    {
        $paid = F::paid($this->gateway);
        $business = FinalizationFixtures::retained();
        $calls = $this->gateway->calls;
        $this->assertSame(ContractIssuancePolicy::V2_CONTRACT, app(ContractIssuancePolicy::class)->current());
        if (! $this->successorRuntime()) {
            try {
                app(RequestTestContract::class)->handle($paid['grant']->id);
                $this->fail('Current v2 capture must refuse the old runtime.');
            } catch (ContractIssuanceException $error) {
                $this->assertSame('profile_changed', $error->reason);
            }
            $this->assertDatabaseCount('contract_render_requests', 0);
            $this->assertSame($business, FinalizationFixtures::retained());

            return;
        }
        $request = app(RequestTestContract::class)->handle($paid['grant']->id);
        $this->assertSame('test-buyer-pdf-v2', $request->profile['version']);
        $this->assertSame(ContractIssuancePolicy::V2_CONTRACT, $request->profile['issuance_policy']);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $original = app(ReadGrantContract::class)->forRequest($request);
        $bytes = app(ContractFiles::class)->verify($original);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('8.76.3', $bytes);
        $this->assertSame(hash('sha256', $bytes), $original->pdf_hash);
        $retained = F::retained();
        $this->assertSame($request->id, app(RequestTestContract::class)->handle($paid['grant']->id)->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($retained, F::retained());
        $this->assertSame($bytes, app(ContractFiles::class)->verify($original));
        $this->assertDatabaseCount('grant_contracts', 1);
        $this->assertSame('pending', PendingEntitlement::sole()->state);
        $this->assertSame($business, FinalizationFixtures::retained());
        $this->assertSame($calls, $this->gateway->calls);
    }

    public function test_pending_v1_preserves_request_and_never_substitutes_current_v2_on_absent_runtime(): void
    {
        $paid = F::paid($this->gateway);
        $request = $this->retainedV1Request($paid);
        $requestBefore = $request->getRawOriginal();
        ksort($requestBefore);
        $business = FinalizationFixtures::retained();
        $calls = $this->gateway->calls;
        $this->assertSame($request->id, app(RequestTestContract::class)->handle($paid['grant']->id)->id);
        $this->assertSame($this->successorRuntime() ? 'quarantined' : 'ready', app(RenderTestContract::class)->handle($request->id));
        $work = ContractRenderWork::sole();
        $this->assertSame(1, $work->attempts);
        if ($this->successorRuntime()) {
            $this->assertSame('profile_changed', $work->reason);
            $this->assertDatabaseCount('grant_contracts', 0);
            $this->travelTo(now()->addDays(2));
            $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
            $this->assertSame(1, ContractRenderWork::sole()->attempts);
        } else {
            $this->assertStringContainsString('8.76.2', app(ContractFiles::class)->verify(GrantContract::sole()));
        }
        $requestAfter = $request->fresh()->getRawOriginal();
        ksort($requestAfter);
        $this->assertSame($requestBefore, $requestAfter);
        $this->assertSame($business, FinalizationFixtures::retained());
        $this->assertSame($calls, $this->gateway->calls);
        $this->assertSame('pending', PendingEntitlement::sole()->state);
    }

    public function test_retained_v1_original_reads_remain_available_and_missing_bytes_are_restore_only(): void
    {
        $paid = F::paid($this->gateway);
        $request = $this->retainedV1Request($paid);
        // Synthetic historical PDF tests immutable storage/read behavior, not old-runtime rendering availability.
        $this->app->instance(ContractRenderer::class, F::renderer());
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->app->instance(ContractRenderer::class, new IsolatedContractRenderer);
        $original = app(ReadGrantContract::class)->forRequest($request);
        $bytes = app(ContractFiles::class)->verify($original);
        $before = F::retained();
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($before, F::retained());
        $this->assertSame($bytes, app(ContractFiles::class)->verify($original));
        $path = Storage::disk('local')->path($original->storage_path);
        chmod($path, 0600);
        unlink($path);
        $this->assertSame('original_unavailable', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame($before, F::retained());
        $this->assertSame($original->id, app(ReadGrantContract::class)->forRequest($request)->id);
        $this->assertSame('completed', ContractRenderWork::sole()->state);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('grant_contracts', 1);
    }
}
