<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class OfferRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    /** Creates actual processed synthetic assets and independently reviewed nonbinding terms. */
    private function draft(): array
    {
        $actor = $this->admin();
        $track = Track::create(['title' => 'Synthetic recording', 'slug' => 'synthetic-'.uniqid(), 'artist' => 'Test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY-RIGHTS', 'sample_disclosure' => 'Synthetic source', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $license = LicenseFixtures::published($actor);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id, 'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$media['master_wav']->id]], $actor);

        return [$actor, $track, $offer, $media];
    }

    public function test_publish_freezes_exact_commercial_legal_rights_and_asset_evidence(): void
    {
        [$actor, $track, $offer, $media] = $this->draft();
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        $this->assertSame(1, $revision->revision);
        $this->assertSame(4999, $revision->price_minor);
        $this->assertSame($track->id, $revision->snapshot['product']['id']);
        $this->assertSame($media['master_wav']->sha256, $revision->snapshot['assets'][0]['sha256']);
        $this->assertSame($media['master_wav']->id, $revision->snapshot['assets'][0]['id']);
        $this->assertSame($media['preview_tagged']->id, $revision->snapshot['preview']['id']);
        $this->assertSame(CanonicalJson::hash($revision->snapshot), $revision->snapshot_hash);
        $this->assertNotEmpty($revision->snapshot['license']['review_evidence_hash']);
        $this->assertNotEmpty($revision->snapshot['rights']['identity_hash']);
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'current_revision_id' => $revision->id, 'is_active' => true]);
        $this->assertDatabaseHas('audit_events', ['action' => 'catalog.offer.revision_published', 'actor_id' => $actor->id, 'subject_id' => $offer->id]);
    }

    public function test_editing_a_draft_cannot_reprice_or_change_assets_of_the_public_offer(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $original = app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track, $actor);
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 12999, 'deliverable_asset_ids' => []], $actor);
        $this->getJson('/api/catalog')->assertJsonPath('tracks.0.offers.0.priceMinor', 4999)->assertJsonPath('tracks.0.offers.0.offerRevisionId', $original->id);
        $this->assertSame([], app(PublicationReadiness::class)->offerBlockers($offer->refresh()));
        $this->assertNotEmpty(app(PublicationReadiness::class)->draftBlockers($offer));
    }

    public function test_partial_draft_edit_preserves_changes_committed_after_its_initial_read(): void
    {
        [$actor, , $offer] = $this->draft();
        $interleaved = false;
        Offer::retrieved(function (Offer $retrieved) use ($offer, &$interleaved) {
            if (! $interleaved && $retrieved->id === $offer->id) {
                $interleaved = true;
                // Model a second editor committing after the first read and before the lock.
                DB::table('offers')->where('id', $offer->id)->update(['price_minor' => 6999]);
            }
        });
        $updated = app(SaveOfferDraft::class)->handle($offer, ['deliverable_asset_ids' => []], $actor);
        $this->assertTrue($interleaved);
        $this->assertSame(6999, $updated->price_minor);
        $this->assertSame([], $updated->deliverable_asset_ids);
    }

    public function test_successor_revision_preserves_original_and_unchanged_publish_is_idempotent(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $original = app(PublishOffer::class)->handle($offer, $actor);
        $oldSnapshot = $original->snapshot;
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 7999], $actor);
        $successor = app(PublishOffer::class)->handle($offer, $actor);
        $replayed = app(PublishOffer::class)->handle($offer, $actor);
        $this->assertSame(2, $successor->revision);
        $this->assertSame($successor->id, $replayed->id);
        $this->assertSame(CanonicalJson::encode($oldSnapshot), CanonicalJson::encode($original->refresh()->snapshot));
        $this->assertSame(4999, $original->price_minor);
        $this->assertDatabaseCount('offer_revisions', 2);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'catalog.offer.revision_published')->count());
        app(PublishTrack::class)->handle($track, $actor);
        $this->getJson('/api/catalog')->assertJsonPath('tracks.0.offers.0.priceMinor', 7999)->assertJsonPath('tracks.0.offers.0.offerRevisionId', $successor->id);
    }

    public function test_deactivation_retains_history_and_reactivation_reuses_identical_snapshot(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track, $actor);
        app(DeactivateOffer::class)->handle($offer, $actor);
        app(DeactivateOffer::class)->handle($offer, $actor);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->assertSame($revision->id, $offer->refresh()->current_revision_id);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.offer.deactivated')->count());
        $this->assertSame($revision->id, app(PublishOffer::class)->handle($offer, $actor)->id);
        $this->getJson('/api/catalog')->assertJsonCount(1, 'tracks');
    }

    public function test_sql_and_orm_cannot_update_or_delete_a_published_revision(): void
    {
        [$actor, , $offer] = $this->draft();
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        foreach ([fn () => $revision->update(['price_minor' => 1]), fn () => $revision->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Mutable commercial history was accepted.');
            } catch (ValidationException) {
            }
        }
        foreach ([fn () => DB::table('offer_revisions')->where('id', $revision->id)->update(['price_minor' => 1]), fn () => DB::table('offer_revisions')->where('id', $revision->id)->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('SQL mutation of commercial history was accepted.');
            } catch (QueryException) {
            }
        }
        $this->assertSame(4999, $revision->refresh()->price_minor);
    }

    public function test_revision_pointer_cannot_reference_a_different_offer_even_on_the_same_track(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        $other = app(SaveOfferDraft::class)->handle(null, $offer->only(['track_id', 'license_version_id', 'price_minor', 'currency', 'deliverable_asset_ids']), $actor);
        try {
            DB::table('offers')->where('id', $other->id)->update(['current_revision_id' => $revision->id, 'is_active' => true]);
            $this->fail('An unrelated offer revision pointer was accepted.');
        } catch (QueryException) {
        }
        $this->assertNull($other->refresh()->current_revision_id);
    }

    public function test_legacy_active_flag_does_not_create_a_published_offer(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $offer->update(['is_active' => true]);
        $this->assertNotEmpty(app(PublicationReadiness::class)->blockers($track));
        $this->assertDatabaseCount('offer_revisions', 0);
        $this->expectException(ValidationException::class);
        app(PublishTrack::class)->handle($track, $actor);
    }

    public function test_missing_or_cross_track_assets_cannot_create_a_revision(): void
    {
        [$actor, $track, $offer] = $this->draft();
        [, , , $otherMedia] = $this->draft();
        foreach ([[999999], [$otherMedia['master_wav']->id], []] as $ids) {
            $offer->update(['deliverable_asset_ids' => $ids]);
            try {
                app(PublishOffer::class)->handle($offer, $actor);
                $this->fail('Invalid deliverables created a revision.');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('offer_revisions', 0);
        $this->assertNull($offer->refresh()->current_revision_id);
    }

    public function test_assets_must_match_exact_licensed_roles_without_duplicates(): void
    {
        [$actor, , $offer, $media] = $this->draft();
        foreach ([[$media['master_wav']->id, $media['master_wav']->id], [$media['master_wav']->id, $media['preview_tagged']->id], [$media['download_mp3']->id]] as $ids) {
            $offer->update(['deliverable_asset_ids' => $ids]);
            try {
                app(PublishOffer::class)->handle($offer, $actor);
                $this->fail('Contradictory deliverable manifest was accepted.');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('offer_revisions', 0);
    }

    public function test_new_recording_requires_a_new_matching_revision_and_old_evidence_survives(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $original = app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track, $actor);
        app(PublishTrack::class)->unpublish($track, $actor);
        $source = MediaFixtures::source($track, 'master_wav', MediaFixtures::wav(1.2, 880));
        $run = app(QueueMediaProcessing::class)->handle($source, $actor);
        app(MediaProcessor::class)->handle($run->id);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $master = $run->outputs()->where('role', 'master_wav')->firstOrFail();
        app(SaveOfferDraft::class)->handle($offer, ['deliverable_asset_ids' => [$master->id]], $actor);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $successor = app(PublishOffer::class)->handle($offer, $actor);
        $this->assertNotSame($original->snapshot['preview']['id'], $successor->snapshot['preview']['id']);
        $this->assertNotSame($original->snapshot['assets'][0]['id'], $successor->snapshot['assets'][0]['id']);
        app(PublishTrack::class)->handle($track, $actor);
        $this->getJson('/api/catalog')->assertJsonPath('tracks.0.offers.0.offerRevisionId', $successor->id);
    }

    public function test_new_rights_evidence_requires_explicit_republication(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $original = app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track, $actor);
        $newRights = RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-NEW-RIGHTS', 'sample_disclosure' => 'New evidence', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $successor = app(PublishOffer::class)->handle($offer, $actor);
        $this->assertSame($newRights->id, $successor->rights_declaration_id);
        $this->assertNotSame($original->rights_declaration_id, $successor->rights_declaration_id);
        $this->getJson('/api/catalog')->assertJsonCount(1, 'tracks');
    }

    public function test_missing_frozen_file_hides_offer_but_preserves_history(): void
    {
        [$actor, $track, $offer, $media] = $this->draft();
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track, $actor);
        Storage::disk('local')->delete($media['master_wav']->storage_path);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->assertSame($media['master_wav']->sha256, $revision->refresh()->snapshot['assets'][0]['sha256']);
    }

    public function test_publication_freshly_hashes_files_even_with_a_recent_cached_verification(): void
    {
        [$actor, , $offer, $media] = $this->draft();
        $this->assertSame([], app(PublicationReadiness::class)->draftBlockers($offer));
        $asset = $media['master_wav'];
        $path = Storage::disk('local')->path($asset->storage_path);
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        chmod($path, 0600);
        file_put_contents($path, $bytes);
        $this->expectException(ValidationException::class);
        app(PublishOffer::class)->handle($offer, $actor);
    }

    public function test_price_and_pointer_inputs_are_validated_without_truncation_or_mass_assignment(): void
    {
        [$actor, , $offer] = $this->draft();
        foreach ([['price_minor' => 49.0], ['price_minor' => 49.99], ['price_minor' => -1], ['price_minor' => 2147483648], ['price_minor' => '1e3'], ['currency' => 'usd'], ['is_active' => true], ['current_revision_id' => 1], ['snapshot' => []]] as $data) {
            try {
                app(SaveOfferDraft::class)->handle($offer, $data, $actor);
                $this->fail('Invalid commercial draft input accepted: '.json_encode($data));
            } catch (ValidationException) {
            }
        }
        $this->assertSame(4999, $offer->refresh()->price_minor);
        $this->assertFalse($offer->is_active);
    }

    public function test_zero_price_and_non_usd_drafts_cannot_be_published(): void
    {
        [$actor, , $offer] = $this->draft();
        foreach ([['price_minor' => 0, 'currency' => 'USD'], ['price_minor' => 4999, 'currency' => 'EUR']] as $data) {
            app(SaveOfferDraft::class)->handle($offer, $data, $actor);
            try {
                app(PublishOffer::class)->handle($offer, $actor);
                $this->fail('Unsupported commercial terms were published.');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('offer_revisions', 0);
    }

    public function test_expired_license_hides_current_offer_without_rewriting_snapshot(): void
    {
        [$actor, $track, $offer] = $this->draft();
        $expiring = LicenseFixtures::published($actor, content: ['effective_until' => now()->addHour()]);
        app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $expiring->id], $actor);
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track, $actor);
        $this->getJson('/api/catalog')->assertJsonCount(1, 'tracks');
        $hash = $revision->snapshot_hash;
        $this->travel(2)->hours();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->assertSame($hash, $revision->refresh()->snapshot_hash);
        $this->expectException(ValidationException::class);
        app(PublishOffer::class)->handle($offer, $actor);
    }

    public function test_future_effective_license_cannot_back_an_active_offer_yet(): void
    {
        [$actor, , $offer] = $this->draft();
        $future = LicenseFixtures::published($actor, content: ['effective_from' => now()->addDay()]);
        app(SaveOfferDraft::class)->handle($offer, ['license_version_id' => $future->id], $actor);
        $this->expectException(ValidationException::class);
        app(PublishOffer::class)->handle($offer, $actor);
    }

    public function test_draft_edits_cannot_move_an_offer_to_another_track(): void
    {
        [$actor, , $offer] = $this->draft();
        $otherTrack = Track::create(['title' => 'Other product', 'slug' => 'other-product']);
        $this->expectException(ValidationException::class);
        app(SaveOfferDraft::class)->handle($offer, ['track_id' => $otherTrack->id], $actor);
    }

    public function test_sql_cannot_move_an_offer_to_a_different_product(): void
    {
        [, , $offer] = $this->draft();
        $otherTrack = Track::create(['title' => 'Other product', 'slug' => 'other-product']);
        $this->expectException(QueryException::class);
        DB::table('offers')->where('id', $offer->id)->update(['track_id' => $otherTrack->id]);
    }

    public function test_non_operators_cannot_save_publish_or_deactivate_offers(): void
    {
        [, , $offer] = $this->draft();
        $customer = User::factory()->create();
        foreach ([fn () => app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 1], $customer), fn () => app(PublishOffer::class)->handle($offer, $customer), fn () => app(DeactivateOffer::class)->handle($offer, $customer)] as $command) {
            try {
                $command();
                $this->fail('Customer invoked an operator offer command.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertDatabaseCount('offer_revisions', 0);
    }

    public function test_catalog_never_serializes_private_legal_rights_or_asset_evidence(): void
    {
        [$actor, $track, $offer] = $this->draft();
        app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track, $actor);
        $response = $this->getJson('/api/catalog')->assertJsonCount(1, 'tracks');
        foreach (['snapshot', 'authored_source', 'storage_path', 'TEST-ONLY-RIGHTS', 'TEST-ONLY-REVIEW', 'sha256', 'media/revisions/', 'review_evidence_hash'] as $private) {
            $response->assertDontSee($private);
        }
    }
}
