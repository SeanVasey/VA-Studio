<?php

namespace Tests\Feature;

use App\Domain\Catalog\ActivateExclusiveOffer;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\ExclusiveActivation;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\VerifyOfferFiles;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExclusiveOfferFixtures;
use Tests\Support\ExclusiveSelectionFixtures as F;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ExclusiveActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage(); F::configure();
    }

    private function activate(array $f): ExclusiveActivation
    {
        return app(ActivateExclusiveOffer::class)->handle($f['offer'], $f['revision']->id, $f['actor']);
    }

    public function test_exact_activation_is_immutable_idempotent_and_does_not_read_changed_drafts(): void
    {
        $f = F::prepared(); $before = $f['revision']->snapshot_hash;
        app(SaveOfferDraft::class)->handle($f['offer'], ['price_minor' => 999, 'deliverable_asset_ids' => []], $f['actor']);
        $activation = $this->activate($f); $audits = DB::table('audit_events')->count();
        $f['actor'] = LicenseFixtures::admin(); $audits = DB::table('audit_events')->count();
        $this->assertSame($activation->id, $this->activate($f)->id);
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertTrue($f['offer']->refresh()->is_active);
        $this->assertSame($before, $f['revision']->refresh()->snapshot_hash);
        $this->assertSame($before, $activation->snapshot['offer_snapshot_hash']);
        $this->assertSame(F::policy(), $activation->snapshot['policy']);
        $this->assertSame([], app(PublicationReadiness::class)->revisionBlockers($f['offer'], $f['revision']));
        $this->getJson('/api/catalog')->assertOk()->assertJsonCount(2, 'tracks.0.offers')
            ->assertDontSee('SYNTHETIC-EXCLUSIVE-LINK')->assertDontSee('scope_identity_hash');
        $quote = F::quote($f);
        $this->assertSame(123456, $quote->subtotal_minor);
        $this->assertSame($activation->snapshot_hash, $quote->snapshot['lines'][0]['exclusive_activation']['snapshot_hash']);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_activation_requires_explicit_links_for_every_active_sibling(): void
    {
        $f = ExclusiveOfferFixtures::draft();
        $f['revision'] = app(PrepareExclusiveOffer::class)->handle($f['offer'], $f['scope']->id, 'SYNTHETIC-LINK', $f['actor']);
        try { $this->activate($f); $this->fail('Unlinked non-exclusive sibling was ignored.'); }
        catch (QuoteException $error) { $this->assertSame('INVENTORY_SCOPE_UNAVAILABLE', $error->errorCode); }
        $this->assertFalse($f['offer']->refresh()->is_active); $this->assertDatabaseCount('exclusive_activations', 0);
        app(ManageRightsScope::class)->link($f['scope']->id, $f['legacy']['revision']->id, 'EXPLICIT-SIBLING', $f['actor']);
        $this->activate($f); $this->assertDatabaseCount('exclusive_activations', 1);
    }

    public static function rejected(): array
    {
        return [['actor'], ['environment'], ['missing_policy'], ['policy_extra'], ['cutoff'], ['pending_policy'], ['discounts'], ['stale_revision'], ['blocked']];
    }

    #[DataProvider('rejected')]
    public function test_controls_cannot_be_bypassed(string $reason): void
    {
        $f = F::prepared(); $policy = F::policy();
        if ($reason === 'actor') { $f['actor'] = User::factory()->create(); }
        if ($reason === 'environment') { $this->app->instance('env', 'production'); }
        if ($reason === 'missing_policy') { config(['commerce.test_exclusive_selection_policy' => null]); }
        if (in_array($reason, ['policy_extra', 'cutoff', 'pending_policy', 'discounts'], true)) {
            $policy[match ($reason) { 'cutoff' => 'non_exclusive_cutoff', 'pending_policy' => 'existing_pending', 'discounts' => 'discounts', default => 'unknown' }] = 'UNAPPROVED';
            config(['commerce.test_exclusive_selection_policy' => json_encode($policy)]);
        }
        if ($reason === 'stale_revision') { $f['revision']->id++; }
        if ($reason === 'blocked') { app(ManageRightsScope::class)->block($f['scope']->id, true, 0, 'TEST-BLOCK', $f['actor']); }
        try { $this->activate($f); $this->fail('Invalid activation accepted.'); }
        catch (QuoteException|AuthorizationException) {}
        $this->assertFalse($f['offer']->refresh()->is_active); $this->assertDatabaseCount('exclusive_activations', 0);
    }

    public function test_activation_uses_fresh_frozen_file_hashes_even_after_a_cached_readiness_check(): void
    {
        $f = F::prepared();
        $this->assertSame([], app(PublicationReadiness::class)->preparedExclusiveBlockers($f['offer'], $f['revision']));
        $path = Storage::disk('local')->path($f['media']['master_wav']->storage_path);
        $bytes = file_get_contents($path); $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        chmod($path, 0600); file_put_contents($path, $bytes);
        try { $this->activate($f); $this->fail('Changed frozen file was activated.'); }
        catch (ValidationException|QuoteException) {}
        $this->assertDatabaseCount('exclusive_activations', 0);
    }

    public function test_effective_terms_are_rechecked_after_hashing(): void
    {
        $this->travelTo(now()->startOfSecond()); $until = now()->addMinute()->toImmutable();
        $f = ExclusiveOfferFixtures::draft(content: ['effective_until' => $until]);
        app(ManageRightsScope::class)->link($f['scope']->id, $f['legacy']['revision']->id, 'SYNTHETIC-SIBLING', $f['actor']);
        $f['revision'] = app(PrepareExclusiveOffer::class)->handle($f['offer'], $f['scope']->id, 'SYNTHETIC-LINK', $f['actor']);
        $this->mock(VerifyOfferFiles::class)->shouldReceive('revision')->once()->andReturnUsing(fn () => $this->travelTo($until));
        try { $this->activate($f); $this->fail('Expired terms were activated.'); } catch (QuoteException) {}
        $this->assertDatabaseCount('exclusive_activations', 0);
    }

    public function test_pending_non_exclusive_attempt_blocks_activation_after_its_original_expiry(): void
    {
        $this->travelTo(now()->startOfSecond()); $f = F::prepared();
        $quote = F::quote(['items' => $f['legacy']['items']]);
        $inventory = app(ReserveQuoteInventory::class); $inventory->hold($quote->public_id, InventoryFixtures::OWNER);
        $pending = $inventory->beginAttempt($quote->public_id, InventoryFixtures::OWNER, (string) Str::uuid());
        $this->travelTo($quote->expires_at->addDay());
        try { $this->activate($f); $this->fail('Pending non-exclusive rights were preempted.'); }
        catch (QuoteException $error) { $this->assertSame('INVENTORY_UNAVAILABLE', $error->errorCode); }
        $this->assertSame('pending', $pending->refresh()->state);
        $this->assertDatabaseCount('exclusive_activations', 0);
    }

    public function test_activation_and_audit_roll_back_with_the_enclosing_operation(): void
    {
        $f = F::prepared(); $audits = DB::table('audit_events')->count();
        try { DB::transaction(function () use ($f) { $this->activate($f); throw new \RuntimeException('Rollback'); }); }
        catch (\RuntimeException $error) { $this->assertSame('Rollback', $error->getMessage()); }
        $this->assertDatabaseCount('exclusive_activations', 0); $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertFalse($f['offer']->refresh()->is_active);
    }

    public function test_sql_guards_and_current_evidence_checks_preserve_activation_history(): void
    {
        $f = F::active(); $activation = $f['activation']; $hash = $activation->snapshot_hash;
        foreach ([fn () => DB::table('exclusive_activations')->where('id', $activation->id)->update(['snapshot_hash' => str_repeat('0', 64)]),
            fn () => DB::table('exclusive_activations')->where('id', $activation->id)->delete()] as $write) {
            try { $write(); $this->fail('Activation history mutated.'); } catch (QueryException) {}
        }
        $this->assertSame($hash, $activation->refresh()->snapshot_hash);
        app(DeactivateOffer::class)->handle($f['offer'], $f['actor']);
        $this->assertSame($activation->id, $this->activate($f)->id);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'catalog.offer.exclusive_activated')->count());
        config(['commerce.test_exclusive_selection_policy' => null]);
        $this->assertNotEmpty(app(PublicationReadiness::class)->revisionBlockers($f['offer']->refresh(), $f['revision']));
        $this->assertSame($hash, $activation->refresh()->snapshot_hash);
    }
}
