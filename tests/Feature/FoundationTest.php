<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class FoundationTest extends TestCase
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

    private function approvedLicense(): LicenseVersion
    {
        return LicenseFixtures::approved($this->admin(), $this->admin());
    }

    private function readyTrack(): Track
    {
        $operator = $this->admin();
        $track = Track::create(['title' => 'Test fixture', 'slug' => 'test-'.uniqid(), 'artist' => 'Test', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test', 'duration_seconds' => 120, 'waveform' => [0.2, 0.5, 0.8]]);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY', 'sample_disclosure' => 'Synthetic test only', 'status' => 'verified', 'verified_by' => $operator->id, 'verified_at' => now()]);
        $media = MediaFixtures::readyTrackMedia($track, $operator);
        $master = $media['master_wav'];
        $license = app(PublishLicense::class)->handle($this->approvedLicense(), $operator);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id, 'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$master->id]], $operator);
        app(PublishOffer::class)->handle($offer, $operator);

        return $track;
    }

    public function test_storefront_and_empty_catalog_do_not_invent_products(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/api/catalog')->assertOk()->assertJsonCount(0, 'tracks')->assertJsonCount(0, 'licenseTiers')->assertJsonPath('commerceEnabled', false)->assertJsonPath('catalogPage.nextUrl', null)->assertJsonPath('catalogPage.previousUrl', null);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_guest_and_customer_cannot_access_any_admin_resource(): void
    {
        foreach (['tracks', 'offers', 'media-assets', 'rights-declarations', 'license-templates', 'license-versions'] as $resource) {
            $this->get('/admin/'.$resource)->assertRedirect('/admin/login');
        }
        $this->actingAs(User::factory()->create());
        foreach (['tracks', 'offers', 'media-assets', 'rights-declarations', 'license-templates', 'license-versions'] as $resource) {
            $this->get('/admin/'.$resource)->assertForbidden();
        }
    }

    public function test_verified_operator_can_render_and_create_catalog_metadata(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['tracks', 'offers', 'media-assets', 'rights-declarations', 'license-templates', 'license-versions'] as $resource) {
            $this->get('/admin/'.$resource)->assertOk();
        }
        Livewire::test(ManageTracks::class)->callAction('create', data: ['title' => 'Original recording', 'slug' => 'original-recording', 'artist' => 'VASEY.AUDIO', 'bpm' => 95, 'musical_key' => 'D minor', 'genre' => 'Hip-Hop', 'duration_seconds' => 150])->assertHasNoActionErrors();
        $this->assertDatabaseHas('tracks', ['slug' => 'original-recording', 'status' => 'draft']);
    }

    public function test_license_author_is_derived_from_authenticated_operator(): void
    {
        $actor = $this->admin();
        $other = $this->admin();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $template = LicenseTemplate::create(['name' => 'Draft', 'slug' => 'draft', 'type' => 'non-exclusive']);
        Livewire::test(ManageLicenseVersions::class)->callAction('create', data: ['license_template_id' => $template->id, 'version' => 1, 'author_id' => $other->id, 'authored_source' => 'Test draft only', 'structured_terms' => ['schema_version' => 1, 'features' => ['Test'], 'required_asset_roles' => ['master_wav']]])->assertHasNoActionErrors();
        $this->assertDatabaseHas('license_versions', ['license_template_id' => $template->id, 'author_id' => $actor->id, 'status' => 'draft']);
    }

    public function test_incomplete_track_cannot_be_published_even_by_operator(): void
    {
        $track = Track::create(['title' => 'Incomplete', 'slug' => 'incomplete']);
        $this->expectException(ValidationException::class);
        app(PublishTrack::class)->handle($track, $this->admin());
    }

    public function test_non_admin_cannot_invoke_publish_service(): void
    {
        $track = $this->readyTrack();
        $this->expectException(AuthorizationException::class);
        app(PublishTrack::class)->handle($track, User::factory()->create());
    }

    public function test_catalog_exposes_only_ready_published_tracks_and_safe_public_derivatives(): void
    {
        $draft = Track::create(['title' => 'Private draft', 'slug' => 'private-draft']);
        $track = $this->readyTrack();
        app(PublishTrack::class)->handle($track, $this->admin());
        $response = $this->getJson('/api/catalog')->assertOk()->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.slug', $track->slug)->assertJsonPath('tracks.0.offers.0.priceMinor', 4999);
        $response->assertDontSee('storage_path')->assertDontSee('fixtures/')->assertDontSee('private-draft');
        $this->get('/tracks/'.$track->slug)->assertOk();
        $this->get('/tracks/'.$draft->slug)->assertNotFound();
        $this->get('/media/'.$track->assets()->where('role', 'master_wav')->where('status', 'ready')->first()->id)->assertNotFound();
        $this->get('/media/'.$track->assets()->where('role', 'artwork')->where('status', 'ready')->first()->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_missing_draft_delivery_revision_blocks_offer_publication_without_changing_current_offer(): void
    {
        $track = $this->readyTrack();
        $offer = $track->offers()->first();
        $currentRevision = $offer->current_revision_id;
        $offer->update(['deliverable_asset_ids' => [99999]]);
        $this->assertNotEmpty(app(PublicationReadiness::class)->draftBlockers($offer));
        $this->assertSame([], app(PublicationReadiness::class)->offerBlockers($offer));
        $this->assertSame($currentRevision, $offer->refresh()->current_revision_id);
        $this->expectException(ValidationException::class);
        app(PublishOffer::class)->handle($offer, $this->admin());
    }

    public function test_missing_asset_after_publication_hides_catalog_and_media(): void
    {
        $track = $this->readyTrack();
        app(PublishTrack::class)->handle($track, $this->admin());
        Storage::disk('local')->delete($track->assets()->where('role', 'master_wav')->where('status', 'ready')->first()->storage_path);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->get('/tracks/'.$track->slug)->assertNotFound();
        $this->get('/media/'.$track->assets()->where('role', 'artwork')->where('status', 'ready')->first()->id)->assertNotFound();
    }

    public function test_new_unverified_rights_declaration_hides_a_previously_published_track(): void
    {
        $track = $this->readyTrack();
        app(PublishTrack::class)->handle($track, $this->admin());
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-NEW-REVIEW', 'sample_disclosure' => 'New evidence requires verification', 'status' => 'pending']);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->get('/tracks/'.$track->slug)->assertNotFound();
    }

    public function test_approved_license_content_cannot_change_without_a_new_review(): void
    {
        $license = $this->approvedLicense();
        $this->expectException(ValidationException::class);
        $license->update(['authored_source' => 'Changed after approval']);
    }

    public function test_review_service_rejects_self_approval_and_records_separate_approval(): void
    {
        $author = $this->admin();
        $reviewer = $this->admin();
        $version = LicenseFixtures::draft($author);
        $review = app(ReviewLicense::class);
        $version = $review->submit($version, $author);
        $evidence = ['approval_reference' => 'TEST-ONLY', 'review_hash' => $version->submission_hash, 'summary_consistency_confirmed' => true];
        try {
            $review->approve($version, $author, $evidence);
            $this->fail('Author self-approval was accepted.');
        } catch (ValidationException) {
        }
        $review->approve($version, $reviewer, $evidence);
        $this->assertDatabaseHas('license_versions', ['id' => $version->id, 'status' => 'approved', 'approved_by' => $reviewer->id]);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $version->id, 'action' => 'rights.license.approved', 'actor_id' => $reviewer->id]);
    }

    public function test_approval_metadata_cannot_be_replaced_with_self_approval(): void
    {
        $license = $this->approvedLicense();
        $this->expectException(ValidationException::class);
        $license->update(['approved_by' => $license->author_id]);
    }

    public function test_published_license_cannot_be_edited_or_reinterpreted_via_template(): void
    {
        $license = app(PublishLicense::class)->handle($this->approvedLicense(), $this->admin());
        try {
            $license->update(['authored_source' => 'Changed']);
            $this->fail('Published update was accepted');
        } catch (ValidationException) {
        }
        try {
            $license->delete();
            $this->fail('Published delete was accepted');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $license->template->update(['type' => 'exclusive']);
    }

    public function test_database_trigger_blocks_bulk_published_license_mutation(): void
    {
        $license = app(PublishLicense::class)->handle($this->approvedLicense(), $this->admin());
        $this->expectException(QueryException::class);
        DB::table('license_versions')->where('id', $license->id)->update(['authored_source' => 'Bypass model']);
    }

    public function test_database_trigger_blocks_bulk_published_license_deletion(): void
    {
        $license = app(PublishLicense::class)->handle($this->approvedLicense(), $this->admin());
        $this->expectException(QueryException::class);
        DB::table('license_versions')->where('id', $license->id)->delete();
    }

    public function test_domain_audit_records_the_explicit_actor_without_browser_session(): void
    {
        $operator = $this->admin();
        $license = app(PublishLicense::class)->handle($this->approvedLicense(), $operator);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $operator->id, 'action' => 'rights.license.published', 'subject_id' => $license->id]);
    }

    public function test_checkout_and_success_redirect_cannot_issue_payment_or_delivery(): void
    {
        $this->postJson('/checkout', ['offerId' => 1, 'amount' => 1, 'paid' => true])->assertStatus(503)->assertJsonPath('code', 'COMMERCE_NOT_ENABLED');
        $this->get('/checkout/success?paid=true')->assertNotFound();
    }

    public function test_verified_media_revisions_cannot_be_overwritten(): void
    {
        $track = $this->readyTrack();
        $this->expectException(ValidationException::class);
        $track->assets()->where('status', 'ready')->first()->update(['storage_path' => 'changed']);
    }

    public function test_changed_asset_bytes_hide_catalog_and_public_media(): void
    {
        $track = $this->readyTrack();
        app(PublishTrack::class)->handle($track, $this->admin());
        $artwork = $track->assets()->where('role', 'artwork')->where('status', 'ready')->first();
        chmod(Storage::disk('local')->path($artwork->storage_path), 0600);
        Storage::disk('local')->put($artwork->storage_path, 'tampered contents');
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->get('/media/'.$artwork->id)->assertNotFound();
    }

    public function test_catalog_uses_measured_preview_metadata_instead_of_editable_track_values(): void
    {
        $track = $this->readyTrack();
        $track->update(['duration_seconds' => 999, 'waveform' => [1]]);
        app(PublishTrack::class)->handle($track, $this->admin());
        $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->first();
        $this->getJson('/api/catalog')->assertOk()
            ->assertJsonPath('tracks.0.durationSeconds', $preview->technical_metadata['duration_seconds'])
            ->assertJsonPath('tracks.0.waveform', $preview->technical_metadata['waveform']);
    }

    public function test_a_new_preview_requires_offers_to_select_the_new_master_revision(): void
    {
        $track = $this->readyTrack();
        $oldPreview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->first();
        $source = MediaFixtures::source($track, 'master_wav', MediaFixtures::wav(1.2, 880));
        $run = app(QueueMediaProcessing::class)->handle($source, $this->admin());
        app(MediaProcessor::class)->handle($run->id);
        $this->assertContains('Deliverables must come from the same verified recording revision as the current preview.', app(PublicationReadiness::class)->blockers($track));
        $master = $run->outputs()->where('role', 'master_wav')->first();
        $operator = $this->admin();
        $offer = app(SaveOfferDraft::class)->handle($track->offers()->first(), ['deliverable_asset_ids' => [$master->id]], $operator);
        $this->assertNotEmpty(app(PublicationReadiness::class)->offerBlockers($offer));
        app(PublishOffer::class)->handle($offer, $operator);
        app(PublishTrack::class)->handle($track, $operator);
        $this->get('/media/'.$oldPreview->id)->assertNotFound();
        $this->get('/media/'.$run->outputs()->where('role', 'preview_tagged')->first()->id)->assertOk();
    }

    public function test_digest_verification_detects_same_size_tampering_after_its_window(): void
    {
        $track = $this->readyTrack();
        $asset = $track->assets()->where('role', 'master_wav')->where('status', 'ready')->first();
        $verifier = app(VerifiedMedia::class);
        $this->assertTrue($verifier->available($asset));
        $this->travel(30)->seconds();
        $this->assertTrue($verifier->available($asset));
        $this->travel(31)->seconds();
        $path = Storage::disk('local')->path($asset->storage_path);
        chmod($path, 0600);
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        file_put_contents($path, $bytes);
        $this->assertFalse($verifier->available($asset));
    }

    public function test_only_operators_can_review_draft_derivatives_and_masters_stay_private(): void
    {
        $track = $this->readyTrack();
        $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->first();
        $master = $track->assets()->where('role', 'master_wav')->where('status', 'ready')->first();
        $this->get('/admin/media/'.$preview->id.'/preview')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/media/'.$preview->id.'/preview')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/media/'.$preview->id.'/preview')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/admin/media/'.$master->id.'/preview')->assertNotFound();
        $this->get('/media/'.$preview->id)->assertNotFound();
    }

    public function test_draft_preview_requires_mfa_enrollment_when_the_panel_requires_it(): void
    {
        $track = $this->readyTrack();
        $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->first();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        // The testing environment registers optional MFA; apply the production panel route policy.
        Route::getRoutes()->getByName('filament.admin.media.preview')
            ->middleware(Dashboard::getRouteMiddleware($panel));
        Route::get('/admin/test-mfa-setup', fn () => 'Test setup destination')
            ->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs($this->admin())->get('/admin/media/'.$preview->id.'/preview')->assertRedirect('/admin/test-mfa-setup');
    }
}
