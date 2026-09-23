<?php

namespace Tests\Feature;

use App\Domain\Catalog\ExclusiveOfferScope;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\VerifyOfferFiles;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExclusiveOfferFixtures as F;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ExclusiveOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function prepare(array $f, string $reference = 'PRIVATE-TEST-LINK'): OfferRevision
    {
        return app(PrepareExclusiveOffer::class)->handle($f['offer'], $f['scope']->id, $reference, $f['actor']);
    }

    public function test_exact_exclusive_scope_license_and_files_are_frozen_atomically_without_activation(): void
    {
        $f = F::draft(); $revision = $this->prepare($f);
        $this->assertSame(2, $revision->snapshot['schema_version']);
        $this->assertSame('test_exclusive_preparation', $revision->snapshot['purpose']);
        $this->assertSame('exclusive', $revision->snapshot['commercial']['type']);
        $this->assertSame('exclusive', $revision->snapshot['license']['type']);
        $this->assertSame(4, $revision->snapshot['license']['structured_terms']['schema_version']);
        $this->assertSame($f['license']->source_hash, $revision->snapshot['license']['source_hash']);
        $this->assertSame($f['media']['master_wav']->sha256, $revision->snapshot['assets'][0]['sha256']);
        $this->assertSame($f['scope']->public_id, $revision->snapshot['inventory']['scope_public_id']);
        $this->assertSame(hash('sha256', 'PRIVATE-TEST-LINK'), $revision->snapshot['inventory']['link_reference_hash']);
        $this->assertSame(CanonicalJson::hash($revision->snapshot), $revision->snapshot_hash);
        $this->assertSame([], app(PublicationReadiness::class)->preparedExclusiveBlockers($f['offer'], $revision));
        $this->assertNotEmpty(app(PublicationReadiness::class)->revisionBlockers($f['offer'], $revision));
        $this->assertDatabaseHas('rights_scope_offers', ['offer_revision_id' => $revision->id, 'rights_scope_id' => $f['scope']->id, 'linked_by' => $f['actor']->id]);
        $this->assertDatabaseHas('offers', ['id' => $f['offer']->id, 'current_revision_id' => $revision->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_events', ['action' => 'catalog.offer.exclusive_prepared', 'subject_id' => $f['offer']->id, 'actor_id' => $f['actor']->id]);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertStringNotContainsString('PRIVATE-TEST-LINK', CanonicalJson::encode($revision->snapshot));
        $this->assertStringNotContainsString('PRIVATE-TEST-SCOPE', CanonicalJson::encode($revision->snapshot));
    }

    public function test_matching_replay_by_another_operator_reuses_evidence_without_new_audits(): void
    {
        $f = F::draft(); $revision = $this->prepare($f);
        $f['actor'] = LicenseFixtures::admin();
        $afterActor = DB::table('audit_events')->count();
        $this->assertSame($revision->id, $this->prepare($f)->id);
        $this->assertSame($afterActor, DB::table('audit_events')->count());
        $this->assertSame(1, RightsScopeOffer::count());
        $this->assertSame(2, OfferRevision::count()); // One legacy revision plus this preparation.
    }

    public function test_changed_price_or_explicit_scope_link_creates_successors_and_retains_old_evidence(): void
    {
        $f = F::draft(); $original = $this->prepare($f); $bytes = CanonicalJson::encode($original->snapshot);
        app(SaveOfferDraft::class)->handle($f['offer'], ['price_minor' => 223456], $f['actor']);
        $second = $this->prepare($f);
        $f['scope'] = app(ManageRightsScope::class)->register('different-explicit-scope', 'EXPLICIT-SECOND-ASSERTION', $f['actor']);
        $third = $this->prepare($f, 'EXPLICIT-SECOND-LINK');
        $this->assertSame([1, 2, 3], [$original->revision, $second->revision, $third->revision]);
        $this->assertSame($bytes, CanonicalJson::encode($original->refresh()->snapshot));
        $this->assertSame(223456, $second->price_minor);
        $this->assertNotSame($original->snapshot['inventory'], $third->snapshot['inventory']);
        $this->assertSame(3, RightsScopeOffer::count());
        $this->assertFalse($f['offer']->refresh()->is_active);
    }

    public function test_distinct_variants_can_prepare_the_same_scope_without_reserving_or_selling_it(): void
    {
        $a = F::draft(); $b = F::draft($a['scope']);
        $first = $this->prepare($a); $second = $this->prepare($b);
        $this->assertSame($first->snapshot['inventory']['scope_public_id'], $second->snapshot['inventory']['scope_public_id']);
        $this->assertNotSame($first->offer_id, $second->offer_id);
        $this->assertDatabaseCount('inventory_claims', 0);
    }

    public function test_legacy_catalog_quotes_and_pricing_remain_unchanged_and_preparations_are_private(): void
    {
        $f = F::draft();
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['legacy']['items']);
        $pricing = app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $oldHash = $f['legacy']['revision']->snapshot_hash;
        $revision = $this->prepare($f);
        $this->getJson('/api/catalog')->assertOk()->assertJsonCount(1, 'tracks.0.offers')
            ->assertJsonPath('tracks.0.offers.0.offerRevisionId', (string) $f['legacy']['revision']->id)
            ->assertDontSee('PRIVATE-TEST')->assertDontSee('test_exclusive_preparation');
        $this->assertSame($oldHash, $f['legacy']['revision']->refresh()->snapshot_hash);
        $this->assertSame($pricing->snapshot_hash, app(PriceQuote::class)->read($quote->public_id, InventoryFixtures::OWNER)->snapshot_hash);
        $items = [['trackId' => $f['track']->id, 'offerId' => $f['offer']->id,
            'licenseVersionId' => $f['license']->id, 'offerRevisionId' => $revision->id]];
        try { app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $items); $this->fail('Prepared exclusive was selectable.'); }
        catch (QuoteException $error) { $this->assertSame('SELECTION_CHANGED', $error->errorCode); }
        $this->expectException(ValidationException::class);
        app(PublishOffer::class)->handle($f['offer'], $f['actor']);
    }

    public function test_even_an_out_of_band_active_flag_cannot_make_a_preparation_public_or_quoteable(): void
    {
        $f = F::draft(); $revision = $this->prepare($f);
        DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => true]);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->assertNotEmpty(app(PublicationReadiness::class)->offerBlockers($f['offer']->refresh()));
        $this->expectException(QuoteException::class);
        app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), [['trackId' => $f['track']->id,
            'offerId' => $f['offer']->id, 'licenseVersionId' => $f['license']->id, 'offerRevisionId' => $revision->id]]);
    }

    public function test_preparation_never_converts_or_deactivates_an_existing_non_exclusive_revision(): void
    {
        $f = F::draft(); $legacy = $f['legacy'];
        app(SaveOfferDraft::class)->handle($legacy['offer'], ['license_version_id' => $f['license']->id], $f['actor']);
        try { app(PrepareExclusiveOffer::class)->handle($legacy['offer'], $f['scope']->id, 'TEST', $f['actor']); $this->fail('Legacy revision was converted.'); }
        catch (ValidationException) {}
        $this->assertSame($legacy['revision']->id, $legacy['offer']->refresh()->current_revision_id);
        $this->assertTrue($legacy['offer']->is_active);
        $this->assertDatabaseCount('rights_scope_offers', 0);
    }

    public function test_customer_and_production_cannot_prepare_exclusives(): void
    {
        $f = F::draft();
        try { app(PrepareExclusiveOffer::class)->handle($f['offer'], $f['scope']->id, 'TEST', User::factory()->create()); $this->fail('Unauthorized preparation.'); }
        catch (AuthorizationException) {}
        $this->app->instance('env', 'production');
        try { $this->prepare($f); $this->fail('Production preparation.'); }
        catch (QuoteException $error) { $this->assertSame('INVENTORY_UNAVAILABLE', $error->errorCode); }
        $this->assertDatabaseCount('rights_scope_offers', 0);
        $this->assertNull($f['offer']->refresh()->current_revision_id);
    }

    public function test_scope_block_invalidates_preparation_and_replay_without_rewriting_evidence(): void
    {
        $f = F::draft(); $revision = $this->prepare($f); $hash = $revision->snapshot_hash;
        app(ManageRightsScope::class)->block($f['scope']->id, true, 0, 'TEST-HOLD', $f['actor']);
        $this->assertNotEmpty(app(PublicationReadiness::class)->preparedExclusiveBlockers($f['offer'], $revision));
        try { $this->prepare($f); $this->fail('Blocked scope prepared.'); } catch (ValidationException) {}
        $this->assertSame($hash, $revision->refresh()->snapshot_hash);
        app(ManageRightsScope::class)->block($f['scope']->id, false, 1, 'TEST-UNBLOCK', $f['actor']);
        $this->assertSame($revision->id, $this->prepare($f)->id);
    }

    public static function invalidInputs(): array
    {
        return array_map(fn ($value) => [$value], ['empty_reference', 'invalid_reference', 'long_reference', 'unknown_scope',
            'non_exclusive_license', 'missing_deliverable', 'unverified_rights', 'zero_price', 'currency', 'blocked_scope']);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_evidence_never_writes_a_partial_revision_or_link(string $case): void
    {
        $f = F::draft(); $ref = 'TEST'; $scopeId = $f['scope']->id;
        match ($case) {
            'empty_reference' => $ref = '', 'invalid_reference' => $ref = 'contains private spaces',
            'long_reference' => $ref = str_repeat('x', 193), 'unknown_scope' => $scopeId = 999999,
            'non_exclusive_license' => $f['offer']->update(['license_version_id' => $f['legacy']['offer']->license_version_id]),
            'missing_deliverable' => $f['offer']->update(['deliverable_asset_ids' => []]),
            'unverified_rights' => $f['track']->rightsDeclarations()->update(['status' => 'pending']),
            'zero_price' => $f['offer']->update(['price_minor' => 0]), 'currency' => $f['offer']->update(['currency' => 'EUR']),
            'blocked_scope' => app(ManageRightsScope::class)->block($scopeId, true, 0, 'TEST', $f['actor']),
        };
        $audits = DB::table('audit_events')->count();
        try { app(PrepareExclusiveOffer::class)->handle($f['offer'], $scopeId, $ref, $f['actor']); $this->fail('Invalid preparation accepted: '.$case); }
        catch (ValidationException|ModelNotFoundException) {}
        $this->assertDatabaseCount('rights_scope_offers', 0);
        $this->assertSame(1, OfferRevision::count());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertNull($f['offer']->refresh()->current_revision_id);
    }

    public function test_cached_media_verification_does_not_hide_same_size_file_corruption(): void
    {
        $f = F::draft(); $this->assertSame([], app(PublicationReadiness::class)->exclusiveDraftBlockers($f['offer']));
        $path = Storage::disk('local')->path($f['media']['master_wav']->storage_path);
        $bytes = file_get_contents($path); $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        chmod($path, 0600); file_put_contents($path, $bytes);
        try { $this->prepare($f); $this->fail('Changed bytes were accepted.'); } catch (ValidationException) {}
        $this->assertDatabaseCount('rights_scope_offers', 0);
        $this->assertNull($f['offer']->refresh()->current_revision_id);
    }

    public function test_license_expiry_during_file_verification_cannot_create_a_preparation(): void
    {
        $this->travelTo(now()->startOfSecond()); $until = now()->addMinute()->toImmutable();
        $f = F::draft(content: ['effective_until' => $until]);
        $this->mock(VerifyOfferFiles::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($until) { $this->travelTo($until); });
        try { $this->prepare($f); $this->fail('Expired evidence accepted.'); } catch (ValidationException) {}
        $this->assertDatabaseCount('rights_scope_offers', 0);
    }

    public function test_failure_after_revision_and_link_creation_rolls_back_all_evidence(): void
    {
        $f = F::draft(); $audits = DB::table('audit_events')->count();
        try {
            DB::transaction(function () use ($f) { $this->prepare($f); throw new \RuntimeException('Synthetic enclosing failure'); });
        } catch (\RuntimeException $error) { $this->assertSame('Synthetic enclosing failure', $error->getMessage()); }
        $this->assertDatabaseCount('rights_scope_offers', 0);
        $this->assertSame(1, OfferRevision::count());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertNull($f['offer']->refresh()->current_revision_id);
    }

    public function test_matching_snapshot_without_a_link_or_with_an_altered_scope_is_rejected(): void
    {
        $f = F::draft(); $revision = $this->prepare($f);
        $clone = clone $revision; $clone->id = 999999;
        $this->assertNotEmpty(app(ExclusiveOfferScope::class)->blockers($clone));
        $snapshot = $revision->snapshot; $snapshot['inventory']['scope_id']++;
        $revision->snapshot = $snapshot;
        $this->assertNotEmpty(app(ExclusiveOfferScope::class)->blockers($revision));
    }

    public function test_existing_database_guards_protect_v2_revision_and_link_history(): void
    {
        $f = F::draft(); $revision = $this->prepare($f);
        foreach ([fn () => DB::table('offer_revisions')->where('id', $revision->id)->update(['snapshot_hash' => str_repeat('0', 64)]),
            fn () => DB::table('rights_scope_offers')->where('offer_revision_id', $revision->id)->update(['evidence_reference' => 'REWRITE']),
            fn () => DB::table('offer_revisions')->where('id', $revision->id)->delete()] as $write) {
            try { $write(); $this->fail('Immutable history was overwritten.'); } catch (QueryException) {}
        }
        $this->assertSame([], app(PublicationReadiness::class)->preparedExclusiveBlockers($f['offer'], $revision->refresh()));
    }
}
