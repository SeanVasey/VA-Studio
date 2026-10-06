<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\RefundResolution\ResolveRefundedTestException;
use App\Filament\Resources\TestPaymentExceptionResource;
use App\Filament\Resources\TestPaymentExceptionResource\Pages\ListTestPaymentExceptions;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Real Filament/Livewire delegation. Financial eligibility and resource effects are tested separately. */
class TestRefundResolutionResourceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        FinalizationFixtures::configure();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_page_rejects_guests_nonstaff_unverified_staff_and_withdrawn_access(): void
    {
        $this->service()->shouldNotReceive('review', 'resolve');
        $this->get('/admin/test-payment-exceptions')->assertRedirect('/admin/login');
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        foreach ([User::factory()->create(), $unverified] as $actor) {
            $this->actingAs($actor)->get('/admin/test-payment-exceptions')->assertForbidden();
        }
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $component = Livewire::test(ListTestPaymentExceptions::class)->assertSuccessful();
        $admin->forceFill(['is_admin' => false])->save();
        $this->actingAs($admin->fresh());
        $component->call('$refresh')->assertForbidden();
    }

    public function test_required_mfa_is_rechecked_on_livewire_requests(): void
    {
        $this->service()->shouldNotReceive('review', 'resolve');
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin->fresh());
        $component = Livewire::test(ListTestPaymentExceptions::class)->assertSuccessful();
        $admin->saveAppAuthenticationSecret(null);
        $this->actingAs($admin->fresh());
        $component->call('$refresh')->assertForbidden();
    }

    public function test_history_displays_only_minimized_resolution_and_existing_operation_projection(): void
    {
        $record = $this->record();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $review = $this->review();
        $review['resolution'] = ['publicId' => (string) Str::uuid(), 'releasedAt' => '2026-10-06T12:00:00Z'];
        $review['history'] = [['sequence' => 4, 'status' => 'reconciliation_observed', 'outcome' => 'confirmed', 'observedAt' => '2026-10-06T11:59:00Z',
            'raw' => 'PRIVATE_PROVIDER_RESPONSE_MARKER']];
        $review['private'] = 'PRIVATE_PROVIDER_RESPONSE_MARKER';
        $service = $this->service();
        $service->shouldReceive('review')->with($record->public_id, Mockery::on(fn ($actor) => $actor->is($admin)))->andReturn($review);
        $service->shouldNotReceive('resolve');
        Queue::fake();
        $before = DB::table('audit_events')->count();
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('refundResolutionHistory', $record)
            ->assertMountedActionModalSee($review['resolution']['publicId'])->assertMountedActionModalSee('2026-10-06T12:00:00Z')
            ->assertMountedActionModalSee('Payment confirmed')->assertMountedActionModalSee('No refund is sent')
            ->assertMountedActionModalDontSee('PRIVATE_PROVIDER_RESPONSE_MARKER')
            ->assertMountedActionModalDontSee($record->evidence_ciphertext)
            ->assertMountedActionModalDontSee(OrderFixtures::buyer()['email']);
        $this->assertSame($before, DB::table('audit_events')->count());
        Queue::assertNothingPushed();
    }

    public function test_unverified_history_never_claims_a_retained_resolution(): void
    {
        $record = $this->record();
        $this->actingAs(LicenseFixtures::admin());
        $review = $this->review();
        $review['testOnly'] = false;
        $review['resolution'] = ['publicId' => 'PRIVATE_UNVERIFIED_RESOLUTION', 'releasedAt' => '2026-10-06T12:00:00Z'];
        $review['history'] = [['sequence' => 4, 'status' => 'PRIVATE_UNSUPPORTED_STATUS', 'outcome' => 'PRIVATE_UNSUPPORTED_STATUS', 'observedAt' => null]];
        $this->service()->shouldReceive('review')->andReturn($review);
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('refundResolutionHistory', $record)
            ->assertMountedActionModalSee('No verified resource resolution is recorded in this review.')
            ->assertMountedActionModalDontSee('PRIVATE_UNVERIFIED_RESOLUTION')->assertMountedActionModalDontSee('PRIVATE_UNSUPPORTED_STATUS');
    }

    public function test_only_two_named_refund_actions_are_added_without_generic_model_writes(): void
    {
        $record = $this->record();
        $this->service()->shouldNotReceive('review', 'resolve');
        $this->actingAs(LicenseFixtures::admin());
        foreach (['create', 'update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate', 'reorder', 'view'] as $ability) {
            $this->assertFalse(TestPaymentExceptionResource::can($ability, $record), $ability);
        }
        $component = Livewire::test(ListTestPaymentExceptions::class);
        $this->assertSame(['inspectEvidence', 'operationHistory', 'recordDisposition', 'reconcilePayment', 'refundResolutionHistory', 'resolveFullRefund'],
            array_keys($component->instance()->getTable()->getFlatActions()));
        $this->assertSame([], $component->instance()->getTable()->getFlatBulkActions());
        $this->assertSame([], $component->instance()->getCachedHeaderActions());
        $this->assertNull($component->instance()->getTable()->getRecordUrl($record));
    }

    public function test_uncertain_retry_retains_exact_review_sequence_and_request_id_without_recapture(): void
    {
        $record = $this->record();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $service = $this->service();
        $service->shouldReceive('review')->once()->with($record->public_id, Mockery::on(fn ($actor) => $actor->is($admin)))->andReturn($this->review());
        $component = Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('resolveFullRefund', $record)
            ->assertMountedActionModalSee('No refund is sent')->assertMountedActionModalSee('Grants and original contracts are unchanged');
        $data = $component->get('mountedActions.0.data');
        $this->assertSame(4, $data['sequence']);
        $this->assertTrue(Str::isUuid($data['request_id']));
        $calls = 0;
        $service->shouldReceive('resolve')->twice()->with($record->public_id, Mockery::on(fn ($actor) => $actor->is($admin)), $data['request_id'], 4)
            ->andReturnUsing(function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new RuntimeException('PRIVATE_PROVIDER_FAILURE_MARKER');
                }

                return ['testOnly' => true, 'status' => 'released', 'sequence' => 6];
            });
        $component->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Refund resolution was not confirmed')
            ->assertTableActionDataSet($data)->assertDontSee('PRIVATE_PROVIDER_FAILURE_MARKER');
        $component->callMountedTableAction()->assertHasNoTableActionErrors()
            ->assertNotified(Notification::make()->title('Refunded test resources released')
                ->body('The retained resolution records the original attempt. No refund was sent. Grants and original contracts are unchanged; fulfillment remains blocked.')->success());
        $this->assertSame([], $component->get('mountedActions'));
    }

    #[DataProvider('unconfirmedOutcomes')]
    public function test_unconfirmed_results_never_claim_success_or_replace_request(string $status, bool $testOnly, string $title): void
    {
        $record = $this->record();
        $this->actingAs(LicenseFixtures::admin());
        $service = $this->service();
        $service->shouldReceive('review')->once()->andReturn($this->review());
        $component = Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('resolveFullRefund', $record);
        $data = $component->get('mountedActions.0.data');
        $service->shouldReceive('resolve')->once()->with($record->public_id, Mockery::type(User::class), $data['request_id'], 4)
            ->andReturn(['testOnly' => $testOnly, 'status' => $status, 'sequence' => 6]);
        $component->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified($title)
            ->assertTableActionDataSet($data)->assertNotNotified('Refunded test resources released')
            ->assertDontSee('PRIVATE_UNSUPPORTED_STATUS');
    }

    public static function unconfirmedOutcomes(): array
    {
        return [
            ['not_refunded', true, 'Full refund was not established'],
            ['attention', true, 'Refund evidence needs attention'],
            ['unavailable', true, 'Refund verification is unavailable'],
            ['busy', true, 'Refund check is in progress'],
            ['stale', true, 'Refund review is out of date'],
            ['PRIVATE_UNSUPPORTED_STATUS', true, 'Refund resolution was not confirmed'],
            ['released', false, 'Refund resolution was not confirmed'],
        ];
    }

    public function test_invalid_request_identity_is_rejected_before_service_submission(): void
    {
        $record = $this->record();
        $this->actingAs(LicenseFixtures::admin());
        $service = $this->service();
        $service->shouldReceive('review')->once()->andReturn($this->review());
        $service->shouldNotReceive('resolve');
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('resolveFullRefund', $record)
            ->setTableActionData(['sequence' => -1, 'request_id' => 'invalid'])
            ->callMountedTableAction()->assertHasTableActionErrors(['sequence', 'request_id']);
    }

    public function test_domain_authorization_denial_is_not_recast_as_an_uncertain_success(): void
    {
        $record = $this->record();
        $this->actingAs(LicenseFixtures::admin());
        $service = $this->service();
        $service->shouldReceive('review')->once()->andReturn($this->review());
        $service->shouldReceive('resolve')->once()->andThrow(new AuthorizationException);
        Livewire::test(ListTestPaymentExceptions::class)->mountTableAction('resolveFullRefund', $record)
            ->callMountedTableAction()->assertForbidden()->assertNotNotified('Refunded test resources released');
    }

    private function service(): MockInterface
    {
        // Untyped double permits UI/domain implementation in independent ownership lanes.
        $service = Mockery::mock();
        $this->app->instance(ResolveRefundedTestException::class, $service);

        return $service;
    }

    private function record(): OrderFinalization
    {
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = PaymentFixtures::started($gateway, true, true);
        $this->travelTo($fixture['order']->attempt()->sole()->expires_at->addSecond());
        $fixture = FinalizationFixtures::confirm($fixture);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($fixture['payment']->id));

        return OrderFinalization::where('order_id', $fixture['order']->id)->sole();
    }

    private function review(): array
    {
        return ['testOnly' => true, 'sequence' => 4, 'history' => [], 'resolution' => null];
    }
}
