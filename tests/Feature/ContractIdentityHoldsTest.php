<?php

namespace Tests\Feature;

use App\Domain\Grants\ContractIdentityHolds;
use App\Filament\Resources\ContractIdentityHoldResource;
use App\Filament\Resources\ContractIdentityHoldResource\Pages\ListContractIdentityHolds;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
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
}
