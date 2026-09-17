<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\QuoteException;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InventoryFixtures as F;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SharedInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
    }

    private function rejected(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected '.$code); }
        catch (QuoteException $error) { $this->assertSame($code, $error->errorCode); }
    }

    public function test_scope_and_exact_offer_link_are_audited_idempotent_and_conflict_safe(): void
    {
        $f = F::selection(); $manage = app(ManageRightsScope::class);
        $this->assertSame($f['scope']->id, $manage->register($f['scope']->scope_key, 'SYNTHETIC-SCOPE', $f['actor'])->id);
        $this->assertSame($f['link']->id, $manage->link($f['scope']->id, $f['revision']->id, 'SYNTHETIC-LINK', $f['actor'])->id);
        $other = $manage->register('different-scope', 'SYNTHETIC-SCOPE', $f['actor']);
        $this->rejected('INVENTORY_SCOPE_CONFLICT', fn () => $manage->link($other->id, $f['revision']->id, 'SYNTHETIC-LINK', $f['actor']));
        $this->rejected('INVENTORY_SCOPE_CONFLICT', fn () => $manage->register($f['scope']->scope_key, 'OTHER-REFERENCE', $f['actor']));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.offer_linked')->count());
        $this->assertArrayNotHasKey('evidence_reference', $f['scope']->toArray());
    }

    public function test_staff_authorization_is_required_on_registration_link_and_controls(): void
    {
        $f = F::selection(); $actor = LicenseFixtures::admin(); $actor->is_admin = false; $actor->save();
        $manage = app(ManageRightsScope::class);
        foreach ([fn () => $manage->register('forbidden', 'REF', $actor),
            fn () => $manage->link($f['scope']->id, $f['revision']->id, 'REF', $actor),
            fn () => $manage->block($f['scope']->id, true, 0, 'REF', $actor)] as $operation) {
            try { $operation(); $this->fail('Nonstaff operation succeeded.'); }
            catch (AuthorizationException) { $this->assertTrue(true); }
        }
    }

    public function test_verified_quote_hold_is_idempotent_and_preserves_historical_quote_and_claims(): void
    {
        $f = F::selection(); $service = app(ReserveQuoteInventory::class); $quoteHash = $f['quote']->snapshot_hash;
        $first = $service->hold($f['quote']->public_id, F::OWNER);
        $second = $service->hold($f['quote']->public_id, F::OWNER);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->snapshot_hash, CanonicalJson::hash($first->snapshot));
        $this->assertSame('test_inventory', $first->snapshot['purpose']);
        $this->assertSame($quoteHash, $f['quote']->refresh()->snapshot_hash);
        $this->assertSame(60, (int) $first->created_at->diffInSeconds($first->expires_at));
        $this->assertDatabaseCount('inventory_reservations', 1); $this->assertDatabaseCount('inventory_claims', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.held')->count());
    }

    public function test_distinct_offer_variants_share_capacity_and_expired_hold_cannot_revive(): void
    {
        $first = F::selection(); $second = F::selection($first['scope']); $service = app(ReserveQuoteInventory::class);
        $old = $service->hold($first['quote']->public_id, F::OWNER);
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => $service->hold($second['quote']->public_id, F::OWNER));
        $this->travelTo($old->expires_at);
        $new = $service->hold($second['quote']->public_id, F::OWNER);
        $this->assertSame('expired', $old->refresh()->state);
        $this->travelTo($old->expires_at->subSecond());
        $this->rejected('INVENTORY_EXPIRED', fn () => $service->beginAttempt($first['quote']->public_id, F::OWNER, (string) Str::uuid()));
        $this->assertSame('held', $new->refresh()->state);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.expired')->count());
    }

    public function test_pending_attempt_is_idempotent_never_expires_and_never_releases_capacity(): void
    {
        $first = F::selection(); $second = F::selection($first['scope']); $service = app(ReserveQuoteInventory::class);
        $held = $service->hold($first['quote']->public_id, F::OWNER); $attempt = (string) Str::uuid();
        $pending = $service->beginAttempt($first['quote']->public_id, F::OWNER, $attempt);
        $this->assertSame($pending->id, $service->beginAttempt($first['quote']->public_id, F::OWNER, $attempt)->id);
        $this->rejected('INVENTORY_ATTEMPT_CONFLICT', fn () => $service->beginAttempt($first['quote']->public_id, F::OWNER, (string) Str::uuid()));
        $this->travelTo($held->expires_at->addSecond());
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => $service->hold($second['quote']->public_id, F::OWNER));
        $this->assertSame('pending', $held->refresh()->state); $this->assertNull($held->expired_at);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.pending')->count());
    }

    public function test_attempt_identity_cannot_be_reused_across_reservations(): void
    {
        $a = F::selection(); $b = F::selection(); $service = app(ReserveQuoteInventory::class); $attempt = (string) Str::uuid();
        foreach ([$a, $b] as $f) { $service->hold($f['quote']->public_id, F::OWNER); }
        $service->beginAttempt($a['quote']->public_id, F::OWNER, $attempt);
        $this->rejected('INVENTORY_ATTEMPT_CONFLICT', fn () => $service->beginAttempt($b['quote']->public_id, F::OWNER, $attempt));
        $this->assertSame(1, InventoryReservation::where('state', 'pending')->count());
    }

    public function test_owner_current_catalog_and_complete_linkage_are_required_before_reservation(): void
    {
        $f = F::selection(); $service = app(ReserveQuoteInventory::class);
        $this->rejected('QUOTE_NOT_FOUND', fn () => $service->hold($f['quote']->public_id, str_repeat('b', 64)));
        $unlinked = QuoteFixtures::selection();
        $quote = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), $unlinked['items']);
        $this->rejected('INVENTORY_SCOPE_UNAVAILABLE', fn () => $service->hold($quote->public_id, F::OWNER));
        $f['track']->update(['status' => 'draft']);
        $this->rejected('SELECTION_CHANGED', fn () => $service->hold($f['quote']->public_id, F::OWNER));
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_multi_line_acquisition_is_atomic_and_rejects_duplicate_underlying_scope(): void
    {
        $a = F::selection(); $b = F::selection(); $c = F::selection($a['scope']); $service = app(ReserveQuoteInventory::class);
        $duplicate = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), [...$a['items'], ...$c['items']]);
        $this->rejected('INVENTORY_SCOPE_UNAVAILABLE', fn () => $service->hold($duplicate->public_id, F::OWNER));
        $service->hold($b['quote']->public_id, F::OWNER);
        $multi = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), [...$a['items'], ...$b['items']]);
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => $service->hold($multi->public_id, F::OWNER));
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('inventory_claims', 1);
        $service->hold($a['quote']->public_id, F::OWNER);
        $this->assertDatabaseCount('inventory_reservations', 2);
    }

    public function test_multi_line_success_has_one_reservation_and_sorted_claims(): void
    {
        $a = F::selection(); $b = F::selection();
        $quote = app(CreateQuote::class)->handle(F::OWNER, (string) Str::uuid(), [...$b['items'], ...$a['items']]);
        $reservation = app(ReserveQuoteInventory::class)->hold($quote->public_id, F::OWNER);
        $this->assertSame([$a['scope']->id, $b['scope']->id], array_column($reservation->snapshot['bindings'], 'scope_id'));
        $this->assertDatabaseCount('inventory_reservations', 1); $this->assertDatabaseCount('inventory_claims', 2);
    }

    public function test_admin_hold_blocks_new_and_pending_handoff_without_releasing_old_claims(): void
    {
        $f = F::selection(); $manage = app(ManageRightsScope::class); $service = app(ReserveQuoteInventory::class);
        $reservation = $service->hold($f['quote']->public_id, F::OWNER);
        $blocked = $manage->block($f['scope']->id, true, 0, 'TEST-HOLD', $f['actor']);
        $this->assertSame(1, $blocked->control_version);
        $this->rejected('INVENTORY_BLOCKED', fn () => $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid()));
        $this->rejected('INVENTORY_CONTROL_CONFLICT', fn () => $manage->block($f['scope']->id, false, 0, 'STALE', $f['actor']));
        $manage->block($f['scope']->id, false, 1, 'TEST-RELEASE-HOLD', $f['actor']);
        $this->assertSame($reservation->id, $service->hold($f['quote']->public_id, F::OWNER)->id);
    }

    public function test_policy_drift_missing_policy_and_production_are_rejected(): void
    {
        $f = F::selection(); $service = app(ReserveQuoteInventory::class);
        $service->hold($f['quote']->public_id, F::OWNER); F::configure(90);
        $this->rejected('INVENTORY_CHANGED', fn () => $service->hold($f['quote']->public_id, F::OWNER));
        config(['commerce.test_inventory_policy' => null]);
        $this->rejected('INVENTORY_POLICY_UNAVAILABLE', fn () => $service->hold($f['quote']->public_id, F::OWNER));
        F::configure(); $this->app->instance('env', 'production');
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => $service->hold($f['quote']->public_id, F::OWNER));
        $this->rejected('INVENTORY_UNAVAILABLE', fn () => app(ManageRightsScope::class)->register('production', 'REF', $f['actor']));
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public static function invalidPolicies(): array
    {
        return array_map(fn ($p) => [$p], [null, '', '{', '[]', '{}', json_encode(F::policy() + ['extra' => true]),
            json_encode(F::policy(0)), json_encode(F::policy(3601)), json_encode(array_replace(F::policy(), ['ttl_seconds' => '60'])),
            json_encode(array_replace(F::policy(), ['pending' => 'expire']))]);
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_policy_is_not_a_default(mixed $raw): void
    {
        config(['commerce.test_inventory_policy' => $raw]);
        $this->rejected('INVENTORY_POLICY_UNAVAILABLE', fn () => app(InventoryPolicy::class)->current());
    }

    public function test_nested_order_failure_rolls_back_reservation_attempt_and_audit(): void
    {
        $f = F::selection(); $service = app(ReserveQuoteInventory::class);
        try {
            DB::transaction(function () use ($f, $service) {
                $service->hold($f['quote']->public_id, F::OWNER);
                $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid());
                throw new \RuntimeException('Synthetic order failure');
            });
        } catch (\RuntimeException $error) { $this->assertSame('Synthetic order failure', $error->getMessage()); }
        $this->assertDatabaseCount('inventory_reservations', 0); $this->assertDatabaseCount('inventory_claims', 0);
        $this->assertSame(0, DB::table('audit_events')->whereIn('action', ['commerce.inventory.held', 'commerce.inventory.pending'])->count());
    }

    public static function forbiddenWrites(): array
    {
        return array_map(fn ($name) => [$name], ['scope_identity', 'scope_delete', 'scope_unversioned_control',
            'link_update', 'link_delete', 'reservation_snapshot', 'reservation_expiry', 'reservation_delete',
            'claim_update', 'claim_delete', 'pending_release', 'expired_revival']);
    }

    #[DataProvider('forbiddenWrites')]
    public function test_sql_guards_retain_identity_and_evidence(string $operation): void
    {
        $f = F::selection(); $service = app(ReserveQuoteInventory::class);
        $reservation = $service->hold($f['quote']->public_id, F::OWNER);
        if ($operation === 'pending_release') { $service->beginAttempt($f['quote']->public_id, F::OWNER, (string) Str::uuid()); }
        if ($operation === 'expired_revival') {
            $other = F::selection($f['scope']); $this->travelTo($reservation->expires_at);
            $service->hold($other['quote']->public_id, F::OWNER);
        }
        $this->expectException(QueryException::class);
        match ($operation) {
            'scope_identity' => DB::table('rights_scopes')->where('id', $f['scope']->id)->update(['scope_key' => 'rewritten']),
            'scope_delete' => DB::table('rights_scopes')->where('id', $f['scope']->id)->delete(),
            'scope_unversioned_control' => DB::table('rights_scopes')->where('id', $f['scope']->id)->update(['blocked' => true]),
            'link_update' => DB::table('rights_scope_offers')->where('id', $f['link']->id)->update(['evidence_reference' => 'REWRITE']),
            'link_delete' => DB::table('rights_scope_offers')->where('id', $f['link']->id)->delete(),
            'reservation_snapshot' => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['snapshot_hash' => str_repeat('0', 64)]),
            'reservation_expiry' => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['expires_at' => now()->addDay()]),
            'reservation_delete' => DB::table('inventory_reservations')->where('id', $reservation->id)->delete(),
            'claim_update' => DB::table('inventory_claims')->update(['rights_scope_id' => $f['scope']->id]),
            'claim_delete' => DB::table('inventory_claims')->delete(),
            default => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['state' => 'held', 'attempt_id' => null, 'pending_at' => null, 'expired_at' => null]),
        };
    }
}
