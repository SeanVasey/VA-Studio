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
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TrackPublicationManifestEditorTest extends TestCase
{
    use FinalizationDatabaseMigrations;

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

    public static function uncertainOutcomes(): array
    {
        return [
            'publish before commit' => ['publish', false],
            'publish after commit' => ['publish', true],
            'unpublish before commit' => ['unpublish', false],
            'unpublish after commit' => ['unpublish', true],
        ];
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_uncertain_outcome_is_reported_privately_and_requires_fresh_review(string $intent, bool $committed): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        if ($intent === 'unpublish') {
            $track = app(PublishTrack::class)->handle($track, $fixture['actor']);
        }
        $page = Livewire::test(ManageTracks::class)->mountTableAction($intent, $track);
        $before = $track->fresh()->getAttributes();
        $audits = AuditEvent::count();
        Exceptions::fake();
        $failure = new RuntimeException('Synthetic private result: storage/app/private/synthetic-original.wav');
        $command = new class($committed, $failure) extends PublishTrack
        {
            public int $calls = 0;

            public function __construct(private bool $committed, private RuntimeException $failure) {}

            public function publishManifestReviewed(array $review, User $actor): Track
            {
                $this->calls++;
                if ($this->committed) {
                    parent::publishManifestReviewed($review, $actor);
                }
                throw $this->failure;
            }

            public function unpublishReviewed(array $review, User $actor): Track
            {
                $this->calls++;
                if ($this->committed) {
                    parent::unpublishReviewed($review, $actor);
                }
                throw $this->failure;
            }
        };
        $this->app->instance(PublishTrack::class, $command);
        $page->callMountedTableAction()->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)
            ->assertSet('mountedActions', [])->assertNotified(Notification::make()->danger()
                ->title('Publication result could not be confirmed.')
                ->body('Reload tracks to check the current publication state, then open a new confirmation before trying again.')
                ->persistent())
            ->assertDontSee('synthetic-original.wav');
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception === $failure);
        Exceptions::assertReportedCount(1);
        $current = $track->fresh();
        $this->assertSame($before['publication_version'] + (int) $committed, $current->publication_version);
        $this->assertSame($committed ? ($intent === 'publish' ? 'published' : 'draft') : $before['status'], $current->status);
        $this->assertSame($audits + (int) $committed, AuditEvent::count());
        if (! $committed) {
            $this->assertSame($before, $current->getAttributes());
        }

        // A repeated request has no mounted confirmation and must not call the command again.
        $page->call('callMountedAction')->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)
            ->assertSet('mountedActions', []);
        $this->assertSame(1, $command->calls);
        $this->assertSame($current->getAttributes(), $track->fresh()->getAttributes());
        $this->assertSame($audits + (int) $committed, AuditEvent::count());
        Exceptions::assertReportedCount(1);

        // After reloading authoritative state, only a newly mounted applicable action can proceed.
        $this->app->instance(PublishTrack::class, new PublishTrack);
        $nextIntent = $committed ? ($intent === 'publish' ? 'unpublish' : 'publish') : $intent;
        $reopened = Livewire::test(ManageTracks::class)->mountTableAction($nextIntent, $current);
        $this->assertSame($current->publication_version, $reopened->get('publicationReview.publication_version'));
        $reopened->callMountedTableAction()->assertSet('publicationReview', null)->assertSet('publicationTableContext', null);
        $this->assertSame($current->publication_version + 1, $track->fresh()->publication_version);
        $this->assertSame($nextIntent === 'publish' ? 'published' : 'draft', $track->fresh()->status);
        $this->assertSame($audits + (int) $committed + 1, AuditEvent::count());
    }

    public static function revocations(): array
    {
        return ['staff role' => ['is_admin', false], 'MFA enrollment' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_authorization_failure_propagates_after_consuming_the_review(string $field, mixed $value): void
    {
        $fixture = $this->draft();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $fixture['actor']->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $page = Livewire::test(ManageTracks::class)->mountTableAction('publish', $fixture['track']);
            $component = $page->instance();
            DB::table('users')->where('id', $fixture['actor']->id)->update([$field => $value]);
            $before = $fixture['track']->fresh()->getAttributes();
            $audits = AuditEvent::count();
            Exceptions::fake();
            try {
                $component->applyReviewedPublication($fixture['track'], $component->getMountedAction(), 'publish');
                $this->fail('Withdrawn authority was swallowed by uncertain-result handling.');
            } catch (AuthorizationException) {
            }
            $this->assertNull($component->publicationReview);
            $this->assertNull($component->publicationTableContext);
            $this->assertSame($before, $fixture['track']->fresh()->getAttributes());
            $this->assertSame($audits, AuditEvent::count());
            Notification::assertNotNotified();
            Exceptions::assertNothingReported();
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_missing_actor_keeps_its_forbidden_response_without_an_uncertainty_notification(): void
    {
        $fixture = $this->draft();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('publish', $fixture['track']);
        $component = $page->instance();
        $before = $fixture['track']->fresh()->getAttributes();
        $audits = AuditEvent::count();
        Exceptions::fake();
        auth()->logout();
        try {
            $component->applyReviewedPublication($fixture['track'], $component->getMountedAction(), 'publish');
            $this->fail('A missing actor lost its forbidden response.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull($component->publicationReview);
        $this->assertNull($component->publicationTableContext);
        $this->assertSame($before, $fixture['track']->fresh()->getAttributes());
        $this->assertSame($audits, AuditEvent::count());
        Notification::assertNotNotified();
        Exceptions::assertNothingReported();
    }

    public function test_missing_resource_exception_keeps_its_original_identity(): void
    {
        $fixture = $this->draft();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('publish', $fixture['track']);
        $component = $page->instance();
        $before = $fixture['track']->fresh()->getAttributes();
        $audits = AuditEvent::count();
        Exceptions::fake();
        $failure = (new ModelNotFoundException)->setModel(Track::class, [$fixture['track']->id]);
        $this->app->instance(PublishTrack::class, new class($failure) extends PublishTrack
        {
            public function __construct(private ModelNotFoundException $failure) {}

            public function publishManifestReviewed(array $review, User $actor): Track
            {
                throw $this->failure;
            }
        });
        try {
            $component->applyReviewedPublication($fixture['track'], $component->getMountedAction(), 'publish');
            $this->fail('A missing resource was changed into uncertain-result recovery.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertNull($component->publicationReview);
        $this->assertNull($component->publicationTableContext);
        $this->assertSame($before, $fixture['track']->fresh()->getAttributes());
        $this->assertSame($audits, AuditEvent::count());
        Notification::assertNotNotified();
        Exceptions::assertNothingReported();
    }
}
