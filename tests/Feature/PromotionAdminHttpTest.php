<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\PromotionAdministration;
use App\Filament\Resources\TestPromotionResource;
use App\Filament\Resources\TestPromotionResource\Pages\ListTestPromotions;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PromotionAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function form(array $changes = []): array
    {
        return array_replace(['key' => 'synthetic-admin-promotion', 'code' => 'SYNTHETICADMIN',
            'effective_from' => '2020-01-01T00:00:00Z', 'effective_until' => '2099-01-01T00:00:00Z',
            'eligibility_mode' => 'all_non_exclusive', 'minimum_subtotal_minor' => '1000',
            'discount_type' => 'fixed', 'amount_minor' => '250', 'max_uses' => '7'], $changes);
    }

    private function campaign(?User $actor = null, array $changes = []): PromotionCampaign
    {
        return app(PromotionAdministration::class)->create(PromotionFixtures::policy($changes), $actor ?? LicenseFixtures::admin());
    }

    public function test_real_editor_saves_exact_money_as_disabled_and_explicitly_changes_availability(): void
    {
        $actor = LicenseFixtures::admin(); $this->actingAs($actor);
        Livewire::test(ListTestPromotions::class)->callAction('createPromotion', data: $this->form(['currency' => 'EUR', 'scope' => 'live', 'schema_version' => 99]))->assertHasNoActionErrors();
        $campaign = PromotionCampaign::sole();
        $this->assertSame('USD', $campaign->snapshot['currency']);
        $this->assertSame('test', $campaign->snapshot['scope']);
        $this->assertSame(1, $campaign->snapshot['schema_version']);
        $this->assertSame(['type' => 'fixed', 'amount_minor' => 250], $campaign->snapshot['discount']);
        $this->assertSame(1000, $campaign->snapshot['minimum_subtotal_minor']);
        $this->assertSame(7, $campaign->snapshot['max_uses']);
        $detail = app(PromotionAdministration::class)->detail($campaign->id, $actor);
        $this->assertFalse($detail['enabled']); $this->assertSame('disabled', $detail['status']);
        Livewire::test(ListTestPromotions::class)->callTableAction('enable', $campaign)->assertHasNoTableActionErrors();
        $this->assertTrue(app(PromotionAdministration::class)->detail($campaign->id, $actor)['enabled']);
        Livewire::test(ListTestPromotions::class)->callTableAction('disable', $campaign)->assertHasNoTableActionErrors();
        $this->assertFalse(app(PromotionAdministration::class)->detail($campaign->id, $actor)['enabled']);
        $this->assertDatabaseCount('promotion_uses', 0);
    }

    public function test_copy_requires_new_identity_and_preserves_original_policy_and_budget(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->campaign($actor);
        $snapshot = $campaign->snapshot; $this->actingAs($actor);
        Livewire::test(ListTestPromotions::class)->mountTableAction('copyPromotion', $campaign)
            ->assertTableActionDataSet(['key' => '', 'code' => '', 'amount_minor' => '500', 'max_uses' => '3']);
        Livewire::test(ListTestPromotions::class)->callTableAction('copyPromotion', $campaign, data: $this->form([
            'key' => $campaign->policy_key, 'code' => $campaign->code,
        ]))->assertHasTableActionErrors();
        $this->assertDatabaseCount('promotion_campaigns', 1);
        Livewire::test(ListTestPromotions::class)->callTableAction('copyPromotion', $campaign, data: $this->form([
            'key' => 'synthetic-percentage-copy', 'code' => 'SYNTHETICCOPY', 'discount_type' => 'percentage',
            'rate_bps' => '1250', 'max_discount_minor' => '300',
        ]))->assertHasNoTableActionErrors();
        $copy = PromotionCampaign::whereKeyNot($campaign->id)->sole();
        $this->assertSame(['type' => 'percentage', 'rate_bps' => 1250, 'max_discount_minor' => 300], $copy->snapshot['discount']);
        $this->assertSame($snapshot, $campaign->fresh()->snapshot);
        $this->assertFalse(app(PromotionAdministration::class)->detail($copy->id, $actor)['enabled']);
    }

    public static function invalidMoney(): array
    {
        return ['decimal' => ['12.50'], 'exponent' => ['1e3'], 'floating point' => [250.0],
            'boolean' => [true], 'negative' => ['-1'], 'whitespace' => [' 250'], 'overflow' => ['9007199254740992']];
    }

    #[DataProvider('invalidMoney')]
    public function test_money_adapter_rejects_ambiguous_or_inexact_values(mixed $value): void
    {
        $this->expectException(ValidationException::class);
        TestPromotionResource::policyFromForm($this->form(['amount_minor' => $value]));
    }

    public function test_livewire_numeric_validation_keeps_failed_input_and_creates_no_records(): void
    {
        $this->actingAs(LicenseFixtures::admin());
        foreach (['12.50', '1e3', '9007199254740992'] as $invalid) {
            Livewire::test(ListTestPromotions::class)->callAction('createPromotion', data: $this->form(['amount_minor' => $invalid]))
                ->assertHasActionErrors(['amount_minor'])->assertActionDataSet(['amount_minor' => $invalid]);
        }
        $this->assertDatabaseCount('promotion_campaigns', 0);
        $exact = TestPromotionResource::policyFromForm($this->form(['amount_minor' => (string) MinorUnits::MAX, 'minimum_subtotal_minor' => '0001000']));
        $this->assertSame(MinorUnits::MAX, $exact['discount']['amount_minor']);
        $this->assertSame(1000, $exact['minimum_subtotal_minor']);
    }

    public function test_selected_revision_form_preserves_exact_ids_and_domain_rejects_invalid_schedule(): void
    {
        $a = QuoteFixtures::selection(); $b = QuoteFixtures::selection();
        $this->actingAs($a['actor']);
        Livewire::test(ListTestPromotions::class)->callAction('createPromotion', data: $this->form([
            'eligibility_mode' => 'offer_revisions', 'offer_revision_ids' => [(string) $b['revision']->id, (string) $a['revision']->id],
        ]))->assertHasNoActionErrors();
        $this->assertSame(['mode' => 'offer_revisions', 'offer_revision_ids' => [$a['revision']->id, $b['revision']->id]], PromotionCampaign::sole()->snapshot['eligibility']);
        Livewire::test(ListTestPromotions::class)->callAction('createPromotion', data: $this->form([
            'key' => 'invalid-interval', 'code' => 'INVALIDINTERVAL', 'effective_until' => '2019-01-01T00:00:00Z',
        ]))->assertHasActionErrors();
        $this->assertDatabaseCount('promotion_campaigns', 1);
    }

    public function test_schedule_states_and_legacy_configuration_status_are_shown_without_mutation_actions(): void
    {
        $actor = LicenseFixtures::admin(); $service = app(PromotionAdministration::class);
        $future = $this->campaign($actor, ['key' => 'future', 'code' => 'FUTURE', 'effective_from' => '2098-01-01T00:00:00Z']);
        $expired = $this->campaign($actor, ['key' => 'expired', 'code' => 'EXPIRED', 'effective_until' => now()->addHour()->format('Y-m-d\TH:i:s\Z')]);
        foreach ([$future, $expired] as $campaign) { $service->setAvailability($campaign->id, 1, true, $actor); }
        $this->travel(2)->hours();
        $policy = PromotionFixtures::policy(['key' => 'legacy', 'code' => 'LEGACY']);
        $legacy = PromotionCampaign::create(['policy_key' => $policy['key'], 'code' => $policy['code'], 'snapshot' => $policy,
            'snapshot_hash' => CanonicalJson::hash($policy), 'created_at' => now()]);
        $this->actingAs($actor);
        Livewire::test(ListTestPromotions::class)->assertSee('Scheduled')->assertSee('Expired')->assertSee('Configuration managed')
            ->assertTableActionHidden('enable', $legacy)->assertTableActionHidden('disable', $legacy)
            ->mountTableAction('review', $legacy)->assertMountedActionModalSee('read only here');
        foreach (['create', 'update', 'delete', 'deleteAny', 'view'] as $ability) {
            $this->assertFalse(TestPromotionResource::getAuthorizationResponse($ability, $legacy)->allowed());
        }
    }

    public function test_review_shows_curated_usage_and_terms_without_private_evidence_or_effects(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->campaign($actor); $this->actingAs($actor);
        $auditCount = AuditEvent::count();
        $component = Livewire::test(ListTestPromotions::class)->mountTableAction('review', $campaign)
            ->assertMountedActionModalSee('Current unstarted holds: 0')->assertMountedActionModalSee('Pending attempts: 0')
            ->assertMountedActionModalSee('Consumed uses: 0')->assertMountedActionModalSee('Remaining capacity: 3');
        foreach ([$campaign->snapshot_hash, 'snapshot_hash', 'quote_pricing_id', 'attempt_id', 'owner_key', 'buyer_email'] as $private) {
            $component->assertDontSee($private, false);
        }
        $this->assertSame($auditCount, AuditEvent::count());
        $this->assertDatabaseCount('promotion_uses', 0);
    }

    public function test_stale_confirmation_cannot_overwrite_availability_changed_by_another_editor(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->campaign($actor); $this->actingAs($actor);
        $component = Livewire::test(ListTestPromotions::class)->mountTableAction('enable', $campaign)->assertSet('expectedAvailabilityRevision', 1);
        $service = app(PromotionAdministration::class);
        $service->setAvailability($campaign->id, 1, true, $actor);
        $service->setAvailability($campaign->id, 2, false, $actor);
        $audits = AuditEvent::count();
        $component->callMountedAction()->assertNotified('Availability change blocked');
        $this->assertFalse($service->detail($campaign->id, $actor)['enabled']);
        $this->assertSame(3, $service->detail($campaign->id, $actor)['revision']);
        $this->assertSame($audits, AuditEvent::count());
    }

    public function test_list_rejects_guests_customers_unverified_staff_and_production(): void
    {
        $url = TestPromotionResource::getUrl();
        $this->get($url)->assertRedirect('/admin/login');
        $unverified = LicenseFixtures::admin(); $unverified->forceFill(['email_verified_at' => null])->save();
        foreach ([User::factory()->create(), $unverified] as $denied) { $this->actingAs($denied)->get($url)->assertForbidden(); }
        $this->actingAs(LicenseFixtures::admin());
        $this->app->instance('env', 'production');
        $this->get($url)->assertForbidden();
        $this->assertFalse(TestPromotionResource::canAccess());
    }

    public static function revokedPrivileges(): array { return [['is_admin', false], ['email_verified_at', null]]; }

    #[DataProvider('revokedPrivileges')]
    public function test_open_action_and_list_recheck_fresh_staff_authority(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->campaign($actor); $this->actingAs($actor);
        $component = Livewire::test(ListTestPromotions::class)->mountTableAction('enable', $campaign);
        DB::table('users')->where('id', $actor->id)->update([$field => $value]);
        $this->get(TestPromotionResource::getUrl())->assertForbidden();
        $component->callMountedAction()->assertForbidden();
        $this->assertSame(1, DB::table('promotion_availabilities')->where('promotion_campaign_id', $campaign->id)->value('revision'));
    }

    public function test_required_mfa_applies_to_direct_route_and_newly_required_reactive_action(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->campaign($actor); $this->actingAs($actor);
        $component = Livewire::test(ListTestPromotions::class)->mountTableAction('enable', $campaign);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $component->callMountedAction()->assertForbidden();
        Route::get('/admin/synthetic-promotion-mfa', fn () => 'Synthetic setup destination')->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        $url = TestPromotionResource::getUrl();
        Route::getRoutes()->match(\Illuminate\Http\Request::create($url))->middleware(Dashboard::getRouteMiddleware($panel));
        $this->get($url)->assertRedirect('/admin/synthetic-promotion-mfa');
        $this->assertDatabaseCount('promotion_uses', 0);
    }

    public function test_reactive_reads_recheck_required_mfa_after_enrollment_is_withdrawn(): void
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $this->actingAs($actor->fresh());
        $component = Livewire::test(ListTestPromotions::class)->assertSuccessful();
        $actor->saveAppAuthenticationSecret(null);
        $component->call('$refresh')->assertForbidden();
    }
}
