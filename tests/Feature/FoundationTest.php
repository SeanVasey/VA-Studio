<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
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
        $author = $this->admin();
        $approver = $this->admin();
        $template = LicenseTemplate::create(['name' => 'Test only', 'slug' => 'test-'.uniqid(), 'type' => 'non-exclusive']);

        return LicenseVersion::create([
            'license_template_id' => $template->id, 'version' => 1,
            'authored_source' => 'NON-BINDING TEST FIXTURE ONLY.',
            'structured_terms' => ['features' => ['Test WAV file'], 'required_asset_roles' => ['master_wav']],
            'status' => 'approved', 'author_id' => $author->id, 'approved_by' => $approver->id,
            'approved_at' => now(), 'approval_reference' => 'TEST-ONLY-REVIEW',
            'renderer_version' => 'test-fixture-v1', 'render_fixture_hash' => str_repeat('a', 64),
        ]);
    }

    private function readyTrack(): Track
    {
        $operator = $this->admin();
        $track = Track::create(['title' => 'Test fixture', 'slug' => 'test-'.uniqid(), 'artist' => 'Test', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test', 'duration_seconds' => 120, 'waveform' => [0.2, 0.5, 0.8]]);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY', 'sample_disclosure' => 'Synthetic test only', 'status' => 'verified', 'verified_by' => $operator->id, 'verified_at' => now()]);
        $master = null;
        foreach (['artwork', 'preview_tagged', 'master_wav'] as $role) {
            $path = 'fixtures/'.$track->id.'/'.$role;
            Storage::disk('local')->put($path, 'fixture-'.$role);
            $asset = MediaAsset::create(['track_id' => $track->id, 'role' => $role, 'disk' => 'local', 'storage_path' => $path, 'original_name' => 'fixture', 'mime_type' => $role === 'artwork' ? 'image/png' : 'audio/wav', 'size_bytes' => strlen('fixture-'.$role), 'sha256' => hash('sha256', 'fixture-'.$role), 'status' => 'ready', 'verified_by' => $operator->id, 'verified_at' => now()]);
            if ($role === 'master_wav') {
                $master = $asset;
            }
        }
        $license = app(PublishLicense::class)->handle($this->approvedLicense(), $operator);
        Offer::create(['track_id' => $track->id, 'license_version_id' => $license->id, 'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$master->id], 'is_active' => true]);

        return $track;
    }

    public function test_storefront_and_empty_catalog_do_not_invent_products(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/api/catalog')->assertOk()->assertExactJson(['tracks' => [], 'licenseTiers' => [], 'commerceEnabled' => false]);
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
        Livewire::test(ManageLicenseVersions::class)->callAction('create', data: ['license_template_id' => $template->id, 'version' => 1, 'author_id' => $other->id, 'authored_source' => 'Test draft only', 'structured_terms' => ['features' => ['Test'], 'required_asset_roles' => ['master_wav']]])->assertHasNoActionErrors();
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
        $this->get('/media/'.$track->assets()->where('role', 'master_wav')->first()->id)->assertNotFound();
        $this->get('/media/'.$track->assets()->where('role', 'artwork')->first()->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_missing_or_wrong_track_delivery_revision_blocks_publication(): void
    {
        $track = $this->readyTrack();
        $offer = $track->offers()->first();
        $offer->update(['deliverable_asset_ids' => [99999]]);
        $this->assertNotEmpty(app(PublicationReadiness::class)->blockers($track));
        $this->expectException(ValidationException::class);
        app(PublishTrack::class)->handle($track, $this->admin());
    }

    public function test_missing_asset_after_publication_hides_catalog_and_media(): void
    {
        $track = $this->readyTrack();
        app(PublishTrack::class)->handle($track, $this->admin());
        Storage::disk('local')->delete($track->assets()->where('role', 'master_wav')->first()->storage_path);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->get('/tracks/'.$track->slug)->assertNotFound();
        $this->get('/media/'.$track->assets()->where('role', 'artwork')->first()->id)->assertNotFound();
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
        $template = LicenseTemplate::create(['name' => 'Review fixture', 'slug' => 'review-fixture', 'type' => 'non-exclusive']);
        $version = LicenseVersion::create(['license_template_id' => $template->id, 'version' => 1, 'authored_source' => 'NON-BINDING TEST ONLY', 'structured_terms' => ['features' => ['Test'], 'required_asset_roles' => ['master_wav']], 'author_id' => $author->id, 'status' => 'draft']);
        $review = app(ReviewLicense::class);
        $review->submit($version, $author);
        $evidence = ['approval_reference' => 'TEST-ONLY', 'renderer_version' => 'test-only', 'render_fixture_hash' => str_repeat('b', 64)];
        try {
            $review->approve($version, $author, $evidence);
            $this->fail('Author self-approval was accepted.');
        } catch (ValidationException) {
        }
        $review->approve($version, $reviewer, $evidence);
        $this->assertDatabaseHas('license_versions', ['id' => $version->id, 'status' => 'approved', 'approved_by' => $reviewer->id]);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $version->id, 'action' => 'rights.license.approved', 'actor_id' => $reviewer->id]);
    }

    public function test_self_approved_license_cannot_be_published(): void
    {
        $license = $this->approvedLicense();
        $license->update(['approved_by' => $license->author_id]);
        $this->expectException(ValidationException::class);
        app(PublishLicense::class)->handle($license, $this->admin());
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
        $track->assets()->first()->update(['storage_path' => 'changed']);
    }
}
