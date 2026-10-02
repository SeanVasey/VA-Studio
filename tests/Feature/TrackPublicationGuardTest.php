<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class TrackPublicationGuardTest extends TestCase
{
    // Reviewed commands must own their authority and publication transaction; fixtures are committed.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function intents(): array
    {
        return ['publish' => ['publish'], 'unpublish' => ['unpublish']];
    }

    private function fixture(string $intent): array
    {
        $fixture = QuoteFixtures::selection();
        if ($intent === 'publish') {
            $fixture['track'] = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        }

        return $fixture;
    }

    private function review(Track $track, User $actor, string $intent): array
    {
        $this->assertSame(0, DB::transactionLevel(), 'Review evidence requires committed fixtures.');

        return app(PublishTrack::class)->review($track, $actor, $intent);
    }

    private function apply(array $review, User $actor, string $intent): Track
    {
        return $intent === 'publish' ? app(PublishTrack::class)->publishReviewed($review, $actor)
            : app(PublishTrack::class)->unpublishReviewed($review, $actor);
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), [
            'tracks', 'media_assets', 'media_processing_runs', 'rights_declarations', 'license_versions',
            'offers', 'offer_revisions', 'orders', 'license_grants', 'site_publications', 'site_publication_schedules', 'audit_events',
        ]);
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid publication confirmation was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('publication', $error->errors());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized publication command succeeded.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('intents')]
    public function test_review_is_exact_read_only_actor_bound_and_apply_advances_only_publication_fields(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $track->refresh();
        $before = $this->evidence();
        $attributes = $track->getAttributes();
        $review = $this->review($track, $actor, $intent);
        $this->assertSame([
            'schema_version' => 1, 'actor_id' => $actor->id, 'track_id' => $track->id, 'intent' => $intent,
            'metadata_version' => $track->metadata_version, 'publication_version' => $track->publication_version, 'status' => $track->status,
        ], $review);
        $this->assertSame($before, $this->evidence());
        $updated = $this->apply($review, $actor, $intent);
        $this->assertSame($intent === 'publish' ? 'published' : 'draft', $updated->status);
        $this->assertSame($review['publication_version'] + 1, $updated->publication_version);
        $this->assertSame($review['metadata_version'], $updated->metadata_version);
        $this->assertSame($track->slug, $updated->published_slug);
        if ($intent === 'unpublish') {
            $this->assertSame($track->getRawOriginal('published_at'), $updated->getRawOriginal('published_at'));
        }
        $new = $updated->getAttributes();
        foreach (['status', 'published_at', 'publication_version', 'updated_at'] as $field) {
            unset($attributes[$field], $new[$field]);
        }
        $this->assertSame($attributes, $new);
        $audit = AuditEvent::where('action', 'catalog.track.'.($intent === 'publish' ? 'published' : 'unpublished'))->latest('id')->firstOrFail();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($track->id, $audit->subject_id);
        $this->assertSame(Track::class, $audit->subject_type);
        $this->assertSame(['schema_version' => 1, 'metadata_version' => $review['metadata_version'],
            'previous_publication_version' => $review['publication_version'], 'publication_version' => $review['publication_version'] + 1], $audit->context);
        $after = $this->evidence();
        $this->assertSame(array_slice($before, 1, -1), array_slice($after, 1, -1), 'Publication must leave media, rights, licenses and commerce untouched.');
        $this->invalid(fn () => $this->apply($review, $actor, $intent));
        $this->assertSame($after, $this->evidence());
    }

    #[DataProvider('intents')]
    public function test_metadata_change_and_same_second_state_aba_reject_the_original_review(string $intent): void
    {
        $this->freezeSecond();
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $command = app(PublishTrack::class);
        $review = $this->review($track, $actor, $intent);
        $changed = app(SaveTrackMetadata::class)->handle($track, ['mood' => 'Current metadata', 'metadata_version' => $track->metadata_version], $actor);
        $before = $this->evidence();
        $this->invalid(fn () => $this->apply($review, $actor, $intent));
        $this->assertSame($before, $this->evidence());
        $freshReview = $this->review($changed, $actor, $intent);
        $originalTime = $changed->updated_at->format('Y-m-d H:i:s');
        if ($intent === 'publish') {
            $changed = $command->handle($changed, $actor);
            $changed = $command->unpublish($changed, $actor);
        } else {
            $changed = $command->unpublish($changed, $actor);
            $changed = $command->handle($changed, $actor);
        }
        $this->assertSame($freshReview['status'], $changed->status);
        $this->assertSame($freshReview['metadata_version'], $changed->metadata_version);
        $this->assertSame($originalTime, $changed->updated_at->format('Y-m-d H:i:s'), 'Timestamp alone demonstrably cannot detect this ABA.');
        $this->assertSame($freshReview['publication_version'] + 2, $changed->publication_version);
        $before = $this->evidence();
        $this->invalid(fn () => $this->apply($freshReview, $actor, $intent));
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('intents')]
    public function test_review_schema_ids_intent_and_types_are_strict_without_any_write(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $review = $this->review($track, $actor, $intent);
        $other = LicenseFixtures::admin();
        $before = $this->evidence();
        $changes = ['schema_version' => 2, 'actor_id' => 0, 'track_id' => 0, 'intent' => $intent === 'publish' ? 'unpublish' : 'publish',
            'metadata_version' => (string) $review['metadata_version'], 'publication_version' => (string) $review['publication_version'],
            'status' => $intent === 'publish' ? 'published' : 'draft', 'unreviewed' => true];
        foreach ($changes as $key => $value) {
            $this->invalid(fn () => $this->apply(array_replace($review, [$key => $value]), $actor, $intent));
        }
        foreach (array_keys($review) as $key) {
            $missing = $review;
            unset($missing[$key]);
            $this->invalid(fn () => $this->apply($missing, $actor, $intent));
        }
        foreach (['metadata_version', 'publication_version'] as $key) {
            foreach ([-1, 2147483648, null, true, 0.5] as $value) {
                $this->invalid(fn () => $this->apply(array_replace($review, [$key => $value]), $actor, $intent));
            }
        }
        $this->denied(fn () => $this->apply($review, $other, $intent));
        $this->denied(fn () => $this->apply(array_replace($review, ['actor_id' => $other->id]), $actor, $intent));
        $this->assertSame($before, $this->evidence());
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach (['publish', 'unpublish'] as $intent) {
            foreach (['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null], 'MFA enrollment' => ['app_authentication_secret', null]] as $name => [$field, $value]) {
                $cases[$intent.' '.$name] = [$intent, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function test_capture_reviewed_and_legacy_commands_reauthorize_the_persisted_actor_and_mfa(string $intent, string $field, mixed $value): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $review = $this->review($track, $actor, $intent);
            $oldActor = $actor->fresh();
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $this->denied(fn () => $this->review($track, $oldActor, $intent));
            $this->denied(fn () => $this->apply($review, $oldActor, $intent));
            $this->denied(fn () => $intent === 'publish' ? app(PublishTrack::class)->handle($track, $oldActor) : app(PublishTrack::class)->unpublish($track, $oldActor));
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    #[DataProvider('intents')]
    public function test_deleted_or_forged_staff_and_unverified_nonstaff_cannot_capture_or_apply(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $review = $this->review($track, $actor, $intent);
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        $forged = new User;
        $forged->forceFill(['id' => $actor->id, 'is_admin' => true, 'email_verified_at' => now()]);
        $before = $this->evidence();
        foreach ([User::factory()->create(), $unverified, $deleted, $forged] as $denied) {
            $this->denied(fn () => $this->review($track, $denied, $intent));
            $this->denied(fn () => $this->apply($review, $denied, $intent));
        }
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('intents')]
    public function test_ambient_transactions_are_refused_without_consuming_or_writing_under_caller_snapshot(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $review = $this->review($track, $actor, $intent);
        $before = $this->evidence();
        DB::beginTransaction();
        try {
            foreach ([fn () => app(PublishTrack::class)->review($track, $actor, $intent), fn () => $this->apply($review, $actor, $intent)] as $operation) {
                try {
                    $operation();
                    $this->fail('Reviewed publication inherited a caller transaction.');
                } catch (LogicException $error) {
                    $this->assertSame('Reviewed track publication requires a standalone transaction.', $error->getMessage());
                }
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame($before, $this->evidence());
            }
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame($review['publication_version'] + 1, $this->apply($review, $actor, $intent)->publication_version);
    }

    #[DataProvider('intents')]
    public function test_audit_failure_rolls_back_publication_counter_state_and_url(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $review = $this->review($track, $actor, $intent);
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic publication audit failure'));
        try {
            $this->apply($review, $actor, $intent);
            $this->fail('Audit failure did not abort publication.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic publication audit failure', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('intents')]
    public function test_signed_capacity_is_checked_before_publication_and_audit_write(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $review = $this->review($track, $actor, $intent);
        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 2147483647]);
        $track->refresh();
        $review['publication_version'] = 2147483647;
        $this->invalid(fn () => $this->review($track, $actor, $intent));
        $before = $this->evidence();
        $this->invalid(fn () => $this->apply($review, $actor, $intent));
        $this->invalid(fn () => $intent === 'publish' ? app(PublishTrack::class)->handle($track, $actor) : app(PublishTrack::class)->unpublish($track, $actor));
        $this->assertSame($before, $this->evidence());
    }

    public function test_current_readiness_is_rechecked_without_publication_or_audit_writes(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture('publish');
        $review = $this->review($track, $actor, 'publish');
        DB::table('rights_declarations')->where('track_id', $track->id)->update(['status' => 'pending']);
        $this->assertNotSame([], app(PublicationReadiness::class)->blockers($track->fresh()));
        $before = $this->evidence();
        $this->invalid(fn () => $this->apply($review, $actor, 'publish'));
        $this->assertSame($before, $this->evidence());
        $draft = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Incomplete publication', 'slug' => 'incomplete-publication'], $actor);
        $review = $this->review($draft, $actor, 'publish');
        $before = $this->evidence();
        $this->invalid(fn () => $this->apply($review, $actor, 'publish'));
        $this->assertSame($before, $this->evidence());
        $this->assertNull($draft->fresh()->published_slug);
        $this->assertNull($draft->fresh()->published_at);
        $this->assertSame(0, $draft->fresh()->publication_version);
    }

    public function test_legacy_same_state_commands_advance_each_revision_and_preserve_callers_and_reserved_url(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture('unpublish');
        $command = app(PublishTrack::class);
        $caller = $track->replicate();
        $caller->setRawAttributes($track->getAttributes(), true);
        $caller->exists = true;
        $original = $caller->getAttributes();
        $updated = $command->handle($caller, $actor);
        $this->assertSame($original, $caller->getAttributes(), 'Trusted immediate callers retain their existing object semantics.');
        $this->assertSame(2, $updated->publication_version);
        $updated = $command->unpublish($updated, $actor);
        $updated = $command->unpublish($updated, $actor);
        $this->assertSame(4, $updated->publication_version);
        $this->assertSame($track->slug, $updated->slug);
        $this->assertSame($track->slug, $updated->published_slug);
        $this->assertSame('draft', $updated->status);
        $audits = AuditEvent::whereIn('action', ['catalog.track.published', 'catalog.track.unpublished'])->orderBy('id')->get();
        $this->assertCount(4, $audits);
        foreach ($audits as $index => $audit) {
            $this->assertSame(['schema_version' => 1, 'metadata_version' => $track->metadata_version,
                'previous_publication_version' => $index, 'publication_version' => $index + 1], $audit->context);
            $this->assertSame($actor->id, $audit->actor_id);
        }
    }

    #[DataProvider('intents')]
    public function test_filament_confirmation_is_locked_and_stale_metadata_consumes_without_publication_then_reopening_recovers(string $intent): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture($intent);
        $this->actingAs($actor);
        $page = Livewire::test(ManageTracks::class)->mountTableAction($intent, $track)
            ->assertSet('publicationReview.actor_id', $actor->id)->assertSet('publicationReview.track_id', $track->id)
            ->assertSet('publicationReview.intent', $intent)->assertSet('publicationReview.publication_version', $track->publication_version);
        app(SaveTrackMetadata::class)->handle($track, ['mood' => 'Metadata changed after confirmation', 'metadata_version' => $track->metadata_version], $actor);
        $before = $this->evidence();
        $page->callMountedTableAction()->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)
            ->assertSet('mountedActions', [])->assertNotified('Publication blocked');
        $this->assertSame($before, $this->evidence());
        Livewire::test(ManageTracks::class)->callTableAction($intent, $track->fresh())->assertHasNoTableActionErrors()
            ->assertSet('publicationReview', null)->assertSet('publicationTableContext', null);
        $this->assertSame($intent === 'publish' ? 'published' : 'draft', $track->fresh()->status);
        $this->assertSame($track->publication_version + 1, $track->fresh()->publication_version);
    }

    public function test_filament_same_second_publication_aba_cannot_reuse_mounted_unpublish_confirmation(): void
    {
        $this->freezeSecond();
        ['actor' => $actor, 'track' => $track] = $this->fixture('unpublish');
        $this->actingAs($actor);
        $page = Livewire::test(ManageTracks::class)->mountTableAction('unpublish', $track);
        $command = app(PublishTrack::class);
        $current = $command->unpublish($track, $actor);
        $current = $command->handle($current, $actor);
        $this->assertSame($track->status, $current->status);
        $this->assertSame($track->updated_at->format('Y-m-d H:i:s'), $current->updated_at->format('Y-m-d H:i:s'));
        $before = $this->evidence();
        $page->callMountedTableAction()->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)
            ->assertSet('mountedActions', [])->assertNotified('Publication blocked');
        $this->assertSame($before, $this->evidence());
    }

    public function test_filament_cancel_replacement_page_and_search_changes_consume_confirmations_and_locked_state_rejects_client_tampering(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture('unpublish');
        for ($index = 0; $index < 10; $index++) {
            app(SaveTrackMetadata::class)->handle(null, ['title' => 'Pagination draft '.$index, 'slug' => 'pagination-draft-'.$index], $actor);
        }
        $this->actingAs($actor);
        $before = $this->evidence();
        $mount = fn () => Livewire::test(ManageTracks::class)->mountTableAction('unpublish', $track);
        $mount()->unmountAction()->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)->assertSet('mountedActions', []);
        $mount()->mountAction('create')->assertSet('publicationReview', null)->assertSet('publicationTableContext', null);
        $page = $mount();
        $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName())
            ->assertSet('publicationReview', null)->assertSet('publicationTableContext', null)->assertSet('mountedActions', []);
        $mount()->set('tableSearch', 'different current table')->assertSet('publicationReview', null)
            ->assertSet('publicationTableContext', null)->assertSet('mountedActions', []);
        foreach (['publicationReview.track_id' => $track->id + 1, 'publicationReview.publication_version' => 999,
            'publicationTableContext.page' => '2'] as $property => $value) {
            try {
                $mount()->set($property, $value);
                $this->fail('The client changed locked publication confirmation state.');
            } catch (CannotUpdateLockedPropertyException) {
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_filament_server_record_retargeting_and_missing_review_are_rejected_without_fallback_to_immediate_command(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->fixture('unpublish');
        ['track' => $other] = $this->fixture('unpublish');
        $this->actingAs($actor);
        $before = $this->evidence();
        foreach (['retarget', 'missing'] as $case) {
            $page = Livewire::test(ManageTracks::class)->mountTableAction('unpublish', $track);
            // Direct server mutation exercises mounted-record binding separately from the client Locked guard.
            $component = $page->instance();
            $action = $component->getMountedAction();
            $component->publicationReview = $case === 'missing' ? null
                : array_replace($component->publicationReview, ['track_id' => $other->id]);
            try {
                $component->applyReviewedPublication($track, $action, 'unpublish');
                $this->fail('An invalid server confirmation reached immediate publication.');
            } catch (Cancel) {
            }
            $this->assertNull($component->publicationReview);
            $this->assertNull($component->publicationTableContext);
            Notification::assertNotified('Publication blocked');
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_invalid_capture_intent_state_and_deleted_draft_identity_cannot_create_or_apply_a_review(): void
    {
        ['actor' => $actor, 'track' => $published] = $this->fixture('unpublish');
        $draft = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Deleted reviewed draft', 'slug' => 'deleted-reviewed-draft'], $actor);
        $before = $this->evidence();
        $this->invalid(fn () => $this->review($published, $actor, 'publish'));
        $this->invalid(fn () => $this->review($draft, $actor, 'unpublish'));
        foreach (['Publish', 'archive', '', 'publish '] as $intent) {
            $this->invalid(fn () => $this->review($draft, $actor, $intent));
        }
        $this->assertSame($before, $this->evidence());
        $review = $this->review($draft, $actor, 'publish');
        $draft->delete();
        $before = $this->evidence();
        foreach ([fn () => $this->review($draft, $actor, 'publish'), fn () => $this->apply($review, $actor, 'publish')] as $operation) {
            try {
                $operation();
                $this->fail('A deleted draft identity authorized publication.');
            } catch (ModelNotFoundException) {
            }
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertSame($before, $this->evidence());
    }
}
