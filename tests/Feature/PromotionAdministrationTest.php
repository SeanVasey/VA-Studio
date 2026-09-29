<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\PromotionAvailability;
use App\Domain\Commerce\Models\PromotionAvailabilityRevision;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PricingSnapshot;
use App\Domain\Commerce\PromotionAdministration;
use App\Domain\Commerce\PromotionPolicy;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PromotionAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond());
        config(['commerce.test_promotions' => null, 'commerce.test_pricing_policy' => null]);
    }

    private function create(array $changes = [], ?User $actor = null): PromotionCampaign
    {
        return app(PromotionAdministration::class)->create(PromotionFixtures::policy($changes), $actor ?? LicenseFixtures::admin());
    }

    private function rejected(callable $action): void
    {
        try { $action(); $this->fail('Invalid promotion administration was accepted.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('promotion', $exception->errors()); }
    }

    private function unavailable(callable $action, int $status = 409): void
    {
        try { $action(); $this->fail('Unavailable promotion was accepted.'); }
        catch (QuoteException $exception) {
            $this->assertSame('PROMOTION_UNAVAILABLE', $exception->errorCode); $this->assertSame($status, $exception->status);
        }
    }

    public function test_creation_is_disabled_immutable_audited_and_installs_no_use_or_other_commercial_effect(): void
    {
        $this->assertDatabaseCount('promotion_campaigns', 0); $this->assertDatabaseCount('promotion_availabilities', 0);
        $actor = LicenseFixtures::admin(); $service = app(PromotionAdministration::class); $campaign = $this->create([], $actor);
        $this->assertSame(PromotionFixtures::policy(), $campaign->snapshot);
        $this->assertSame(CanonicalJson::hash($campaign->snapshot), $campaign->snapshot_hash);
        $detail = $service->detail($campaign->id, $actor);
        $this->assertSame('disabled', $detail['status']); $this->assertTrue($detail['managed']);
        $this->assertFalse($detail['enabled']); $this->assertSame(1, $detail['revision']);
        $this->assertSame(['held' => 0, 'pending' => 0, 'consumed' => 0, 'expired' => 0, 'remaining' => 3], $detail['usage']);
        $history = PromotionAvailabilityRevision::sole();
        $this->assertSame('create', $history->operation); $this->assertSame($actor->id, $history->actor_id);
        $this->assertNull($history->previous_enabled);
        $audit = AuditEvent::where('action', 'commerce.promotion.created')->sole();
        $this->assertSame($actor->id, $audit->actor_id); $this->assertSame($campaign->snapshot_hash, $audit->context['policy_hash']);
        $this->assertArrayNotHasKey('snapshot', $audit->context);
        $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'));
        foreach (['promotion_uses', 'quote_pricings', 'orders', 'license_grants', 'pending_entitlements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_enable_disable_reenable_require_fresh_revision_and_cannot_reset_terms_or_capacity(): void
    {
        $actor = LicenseFixtures::admin(); $service = app(PromotionAdministration::class); $campaign = $this->create([], $actor);
        $before = $campaign->refresh()->getAttributes();
        $this->assertSame(2, $service->setAvailability($campaign->id, 1, true, $actor)->revision);
        $this->assertEquals($campaign->snapshot, app(PromotionPolicy::class)->current('SYNTHETIC'));
        $this->assertSame('active', $service->detail($campaign->id, $actor)['status']);
        $service->setAvailability($campaign->id, 2, false, $actor);
        $service->setAvailability($campaign->id, 3, true, $actor);
        foreach ([fn () => $service->setAvailability($campaign->id, 2, false, $actor),
            fn () => $service->setAvailability($campaign->id, 4, true, $actor),
            fn () => $service->setAvailability($campaign->id, -1, false, $actor)] as $invalid) { $this->rejected($invalid); }
        $this->assertSame(4, PromotionAvailability::sole()->revision);
        $this->assertSame($before, $campaign->refresh()->getAttributes());
        $this->assertDatabaseCount('promotion_availability_revisions', 4);
        $this->assertSame(2, AuditEvent::where('action', 'commerce.promotion.enabled')->count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.promotion.disabled')->count());
    }

    public function test_lifetime_identity_and_configured_collisions_fail_closed_including_version_changes(): void
    {
        $actor = LicenseFixtures::admin(); $this->create([], $actor);
        foreach ([[], ['version' => 2], ['code' => 'NEWCODE'], ['key' => 'new-key']] as $changes) {
            $this->rejected(fn () => $this->create($changes, $actor));
        }
        PromotionFixtures::configure([PromotionFixtures::policy(['key' => 'configured', 'code' => 'LEGACY'])]);
        foreach ([['key' => 'configured', 'code' => 'UNIQUE'], ['key' => 'unique', 'code' => 'LEGACY']] as $changes) {
            $this->rejected(fn () => $this->create($changes, $actor));
        }
        foreach (['{', '{}', 'null', str_repeat('x', 65537), json_encode([PromotionFixtures::policy(), PromotionFixtures::policy()])] as $bad) {
            config(['commerce.test_promotions' => $bad]);
            $this->rejected(fn () => $this->create(['key' => 'new', 'code' => 'NEWCODE'], $actor));
        }
        foreach ([null, '', '  ', '[]'] as $index => $empty) {
            config(['commerce.test_promotions' => $empty]);
            $this->create(['key' => 'new-'.$index, 'code' => 'NEW'.$index], $actor);
        }
        $this->assertDatabaseCount('promotion_campaigns', 5);
    }

    public function test_server_validation_rejects_currency_money_limits_and_unsupported_terms(): void
    {
        $actor = LicenseFixtures::admin();
        foreach ([['currency' => 'EUR'], ['scope' => 'live'], ['version' => '1'], ['max_uses' => 10001], ['max_uses' => 0],
            ['discount' => ['type' => 'fixed', 'amount_minor' => '500']], ['discount' => ['type' => 'fixed', 'amount_minor' => 0]],
            ['discount' => ['type' => 'percentage', 'rate_bps' => 10001, 'max_discount_minor' => 100]],
            ['minimum_subtotal_minor' => -1], ['stacking' => 'all'], ['release' => 'always'],
            ['effective_until' => '2020-01-01T00:00:00Z'], ['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [2, 1]]],
            ['unexpected' => true]] as $changes) { $this->rejected(fn () => $this->create($changes, $actor)); }
        $this->assertDatabaseCount('promotion_campaigns', 0); $this->assertDatabaseCount('promotion_availabilities', 0);
        $this->assertDatabaseCount('promotion_availability_revisions', 0); $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_dates_are_half_open_and_expired_campaigns_display_expired_and_cannot_be_reenabled(): void
    {
        $actor = LicenseFixtures::admin(); $service = app(PromotionAdministration::class);
        $from = now()->addMinute(); $until = now()->addMinutes(2);
        $campaign = $this->create(['effective_from' => $from->toIso8601ZuluString(), 'effective_until' => $until->toIso8601ZuluString()], $actor);
        $service->setAvailability($campaign->id, 1, true, $actor);
        $this->assertSame('scheduled', $service->detail($campaign->id, $actor)['status']);
        $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'));
        $this->travelTo($from); $this->assertSame('SYNTHETIC', app(PromotionPolicy::class)->current('SYNTHETIC')['code']);
        $this->travelTo($until); $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'));
        $this->assertSame('expired', $service->detail($campaign->id, $actor)['status']);
        $service->setAvailability($campaign->id, 2, false, $actor);
        $this->rejected(fn () => $service->setAvailability($campaign->id, 3, true, $actor));
        $this->assertSame('expired', $service->detail($campaign->id, $actor)['status']);
    }

    public function test_each_command_rechecks_stored_authority_and_all_administration_rejects_production(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->create([], $actor); $service = app(PromotionAdministration::class);
        $unverified = LicenseFixtures::admin(); DB::table('users')->where('id', $unverified->id)->update(['email_verified_at' => null]);
        DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        foreach ([$actor, $unverified, User::factory()->create(), new User] as $denied) {
            foreach ([fn () => $service->create(PromotionFixtures::policy(), $denied), fn () => $service->campaigns($denied),
                fn () => $service->detail($campaign->id, $denied), fn () => $service->setAvailability($campaign->id, 1, true, $denied)] as $call) {
                try { $call(); $this->fail('Withdrawn authorization was accepted.'); } catch (AuthorizationException) { $this->assertTrue(true); }
            }
        }
        $this->app->instance('env', 'production'); $admin = LicenseFixtures::admin();
        try { $service->setAvailability($campaign->id, 1, true, $admin); $this->fail('Production promotion enabled.'); }
        catch (AuthorizationException) { $this->assertTrue(true); }
        $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'), 503);
        $this->assertSame(1, PromotionAvailability::sole()->revision);
    }

    public function test_managed_policy_wins_configuration_collision_and_disabled_code_never_falls_back(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->create([], $actor); $service = app(PromotionAdministration::class);
        PromotionFixtures::configure([PromotionFixtures::policy(['max_uses' => 999])]);
        $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'));
        $service->setAvailability($campaign->id, 1, true, $actor);
        $this->assertSame(3, app(PromotionPolicy::class)->current('SYNTHETIC')['max_uses']);
        config(['commerce.test_promotions' => '{']);
        $this->assertSame(3, app(PromotionPolicy::class)->current('SYNTHETIC')['max_uses']);
        $service->setAvailability($campaign->id, 2, false, $actor);
        $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'));
    }

    public function test_mutation_rechecks_authority_after_waiting_for_the_campaign_lock(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->create([], $actor); $waited = false;
        DB::listen(function ($query) use ($actor, &$waited): void {
            if (! $waited && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'promotion_campaigns')) {
                $waited = true;
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            }
        });
        try { app(PromotionAdministration::class)->setAvailability($campaign->id, 1, true, $actor); $this->fail('Pre-lock authority was reused.'); }
        catch (AuthorizationException) { $this->assertTrue($waited); }
        $this->assertSame(1, PromotionAvailability::sole()->revision);
        $this->assertDatabaseCount('promotion_availability_revisions', 1);
    }

    public function test_unmanaged_existing_campaigns_keep_legacy_configuration_and_are_read_only(): void
    {
        PromotionFixtures::configure([PromotionFixtures::policy()]);
        $quote = app(CreateQuote::class)->handle(self::OWNER, (string) Str::uuid(), QuoteFixtures::selection()['items']);
        app(PriceQuote::class)->createWithPromotion($quote->public_id, self::OWNER, 'SYNTHETIC');
        $campaign = PromotionCampaign::sole(); $actor = LicenseFixtures::admin(); $service = app(PromotionAdministration::class);
        $detail = $service->detail($campaign->id, $actor);
        $this->assertFalse($detail['managed']); $this->assertSame('legacy', $detail['status']);
        $this->assertNull($detail['revision']); $this->assertSame(1, $detail['usage']['held']);
        $this->assertSame(PromotionFixtures::policy(), app(PromotionPolicy::class)->current('SYNTHETIC'));
        $this->rejected(fn () => $service->setAvailability($campaign->id, 1, false, $actor));
        $this->assertDatabaseCount('promotion_availabilities', 0);
    }

    public function test_disabled_campaign_retains_quote_hold_and_snapshot_and_reenable_cannot_reset_capacity(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->create(['max_uses' => 1], $actor); $service = app(PromotionAdministration::class);
        $service->setAvailability($campaign->id, 1, true, $actor);
        $items = QuoteFixtures::selection()['items'];
        $quote = app(CreateQuote::class)->handle(self::OWNER, (string) Str::uuid(), $items);
        $pricing = app(PriceQuote::class)->createWithPromotion($quote->public_id, self::OWNER, 'SYNTHETIC');
        $hash = $pricing->snapshot_hash; $service->setAvailability($campaign->id, 2, false, $actor);
        $this->unavailable(fn () => app(PriceQuote::class)->read($quote->public_id, self::OWNER));
        $this->unavailable(fn () => app(PromotionUsage::class)->beginAttempt($quote->public_id, self::OWNER, (string) Str::uuid()));
        $this->assertSame($hash, CanonicalJson::hash(app(PricingSnapshot::class)->verify($pricing, $quote)));
        $this->assertSame('held', PromotionUse::sole()->state);
        $service->setAvailability($campaign->id, 3, true, $actor);
        $next = app(CreateQuote::class)->handle(self::OWNER, (string) Str::uuid(), $items);
        try { app(PriceQuote::class)->createWithPromotion($next->public_id, self::OWNER, 'SYNTHETIC'); $this->fail('Capacity was reset.'); }
        catch (QuoteException $exception) { $this->assertSame('PROMOTION_LIMIT_REACHED', $exception->errorCode); }
        $this->assertSame(0, $service->detail($campaign->id, $actor)['usage']['remaining']);
        $this->travelTo($pricing->expires_at);
        $this->assertSame(['held' => 0, 'pending' => 0, 'consumed' => 0, 'expired' => 1, 'remaining' => 1], $service->detail($campaign->id, $actor)['usage']);
        $this->assertDatabaseCount('quote_pricings', 1); $this->assertDatabaseCount('promotion_uses', 1);
    }

    public function test_availability_guards_and_populated_rollback_preserve_history_and_control(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->create([], $actor);
        app(PromotionAdministration::class)->setAvailability($campaign->id, 1, true, $actor);
        $control = PromotionAvailability::sole(); $history = PromotionAvailabilityRevision::where('revision', 2)->sole();
        foreach ([$control, $history] as $model) {
            foreach ([fn () => $model->delete(), fn () => $model->update(['enabled' => false])] as $op) {
                try { $op(); $this->fail('Model bypassed promotion evidence retention.'); } catch (LogicException) { $this->assertTrue(true); }
            }
        }
        foreach ([fn () => DB::table('promotion_availabilities')->delete(),
            fn () => DB::table('promotion_availabilities')->update(['enabled' => false]),
            fn () => DB::table('promotion_availabilities')->update(['revision' => 3, 'enabled' => false]),
            fn () => DB::table('promotion_availability_revisions')->delete(),
            fn () => DB::table('promotion_availability_revisions')->update(['enabled' => false])] as $op) {
            try { DB::transaction($op); $this->fail('SQL bypassed promotion evidence retention.'); } catch (QueryException) { $this->assertTrue(true); }
        }
        $migration = require database_path('migrations/2026_09_29_000025_promotion_administration.php');
        try { $migration->down(); $this->fail('Populated administration was dropped.'); } catch (LogicException) { $this->assertTrue(true); }
        try { DB::transaction(fn () => DB::table('promotion_availabilities')->delete()); $this->fail('Refused rollback removed guards.'); }
        catch (QueryException) { $this->assertTrue(true); }
        $this->assertSame(2, $control->refresh()->revision); $this->assertTrue($control->enabled);
        $row = $history->getAttributes(); unset($row['id']);
        foreach ([['revision' => 4, 'enabled' => false, 'previous_enabled' => true, 'operation' => 'disable'],
            ['revision' => 3, 'enabled' => true, 'previous_enabled' => true, 'operation' => 'enable'],
            ['revision' => 3, 'enabled' => false, 'previous_enabled' => true, 'operation' => 'disable', 'policy_hash' => str_repeat('0', 64)]] as $change) {
            try { DB::transaction(fn () => DB::table('promotion_availability_revisions')->insert(array_replace($row, $change))); $this->fail('Invalid history transition inserted.'); }
            catch (QueryException) { $this->assertTrue(true); }
        }
    }

    public function test_incomplete_restored_availability_evidence_fails_closed_and_cannot_use_legacy_fallback(): void
    {
        $actor = LicenseFixtures::admin(); $policy = PromotionFixtures::policy();
        $campaign = PromotionCampaign::create(['policy_key' => $policy['key'], 'code' => $policy['code'],
            'snapshot' => $policy, 'snapshot_hash' => CanonicalJson::hash($policy), 'created_at' => now()]);
        PromotionAvailabilityRevision::create(['promotion_campaign_id' => $campaign->id, 'revision' => 1,
            'enabled' => false, 'previous_enabled' => null, 'operation' => 'create', 'policy_hash' => $campaign->snapshot_hash,
            'actor_id' => $actor->id, 'created_at' => now()]);
        PromotionFixtures::configure([$policy]);
        $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'), 503);
        $this->rejected(fn () => app(PromotionAdministration::class)->detail($campaign->id, $actor));
        $this->assertDatabaseCount('promotion_availabilities', 0);
    }

    public function test_corrupt_restored_policy_is_rejected_even_with_consistent_disabled_control_and_configuration(): void
    {
        $actor = LicenseFixtures::admin(); $policy = PromotionFixtures::policy();
        $campaign = PromotionCampaign::create(['policy_key' => $policy['key'], 'code' => $policy['code'],
            'snapshot' => $policy, 'snapshot_hash' => str_repeat('f', 64), 'created_at' => now()]);
        PromotionAvailabilityRevision::create(['promotion_campaign_id' => $campaign->id, 'revision' => 1,
            'enabled' => false, 'previous_enabled' => null, 'operation' => 'create', 'policy_hash' => $campaign->snapshot_hash,
            'actor_id' => $actor->id, 'created_at' => now()]);
        PromotionAvailability::create(['promotion_campaign_id' => $campaign->id, 'revision' => 1, 'enabled' => false, 'updated_at' => now()]);
        PromotionFixtures::configure([$policy]);
        $this->unavailable(fn () => app(PromotionPolicy::class)->current('SYNTHETIC'), 503);
        $this->rejected(fn () => app(PromotionAdministration::class)->detail($campaign->id, $actor));
        $this->rejected(fn () => app(PromotionAdministration::class)->setAvailability($campaign->id, 1, true, $actor));
        $this->assertSame(1, PromotionAvailability::sole()->revision);
    }

    public function test_audit_failure_rolls_back_creation_and_availability_together(): void
    {
        $actor = LicenseFixtures::admin(); $campaign = $this->create([], $actor); $service = app(PromotionAdministration::class);
        AuditEvent::creating(function (AuditEvent $event): void {
            if (str_starts_with($event->action, 'commerce.promotion.')) { throw new RuntimeException('Synthetic promotion audit failure'); }
        });
        try {
            foreach ([fn () => $this->create(['key' => 'new', 'code' => 'NEWCODE'], $actor),
                fn () => $service->setAvailability($campaign->id, 1, true, $actor)] as $action) {
                try { $action(); $this->fail('Audit failure was ignored.'); }
                catch (RuntimeException $exception) { $this->assertSame('Synthetic promotion audit failure', $exception->getMessage()); }
            }
        } finally { AuditEvent::flushEventListeners(); }
        $this->assertDatabaseCount('promotion_campaigns', 1); $this->assertDatabaseCount('promotion_availability_revisions', 1);
        $this->assertFalse(PromotionAvailability::sole()->enabled); $this->assertSame(1, PromotionAvailability::sole()->revision);
    }
}
