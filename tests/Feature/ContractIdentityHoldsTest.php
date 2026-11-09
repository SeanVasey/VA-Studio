<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\ContractIdentityHolds;
use App\Domain\Grants\ContractRenderHolds;
use App\Filament\Resources\ContractIdentityHoldResource;
use App\Filament\Resources\ContractIdentityHoldResource\Pages\ListContractIdentityHolds;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * D-30: the staff surface for paid orders held at finalization. The records themselves are written by the paid-grant journeys
 * (PaidGrantDocumentJourneyTest, PaidTaxGrantJourneyTest); this pins who may read them and that the list is read-only.
 */
class ContractIdentityHoldsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A hold record as ContractIdentityHolds::record() writes it, plus an unrelated audit row that must not be listed. */
    private static function held(): array
    {
        $order = (string) Str::uuid();
        $other = (string) Str::uuid();
        AuditEvent::create(['actor_id' => null, 'action' => ContractIdentityHolds::ACTION, 'subject_type' => 'production_tax_checkout_order', 'subject_id' => 7,
            'context' => ['chain' => 'production_tax_checkout_v2', 'reason' => ContractIdentityHolds::REASON, 'order_public_id' => $order]]);
        AuditEvent::create(['actor_id' => null, 'action' => 'paid_tax_origin.retained', 'subject_type' => 'production_tax_checkout_order', 'subject_id' => 8,
            'context' => ['chain' => 'production_tax_checkout_v2', 'reason' => ContractIdentityHolds::REASON, 'order_public_id' => $other]]);

        return [$order, $other];
    }

    public function test_the_hold_list_requires_verified_staff_on_the_server(): void
    {
        [$order] = self::held();
        $this->get('/admin/contract-identity-holds')->assertRedirect('/admin/login');
        $unverified = LicenseFixtures::admin();
        $unverified->email_verified_at = null;
        $unverified->save();
        foreach ([User::factory()->create(), $unverified] as $denied) {
            $this->actingAs($denied);
            $this->get('/admin/contract-identity-holds')->assertForbidden();
            try {
                ContractIdentityHolds::staffQuery()->get();
                $this->fail('An unauthorized hold read succeeded.');
            } catch (AuthorizationException) {
            }
        }
        $this->actingAs(LicenseFixtures::admin());
        $this->assertSame([$order], ContractIdentityHolds::staffQuery()->get()->pluck('context.order_public_id')->all());
    }

    public function test_staff_see_held_orders_only_and_the_list_is_read_only(): void
    {
        [$order, $other] = self::held();
        $this->actingAs(LicenseFixtures::admin());
        $this->get('/admin/contract-identity-holds')->assertOk()->assertSee($order)->assertSee('Tax checkout (V2)')
            ->assertSee(ContractIdentityHolds::REASON)->assertDontSee($other);
        $hold = AuditEvent::query()->where('action', ContractIdentityHolds::ACTION)->sole();
        $this->assertTrue(ContractIdentityHoldResource::can('viewAny'));
        foreach (['create', 'update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate', 'reorder', 'view'] as $ability) {
            $this->assertFalse(ContractIdentityHoldResource::can($ability, $hold), $ability);
        }
        $this->get('/admin/contract-identity-holds/create')->assertNotFound();
        $this->get('/admin/contract-identity-holds/'.$hold->id.'/edit')->assertNotFound();
        $component = Livewire::test(ListContractIdentityHolds::class)->assertSuccessful();
        $this->assertSame([], $component->instance()->getTable()->getFlatActions());
        $this->assertSame([], $component->instance()->getTable()->getFlatBulkActions());
    }

    public function test_livewire_refresh_reauthorizes_after_staff_access_is_removed(): void
    {
        self::held();
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $component = Livewire::test(ListContractIdentityHolds::class)->assertSuccessful();
        $actor->forceFill(['is_admin' => false])->save();
        $this->actingAs($actor->fresh());
        $component->call('$refresh')->assertForbidden();
    }

    public function test_recording_needs_a_known_chain_and_an_existing_order(): void
    {
        ContractIdentityHolds::record('production_checkout_v1', (string) Str::uuid());
        ContractIdentityHolds::record('production_tax_checkout_v2', (string) Str::uuid());
        $this->assertSame(0, AuditEvent::query()->count());
        $this->expectException(InvalidArgumentException::class);
        ContractIdentityHolds::record('unknown_chain', (string) Str::uuid());
    }

    /** A render hold as ContractRenderHolds::record() writes it, beside an identity hold: staff see both, labelled. */
    public function test_staff_see_contract_render_holds_beside_identity_holds_with_the_same_server_checks(): void
    {
        [$order] = self::held();
        $rendered = (string) Str::uuid();
        AuditEvent::create(['actor_id' => null, 'action' => ContractRenderHolds::ACTION, 'subject_type' => 'production_checkout_order', 'subject_id' => 9,
            'context' => ['chain' => 'production_checkout_v1', 'reason' => 'signature_changed', 'order_public_id' => $rendered]]);
        // The same action under another subject type is not a paid-order hold.
        AuditEvent::create(['actor_id' => null, 'action' => ContractRenderHolds::ACTION, 'subject_type' => 'license_grant', 'subject_id' => 10,
            'context' => ['chain' => 'production_checkout_v1', 'reason' => 'render_failed', 'order_public_id' => 'not-a-paid-order']]);
        $this->actingAs(User::factory()->create());
        $this->get('/admin/contract-identity-holds')->assertForbidden();
        try {
            ContractIdentityHolds::staffQuery()->get();
            $this->fail('An unauthorized hold read succeeded.');
        } catch (AuthorizationException) {
        }
        $this->actingAs(LicenseFixtures::admin());
        $this->assertEqualsCanonicalizing([$order, $rendered], ContractIdentityHolds::staffQuery()->get()->pluck('context.order_public_id')->all());
        $this->get('/admin/contract-identity-holds')->assertOk()->assertSee($rendered)->assertSee('Checkout (V1)')->assertSee('Contract render')
            ->assertSee('signature_changed')->assertSee('Contract identity')->assertDontSee('not-a-paid-order');
    }

    public function test_render_hold_reasons_records_and_their_transaction_boundary(): void
    {
        $permanent = new ContractIssuanceException('signature_changed');
        $this->assertSame('signature_changed', ContractRenderHolds::reason($permanent, 1, 5));
        foreach (ContractRenderHolds::PERMANENT as $reason) {
            $this->assertSame($reason, ContractRenderHolds::reason(new ContractIssuanceException($reason), 1, 5));
        }
        // Retryable failures are held only on the last attempt.
        foreach ([new ContractIssuanceException('storage_failed'), new ContractIssuanceException('unavailable'), new \RuntimeException('synthetic')] as $error) {
            $this->assertNull(ContractRenderHolds::reason($error, 4, 5));
            $this->assertSame(ContractRenderHolds::ATTEMPTS_EXHAUSTED, ContractRenderHolds::reason($error, 5, 5));
        }

        // Unknown chains and reasons are refused; a missing order writes nothing.
        ContractRenderHolds::record('production_checkout_v1', (string) Str::uuid(), 'render_failed');
        $this->assertSame(0, AuditEvent::query()->count());
        foreach ([['unknown_chain', 'render_failed'], ['production_checkout_v1', 'buyer name']] as [$chain, $reason]) {
            try {
                ContractRenderHolds::record($chain, (string) Str::uuid(), $reason);
                $this->fail('An unknown render hold was recorded.');
            } catch (InvalidArgumentException) {
            }
        }
        // recordAfterFailure never throws; with a null reason or inside a transaction it writes nothing.
        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) extends Handler
        {
            public function __construct(private array &$reported)
            {
                parent::__construct(app());
            }

            public function report(\Throwable $e): void
            {
                $this->reported[] = $e;
            }
        });
        ContractRenderHolds::recordAfterFailure('unknown_chain', (string) Str::uuid(), null);
        DB::transaction(fn () => ContractRenderHolds::recordAfterFailure('production_checkout_v1', (string) Str::uuid(), 'render_failed'));
        ContractRenderHolds::recordAfterFailure('unknown_chain', (string) Str::uuid(), 'render_failed');
        $this->assertSame([\LogicException::class, InvalidArgumentException::class], array_map(get_class(...), $reported));
        $this->assertSame(0, AuditEvent::query()->count());
    }
}
