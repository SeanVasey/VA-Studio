<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Filament\Resources\TestUnpaidOrderResource;
use App\Filament\Resources\TestUnpaidOrderResource\Pages\ListTestUnpaidOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/** Real Filament/Livewire boundary with a service double; domain release acceptance is separate. */
class TestUnpaidOrderResourceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        OrderFixtures::configure();
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_page_rejects_guests_nonstaff_unverified_staff_and_withdrawn_access(): void
    {
        $service = $this->service();
        $service->shouldNotReceive('review', 'release');
        $this->get('/admin/test-unpaid-orders')->assertRedirect('/admin/login');
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        foreach ([User::factory()->create(), $unverified] as $actor) {
            $this->actingAs($actor)->get('/admin/test-unpaid-orders')->assertForbidden();
        }
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $component = Livewire::test(ListTestUnpaidOrders::class)->assertSuccessful();
        $admin->forceFill(['is_admin' => false])->save();
        $this->actingAs($admin->fresh());
        $component->call('$refresh')->assertForbidden();
    }

    public function test_required_mfa_is_rechecked_on_livewire_requests(): void
    {
        $service = $this->service();
        $service->shouldNotReceive('review', 'release');
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin->fresh());
        $component = Livewire::test(ListTestUnpaidOrders::class)->assertSuccessful();
        $admin->saveAppAuthenticationSecret(null);
        $this->actingAs($admin->fresh());
        $component->call('$refresh')->assertForbidden();
    }

    public function test_list_uses_service_scope_and_history_displays_only_minimized_projection(): void
    {
        $order = $this->order();
        $other = $this->order();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $review = $this->review($order);
        $review['status'] = 'released';
        $review['release'] = ['publicId' => (string) Str::uuid(), 'releasedAt' => '2026-10-06T12:00:00Z'];
        $review['history'] = [['sequence' => 4, 'kind' => 'observed', 'outcome' => 'released', 'createdAt' => '2026-10-06T12:00:00Z',
            'raw' => 'PRIVATE_PROVIDER_RESPONSE_MARKER']];
        $review['private'] = 'PRIVATE_PROVIDER_RESPONSE_MARKER';
        $service = $this->service($order);
        $service->shouldReceive('review')->with($order->public_id, Mockery::on(fn ($actor) => $actor->is($admin)))->andReturn($review);
        $service->shouldNotReceive('release');
        // Fixture preparation schedules media work; measure only the operator read boundary.
        Queue::fake();
        $before = DB::table('audit_events')->count();
        $this->get('/admin/test-unpaid-orders')->assertOk()->assertSee($order->public_id)->assertDontSee($other->public_id)
            ->assertDontSee($order->payload_ciphertext)->assertDontSee(OrderFixtures::buyer()['legalName'])
            ->assertDontSee(OrderFixtures::buyer()['email']);
        Livewire::test(ListTestUnpaidOrders::class)->mountTableAction('releaseHistory', $order)
            ->assertMountedActionModalSee($order->public_id)->assertMountedActionModalSee($review['release']['publicId'])
            ->assertMountedActionModalSee('2026-10-06T12:00:00Z')
            ->assertMountedActionModalDontSee('PRIVATE_PROVIDER_RESPONSE_MARKER')
            ->assertMountedActionModalDontSee($order->payload_ciphertext);
        $this->assertSame($before, DB::table('audit_events')->count());
        Queue::assertNothingPushed();
    }

    public function test_only_named_actions_are_exposed_without_generic_model_writes(): void
    {
        $order = $this->order();
        $this->service($order)->shouldNotReceive('review', 'release');
        $this->actingAs(LicenseFixtures::admin());
        $this->assertTrue(TestUnpaidOrderResource::can('viewAny'));
        foreach (['create', 'update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate', 'reorder', 'view'] as $ability) {
            $this->assertFalse(TestUnpaidOrderResource::can($ability, $order), $ability);
        }
        $this->get('/admin/test-unpaid-orders/create')->assertNotFound();
        $this->get('/admin/test-unpaid-orders/'.$order->id.'/edit')->assertNotFound();
        $component = Livewire::test(ListTestUnpaidOrders::class)
            ->assertTableActionExists('releaseHistory')->assertTableActionExists('releaseUnpaid');
        $this->assertSame(['releaseHistory', 'releaseUnpaid'], array_keys($component->instance()->getTable()->getFlatActions()));
        $this->assertSame([], $component->instance()->getTable()->getFlatBulkActions());
        $this->assertSame([], $component->instance()->getCachedHeaderActions());
        $this->assertNull($component->instance()->getTable()->getRecordUrl($order));
    }

    public function test_uncertain_retry_retains_exact_review_sequence_and_request_id_without_recapture(): void
    {
        $order = $this->order();
        $admin = LicenseFixtures::admin();
        $this->actingAs($admin);
        $service = $this->service($order);
        $service->shouldReceive('review')->once()->with($order->public_id, Mockery::on(fn ($actor) => $actor->is($admin)))
            ->andReturn($this->review($order));
        $component = Livewire::test(ListTestUnpaidOrders::class)->mountTableAction('releaseUnpaid', $order);
        $data = $component->get('mountedActions.0.data');
        $this->assertSame(4, $data['sequence']);
        $this->assertTrue(Str::isUuid($data['request_id']));
        $calls = 0;
        $service->shouldReceive('release')->twice()->with($order->public_id, Mockery::on(fn ($actor) => $actor->is($admin)), $data['request_id'], 4)
            ->andReturnUsing(function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new RuntimeException('PRIVATE_PROVIDER_FAILURE_MARKER');
                }

                return ['testOnly' => true, 'status' => 'released', 'sequence' => 6];
            });
        $component->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Release was not confirmed')
            ->assertTableActionDataSet($data)->assertDontSee('PRIVATE_PROVIDER_FAILURE_MARKER');
        $component->callMountedTableAction()->assertHasNoTableActionErrors()
            ->assertNotified(Notification::make()->title('Test resources released')
                ->body('The retained release records the original attempt. This does not issue rights or refund a payment.')->success());
        $this->assertSame([], $component->get('mountedActions'));
    }

    #[DataProvider('unconfirmedOutcomes')]
    public function test_unconfirmed_results_never_claim_success_or_replace_request(string $status, bool $testOnly, string $title): void
    {
        $order = $this->order();
        $this->actingAs(LicenseFixtures::admin());
        $service = $this->service($order);
        $service->shouldReceive('review')->once()->andReturn($this->review($order));
        $component = Livewire::test(ListTestUnpaidOrders::class)->mountTableAction('releaseUnpaid', $order);
        $data = $component->get('mountedActions.0.data');
        $service->shouldReceive('release')->once()->with($order->public_id, Mockery::type(User::class), $data['request_id'], 4)
            ->andReturn(['testOnly' => $testOnly, 'status' => $status, 'sequence' => 6]);
        $component->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified($title)
            ->assertTableActionDataSet($data)->assertNotNotified('Test resources released')
            ->assertDontSee('PRIVATE_UNSUPPORTED_STATUS');
    }

    public static function unconfirmedOutcomes(): array
    {
        return [
            ['not_unpaid', true, 'Unpaid status was not established'],
            ['attention', true, 'Release evidence needs attention'],
            ['unavailable', true, 'Unpaid verification is unavailable'],
            ['payment_recorded', true, 'Payment is already recorded'],
            ['busy', true, 'Release check is in progress'],
            ['stale', true, 'Release review is out of date'],
            ['PRIVATE_UNSUPPORTED_STATUS', true, 'Release was not confirmed'],
            ['released', false, 'Release was not confirmed'],
        ];
    }

    public function test_invalid_request_identity_is_rejected_before_service_submission(): void
    {
        $order = $this->order();
        $this->actingAs(LicenseFixtures::admin());
        $service = $this->service($order);
        $service->shouldReceive('review')->once()->andReturn($this->review($order));
        $service->shouldNotReceive('release');
        Livewire::test(ListTestUnpaidOrders::class)->mountTableAction('releaseUnpaid', $order)
            ->setTableActionData(['sequence' => -1, 'request_id' => 'invalid'])
            ->callMountedTableAction()->assertHasTableActionErrors(['sequence', 'request_id']);
    }

    private function service(?Order $order = null): MockInterface
    {
        // Untyped double keeps this UI contract runnable before the separately owned domain lane is composed.
        $service = Mockery::mock();
        $service->shouldReceive('query')->andReturnUsing(fn () => Order::query()
            ->select(['orders.id', 'orders.public_id', 'orders.created_at'])->whereKey($order?->id ?? -1));
        $this->app->instance(ReleaseTestOrderResources::class, $service);

        return $service;
    }

    private function order(): Order
    {
        $fixture = OrderFixtures::priced();

        return app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($fixture['quote']));
    }

    private function review(Order $order): array
    {
        return ['orderId' => $order->public_id, 'sequence' => 4, 'status' => 'unverified', 'history' => [], 'release' => null];
    }
}
