<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Filament\Resources\OfferResource\Pages\ManageOffers;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class LicensingAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_operator_review_actions_bind_the_actual_submission_and_preserve_a_successor_history(): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($author);
        $this->actingAs($author);
        Livewire::test(ManageLicenseVersions::class)
            ->callTableAction('submit_review', $draft)->assertHasNoTableActionErrors();
        $draft->refresh();
        Livewire::test(ManageLicenseVersions::class)->assertTableActionHidden('approve', $draft);
        $this->actingAs($reviewer);
        Livewire::test(ManageLicenseVersions::class)
            ->callTableAction('approve', $draft, data: ['approval_reference' => 'SYNTHETIC-UI-REVIEW', 'review_hash' => str_repeat('f', 64), 'summary_consistency_confirmed' => true])
            ->assertHasTableActionErrors();
        $this->assertDatabaseCount('license_review_evidence', 0);
        Livewire::test(ManageLicenseVersions::class)
            ->callTableAction('approve', $draft, data: ['approval_reference' => 'SYNTHETIC-UI-REVIEW', 'review_hash' => $draft->submission_hash, 'summary_consistency_confirmed' => true])
            ->assertHasNoTableActionErrors();
        Livewire::test(ManageLicenseVersions::class)
            ->callTableAction('publish', $draft->fresh())->assertHasNoTableActionErrors();
        $original = $draft->refresh()->toArray();
        Livewire::test(ManageLicenseVersions::class)
            ->callTableAction('successor', $draft)->assertHasNoTableActionErrors();
        $successor = LicenseVersion::where('predecessor_id', $draft->id)->sole();
        $this->assertSame(2, $successor->version);
        $this->assertSame('draft', $successor->status);
        Livewire::test(ManageLicenseVersions::class)
            ->callTableAction('edit', $successor, data: ['authored_source' => 'Changed synthetic text only.', 'structured_terms' => $successor->structured_terms])
            ->assertHasNoTableActionErrors()
            ->mountTableAction('compare', $successor)->assertMountedActionModalSee('Changed synthetic text only.');
        $this->assertSame($original, $draft->refresh()->toArray());
    }

    public function test_all_content_contributors_are_excluded_from_the_approval_action(): void
    {
        $creator = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $draft = LicenseFixtures::draft($creator);
        $draft = app(UpdateLicenseDraft::class)->handle($draft, ['authored_source' => 'Revised synthetic content.', 'structured_terms' => $draft->structured_terms], $editor);
        $submitted = app(ReviewLicense::class)->submit($draft, $editor);
        foreach ([$creator, $editor] as $contributor) {
            $this->actingAs($contributor);
            Livewire::test(ManageLicenseVersions::class)->assertTableActionHidden('approve', $submitted);
        }
    }

    public function test_license_preview_requires_staff_and_escapes_source_without_public_caching(): void
    {
        $draft = LicenseFixtures::draft(content: ['authored_source' => '<script>alert("synthetic")</script> {{buyer}}']);
        $url = route('filament.admin.licenses.preview', $draft);
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(LicenseFixtures::admin())->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'")
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertSee('{{buyer}}');
    }

    public function test_unsupported_legacy_schema_has_an_escaped_retained_source_view_without_new_evidence(): void
    {
        $supported = LicenseFixtures::draft();
        $legacy = LicenseVersion::create(['license_template_id' => $supported->license_template_id, 'version' => 2, 'author_id' => $supported->author_id,
            'status' => 'draft', 'authored_source' => '<script>legacy</script>', 'structured_terms' => ['unknown_historic_term' => 'Retain me']]);
        $this->actingAs(LicenseFixtures::admin())->get(route('filament.admin.licenses.preview', $legacy))
            ->assertOk()->assertSee('Retained license source')->assertSee('Retain me')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
        $this->assertDatabaseCount('license_review_evidence', 0);
        $this->assertNull($legacy->fresh()->submission_hash);
    }

    public function test_license_preview_enforces_required_panel_mfa(): void
    {
        $draft = LicenseFixtures::draft();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        Route::getRoutes()->getByName('filament.admin.licenses.preview')->middleware(Dashboard::getRouteMiddleware($panel));
        Route::get('/admin/test-license-mfa', fn () => 'Test setup destination')->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs(LicenseFixtures::admin())->get(route('filament.admin.licenses.preview', $draft))->assertRedirect('/admin/test-license-mfa');
    }

    public function test_offer_admin_requires_explicit_publication_and_keeps_previous_commercial_history(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $track = Track::create(['title' => 'Synthetic admin fixture', 'slug' => 'synthetic-admin-fixture', 'artist' => 'Test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY', 'sample_disclosure' => 'Synthetic', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $license = LicenseFixtures::published($actor);
        Livewire::test(ManageOffers::class)->callAction('create', data: [
            'track_id' => $track->id, 'license_version_id' => $license->id, 'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$media['master_wav']->id], 'is_active' => true,
        ])->assertHasNoActionErrors();
        $offer = Offer::sole();
        $this->assertFalse($offer->is_active);
        $this->assertNull($offer->current_revision_id);
        Livewire::test(ManageOffers::class)->callTableAction('publish_revision', $offer)->assertHasNoTableActionErrors();
        $first = $offer->refresh()->currentRevision;
        app(PublishTrack::class)->handle($track, $actor);
        Livewire::test(ManageOffers::class)->callTableAction('edit', $offer, data: ['price_minor' => 7999])->assertHasNoTableActionErrors();
        $this->getJson('/api/catalog')->assertJsonPath('tracks.0.offers.0.priceMinor', 4999)->assertJsonPath('tracks.0.offers.0.offerRevisionId', $first->id);
        Livewire::test(ManageOffers::class)->callTableAction('publish_revision', $offer->fresh())->assertHasNoTableActionErrors();
        $this->getJson('/api/catalog')->assertJsonPath('tracks.0.offers.0.priceMinor', 7999);
        $this->assertSame(4999, $first->fresh()->price_minor);
        Livewire::test(ManageOffers::class)->mountTableAction('history', $offer->fresh())->assertMountedActionModalSee($first->snapshot_hash);
        Livewire::test(ManageOffers::class)->callTableAction('deactivate', $offer->fresh())->assertHasNoTableActionErrors();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->assertDatabaseCount('offer_revisions', 2);
    }
}
