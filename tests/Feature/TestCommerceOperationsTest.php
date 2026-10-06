<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Operations\ReadTestCommerceOperations;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use App\Filament\Resources\TestContractIssuanceResource;
use App\Filament\Resources\TestContractIssuanceResource\Pages\ListTestContractIssuance;
use App\Filament\Resources\TestPaymentExceptionResource;
use App\Filament\Resources\TestPaymentExceptionResource\Pages\ListTestPaymentExceptions;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures as F;
use Tests\Support\OrderFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestCommerceOperationsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;
    private ContractRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->gateway = PaymentFixtures::gateway(); $this->renderer = F::renderer();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, $this->renderer);
    }

    public function test_lists_and_reader_require_verified_staff(): void
    {
        foreach (['/admin/test-payment-exceptions', '/admin/test-contract-issuance'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
        $unverified = LicenseFixtures::admin(); $unverified->email_verified_at = null; $unverified->save();
        foreach ([User::factory()->create(), $unverified] as $denied) {
            $this->actingAs($denied);
            foreach (['/admin/test-payment-exceptions', '/admin/test-contract-issuance'] as $url) {
                $this->get($url)->assertForbidden();
            }
            foreach (['exceptions', 'contracts'] as $method) {
                try { app(ReadTestCommerceOperations::class)->{$method}()->get(); $this->fail('Unauthorized metadata read succeeded.'); }
                catch (AuthorizationException) { }
            }
        }
    }

    public function test_livewire_refresh_reauthorizes_after_staff_access_is_removed(): void
    {
        $actor = LicenseFixtures::admin(); $this->actingAs($actor);
        $exceptions = Livewire::test(ListTestPaymentExceptions::class)->assertSuccessful();
        $contracts = Livewire::test(ListTestContractIssuance::class)->assertSuccessful();
        $actor->forceFill(['is_admin' => false])->save(); $this->actingAs($actor->fresh());
        $exceptions->call('$refresh')->assertForbidden(); $contracts->call('$refresh')->assertForbidden();
    }

    public function test_livewire_refresh_rejects_required_mfa_withdrawal_on_both_lists(): void
    {
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        foreach ([ListTestPaymentExceptions::class, ListTestContractIssuance::class] as $page) {
            $actor = LicenseFixtures::admin();
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->actingAs($actor->fresh());
            $component = Livewire::test($page)->assertSuccessful();
            $component->call('$refresh')->assertSuccessful();
            $actor->saveAppAuthenticationSecret(null);
            $this->actingAs($actor->fresh());
            $component->call('$refresh')->assertForbidden();
        }
    }

    public function test_livewire_refresh_applies_newly_required_mfa_on_both_lists(): void
    {
        $panel = Filament::getPanel('admin');
        foreach ([ListTestPaymentExceptions::class, ListTestContractIssuance::class] as $page) {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
            $this->actingAs(LicenseFixtures::admin());
            $component = Livewire::test($page)->assertSuccessful();
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
            $component->call('$refresh')->assertForbidden();
        }
    }

    public function test_metadata_lists_show_retained_identifiers_without_private_input_or_side_effects(): void
    {
        $paid = F::paid($this->gateway); $exception = $this->exception();
        // Fixture construction queues media processing; measure dispatch only during the read boundary.
        Queue::fake();
        $this->actingAs(LicenseFixtures::admin()); $before = F::retained();
        $providerCalls = $this->gateway->calls; $audits = DB::table('audit_events')->count();
        $exceptions = $this->get('/admin/test-payment-exceptions')->assertOk()
            ->assertSee($exception['order']->public_id)->assertSee('Confirmation observed too late')
            ->assertDontSee($paid['order']->public_id);
        $contracts = $this->get('/admin/test-contract-issuance')->assertOk()
            ->assertSee($paid['grant']->public_id)->assertSee($paid['order']->public_id)->assertSee('Not requested')
            ->assertDontSee($exception['order']->public_id);
        foreach ([$exceptions, $contracts] as $response) {
            foreach ([$paid['order']->payload_ciphertext, $paid['grant']->render_input_ciphertext,
                $paid['grant']->render_input_hash, $paid['payment']->provider_payment_intent_id,
                $paid['payment']->account_id, OrderFixtures::buyer()['legalName'], OrderFixtures::buyer()['email'], 'storage_path', 'claim_token'] as $private) {
                $response->assertDontSee($private, false);
            }
        }
        $rows = app(ReadTestCommerceOperations::class)->contracts()->get();
        $this->assertSame(['id', 'public_id', 'order_public_id', 'created_at', 'request_public_id', 'attempts',
            'next_attempt_at', 'lease_expires_at', 'work_updated_at', 'issuance_state', 'document_public_id', 'issued_at', 'work_reason'],
            array_keys($rows->sole()->getAttributes()));
        $this->assertSame($before, F::retained()); $this->assertSame($providerCalls, $this->gateway->calls);
        $this->assertSame($audits, DB::table('audit_events')->count()); $this->assertSame([], $this->renderer->calls);
        Queue::assertNothingPushed();
    }

    public function test_retained_lists_survive_flag_withdrawal_but_never_expand_an_invalid_or_different_account_scope(): void
    {
        $paid = F::paid($this->gateway); $exception = $this->exception(); $this->actingAs(LicenseFixtures::admin());
        config(['contracts.test_issuance_enabled' => false, 'contracts.test_issuance_policy' => null,
            'payments.stripe.finalization_enabled' => false, 'payments.stripe.finalization_policy' => null,
            'payments.stripe.processing_enabled' => false, 'payments.stripe.checkout_enabled' => false]);
        $reader = app(ReadTestCommerceOperations::class);
        $this->assertSame($paid['grant']->public_id, $reader->contracts()->sole()->public_id);
        $this->assertSame($exception['order']->public_id, $reader->exceptions()->sole()->order_public_id);
        foreach ([null, '', 'invalid', 'acct_OTHERTEST'] as $account) {
            config(['payments.stripe.account_id' => $account]);
            $this->assertCount(0, $reader->contracts()->get()); $this->assertCount(0, $reader->exceptions()->get());
        }
    }

    public function test_issuance_view_distinguishes_unrequested_waiting_claimed_retry_and_quarantined_work(): void
    {
        $paid = F::paid($this->gateway); $this->actingAs(LicenseFixtures::admin());
        $this->assertState('missing_request');
        $request = app(RequestTestContract::class)->handle($paid['grant']->id); $this->assertState('pending');
        $claim = app(ContractWork::class)->claim($request->id); $this->assertState('processing');
        $this->assertSame('retry', app(ContractWork::class)->fail($request->id, $claim['token'], 'storage_failed'));
        $row = $this->assertState('retry'); $this->assertSame('storage_failed', $row->work_reason);
        $this->assertSame(1, (int) $row->attempts); $this->assertNotNull($row->next_attempt_at);
        $this->travelTo(now()->addSeconds(60)); $claim = app(ContractWork::class)->claim($request->id);
        app(ContractWork::class)->fail($request->id, $claim['token'], 'unsupported_input', true);
        $row = $this->assertState('quarantined'); $this->assertSame('unsupported_input', $row->work_reason);
        Livewire::test(ListTestContractIssuance::class)->assertSee('Needs attention')->assertSee('Unsupported input');
    }

    public function test_recorded_manifest_does_not_claim_current_file_health_or_authorize_downloads(): void
    {
        $paid = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($paid['grant']->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $original = GrantContract::sole(); $this->actingAs(LicenseFixtures::admin());
        $this->assertTrue(unlink(Storage::disk('local')->path($original->storage_path)));
        $before = F::retained(); $calls = $this->renderer->calls;
        $row = $this->assertState('recorded'); $this->assertSame($original->public_id, $row->document_public_id);
        $this->get('/admin/test-contract-issuance')->assertOk()->assertSee('Original recorded')
            ->assertSee('file health is not checked')->assertDontSee($original->storage_path)->assertDontSee($original->pdf_hash);
        $this->assertSame($before, F::retained()); $this->assertSame($calls, $this->renderer->calls);
    }

    public function test_cross_bound_original_is_never_presented_as_recorded(): void
    {
        $paid = F::paid($this->gateway, true); $first = app(RequestTestContract::class)->handle($paid['grants'][0]->id);
        $second = app(RequestTestContract::class)->handle($paid['grants'][1]->id);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($first->id));
        $this->actingAs(LicenseFixtures::admin());
        // Deliberate corruption in a disposable database; normal fixtures always use guarded commands.
        DB::unprepared('DROP TRIGGER grant_contracts_immutable_update');
        DB::table('grant_contracts')->where('contract_render_request_id', $first->id)
            ->update(['contract_render_request_id' => $second->id]);
        foreach (app(ReadTestCommerceOperations::class)->contracts()->get() as $row) {
            $this->assertSame('attention', $row->issuance_state); $this->assertNull($row->document_public_id);
        }
    }

    public function test_a_request_without_work_is_evidence_needing_attention(): void
    {
        $paid = F::paid($this->gateway); $request = app(RequestTestContract::class)->handle($paid['grant']->id);
        $this->actingAs(LicenseFixtures::admin());
        // Deliberately remove coordination in this disposable corruption fixture.
        DB::unprepared('DROP TRIGGER contract_render_work_retain');
        DB::table('contract_render_work')->where('contract_render_request_id', $request->id)->delete();
        $this->assertState('attention');
    }

    public function test_resources_deny_model_mutation_and_detail_abilities_and_expose_only_named_operational_actions(): void
    {
        $paid = F::paid($this->gateway); $this->actingAs(LicenseFixtures::admin());
        foreach ([TestContractIssuanceResource::class, TestPaymentExceptionResource::class] as $resource) {
            $this->assertTrue($resource::can('viewAny'));
            foreach (['create', 'update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate', 'reorder', 'view'] as $ability) {
                $this->assertFalse($resource::can($ability, $paid['grant']), $ability);
            }
        }
        foreach (['/admin/test-payment-exceptions', '/admin/test-contract-issuance'] as $path) {
            $this->get($path.'/create')->assertNotFound(); $this->get($path.'/1/edit')->assertNotFound();
        }
        $component = Livewire::test(ListTestContractIssuance::class);
        $this->assertSame([], $component->instance()->getTable()->getFlatActions());
        $this->assertSame([], $component->instance()->getTable()->getFlatBulkActions());
        $exceptions = Livewire::test(ListTestPaymentExceptions::class);
        $this->assertSame(['inspectEvidence', 'operationHistory', 'recordDisposition', 'reconcilePayment', 'refundResolutionHistory', 'resolveFullRefund'], array_keys($exceptions->instance()->getTable()->getFlatActions()));
        $this->assertSame([], $exceptions->instance()->getTable()->getFlatBulkActions());
    }

    public function test_filters_do_not_expand_scope_and_tampered_page_sizes_remain_bounded(): void
    {
        $paid = F::paid($this->gateway); $this->actingAs(LicenseFixtures::admin());
        $component = Livewire::test(ListTestContractIssuance::class)->filterTable('issuance_state', 'missing_request')
            ->assertSee($paid['grant']->public_id)->filterTable('issuance_state', 'recorded')->assertDontSee($paid['grant']->public_id);
        foreach (['all', 1000000, 0, -1] as $pageSize) {
            $component->set('tableRecordsPerPage', $pageSize);
            $this->assertSame(25, $component->instance()->getTableRecordsPerPage());
        }
        $this->assertCount(0, ReadTestCommerceOperations::filterContracts(app(ReadTestCommerceOperations::class)->contracts(), 'untrusted')->get());
        config(['payments.stripe.account_id' => 'acct_OTHERTEST']);
        Livewire::test(ListTestContractIssuance::class)->filterTable('issuance_state', 'missing_request')->assertDontSee($paid['grant']->public_id);
    }

    public function test_both_lists_enforce_required_panel_mfa(): void
    {
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        Route::get('/admin/test-commerce-mfa', fn () => 'Synthetic setup destination')->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs(LicenseFixtures::admin());
        foreach ([TestPaymentExceptionResource::class, TestContractIssuanceResource::class] as $resource) {
            $url = $resource::getUrl();
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($url));
            $route->middleware(Dashboard::getRouteMiddleware($panel));
            $this->get($url)->assertRedirect('/admin/test-commerce-mfa');
        }
    }

    private function exception(): array
    {
        $this->gateway->onCreate = fn (array $params): array => CheckoutFixtures::session($params, 'cs_test_OPERATOREXCEPTION');
        $fixture = PaymentFixtures::started($this->gateway);
        $this->gateway->session['payment_intent'] = 'pi_OPERATOREXCEPTION';
        $this->gateway->payment['id'] = 'pi_OPERATOREXCEPTION';
        $this->travelTo($fixture['order']->attempt()->sole()->expires_at);
        $fixture = FinalizationFixtures::confirm($fixture);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($fixture['payment']->id));

        return $fixture;
    }

    private function assertState(string $state): \Illuminate\Database\Eloquent\Model
    {
        $before = F::retained(); $row = app(ReadTestCommerceOperations::class)->contracts()->sole();
        $this->assertSame($state, $row->issuance_state); $this->assertSame($before, F::retained());

        return $row;
    }
}
