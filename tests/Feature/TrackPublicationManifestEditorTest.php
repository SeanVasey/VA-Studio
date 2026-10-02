<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TrackPublicationManifestEditorTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function draft(): array
    {
        $fixture = QuoteFixtures::selection();
        $fixture['track'] = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $this->actingAs($fixture['actor']);

        return $fixture;
    }

    public function test_publish_confirmation_retains_only_the_actor_bound_manifest_identity(): void
    {
        $fixture = $this->draft();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('publish', $fixture['track']);
        $review = $page->get('publicationReview');
        $this->assertEqualsCanonicalizing(['schema_version', 'actor_id', 'track_id', 'intent',
            'metadata_version', 'publication_version', 'status', 'manifest_hash'], array_keys($review));
        $this->assertSame(2, $review['schema_version']);
        $this->assertSame($fixture['actor']->id, $review['actor_id']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $review['manifest_hash']);
        $this->assertSame('draft', $fixture['track']->fresh()->status);
        foreach ($fixture['media'] as $asset) {
            $this->assertStringNotContainsString($asset->storage_path, json_encode($review, JSON_THROW_ON_ERROR));
        }
    }

    public function test_unready_track_cannot_open_a_publish_confirmation_and_shows_a_blocker(): void
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Private incomplete draft', 'slug' => 'private-incomplete-draft'], $actor);
        $this->actingAs($actor);
        $before = $track->fresh()->getAttributes();
        Livewire::test(ManageTracks::class)->mountTableAction('publish', $track)
            ->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)
            ->assertSet('mountedActions', [])->assertNotified('Publication blocked');
        $this->assertSame($before, $track->fresh()->getAttributes());
        $this->assertSame(0, AuditEvent::where('action', 'catalog.track.published')->count());
    }

    public function test_changed_offer_consumes_confirmation_without_recapture_and_reopening_reviews_the_new_offer(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        $page = Livewire::test(ManageTracks::class)->mountTableAction('publish', $track);
        $hash = $page->get('publicationReview.manifest_hash');
        $offer = app(SaveOfferDraft::class)->handle($fixture['offer'], ['price_minor' => 1234,
            'deliverable_asset_ids' => [$fixture['media']['master_wav']->id]], $fixture['actor']);
        app(PublishOffer::class)->handle($offer, $fixture['actor']);
        $before = AuditEvent::count();
        $page->callMountedTableAction()->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)
            ->assertSet('mountedActions', [])->assertNotified('Publication blocked');
        $this->assertSame('draft', $track->fresh()->status);
        $this->assertSame($track->publication_version, $track->fresh()->publication_version);
        $this->assertSame($before, AuditEvent::count());
        $reopened = Livewire::test(ManageTracks::class)->mountTableAction('publish', $track->fresh());
        $this->assertNotSame($hash, $reopened->get('publicationReview.manifest_hash'));
        $reopened->callMountedTableAction()->assertSet('publicationReview', null)->assertSet('publicationTableContext', null);
        $this->assertSame('published', $track->fresh()->status);
        $this->assertSame($track->publication_version + 1, $track->fresh()->publication_version);
    }

    public function test_legacy_server_review_cannot_fall_back_to_metadata_only_publication(): void
    {
        $fixture = $this->draft();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('publish', $fixture['track']);
        $component = $page->instance();
        $component->publicationReview = app(PublishTrack::class)->review($fixture['track'], $fixture['actor'], 'publish');
        $before = AuditEvent::count();
        try {
            $component->applyReviewedPublication($fixture['track'], $component->getMountedAction(), 'publish');
            $this->fail('A legacy review bypassed the current evidence confirmation.');
        } catch (Cancel) {
        }
        $this->assertNull($component->publicationReview);
        $this->assertNull($component->publicationTableContext);
        $this->assertSame('draft', $fixture['track']->fresh()->status);
        $this->assertSame($before, AuditEvent::count());
    }

    public function test_uncertain_success_consumes_review_before_the_domain_result_returns(): void
    {
        $fixture = $this->draft();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('publish', $fixture['track']);
        $component = $page->instance();
        $before = AuditEvent::where('action', 'catalog.track.published')->count();
        $this->app->instance(PublishTrack::class, new class extends PublishTrack
        {
            public function publishManifestReviewed(array $review, User $actor): Track
            {
                parent::publishManifestReviewed($review, $actor);
                throw new RuntimeException('Synthetic lost result after commit.');
            }
        });
        try {
            $component->applyReviewedPublication($fixture['track'], $component->getMountedAction(), 'publish');
            $this->fail('Expected the synthetic uncertain result.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic lost result after commit.', $exception->getMessage());
        }
        $this->assertNull($component->publicationReview);
        $this->assertNull($component->publicationTableContext);
        $this->assertSame('published', $fixture['track']->fresh()->status);
        $this->assertSame($before + 1, AuditEvent::where('action', 'catalog.track.published')->count());
        try {
            $component->applyReviewedPublication($fixture['track'], $component->getMountedAction(), 'publish');
            $this->fail('A consumed review was recaptured for retry.');
        } catch (Cancel) {
        }
        $this->assertSame($before + 1, AuditEvent::where('action', 'catalog.track.published')->count());
    }
}
