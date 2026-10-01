<?php

namespace Tests\Feature;

use App\Domain\Catalog\BulkAddTrackTags;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class BulkTrackTagsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function drafts(User $actor, int $count = 2): array
    {
        return array_map(fn ($index) => app(SaveTrackMetadata::class)->handle(null, [
            'title' => 'Synthetic bulk '.$index, 'slug' => 'synthetic-bulk-'.$index,
            'tags' => ['Original '.$index], 'description' => 'Private bulk working note',
        ], $actor), range(1, $count));
    }

    private function review(array $tracks, User $actor, array $additions = ['New tag']): array
    {
        return app(BulkAddTrackTags::class)->review(array_map(fn ($track) => $track->id, $tracks), $additions, $actor);
    }

    private function evidence(): array
    {
        return [DB::table('tracks')->orderBy('id')->get()->toJson(), DB::table('audit_events')->orderBy('id')->get()->toJson()];
    }

    public function test_review_is_read_only_and_explicit_apply_preserves_order_with_only_minimized_per_track_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $before = $this->evidence();
        $review = $this->review(array_reverse($tracks), $actor, ['Original 1', 'New tag', 'new tag']);
        $this->assertSame($before, $this->evidence());
        $this->assertSame(array_column($tracks, 'id'), array_column($review['tracks'], 'id'));
        $this->assertSame(['Original 1'], $review['tracks'][0]['before_tags']);
        $this->assertSame(['Original 1', 'New tag', 'new tag'], $review['tracks'][0]['after_tags']);
        $retained = array_map(fn ($track) => $track->fresh()->getAttributes(), $tracks);
        $result = app(BulkAddTrackTags::class)->apply($review, $actor);
        $this->assertSame(array_column($tracks, 'id'), $result['changed_ids']);
        $this->assertSame([], $result['unchanged_ids']);
        foreach ($tracks as $index => $track) {
            $after = $track->fresh()->getAttributes();
            foreach (['tags', 'metadata_version', 'updated_at'] as $field) {
                unset($retained[$index][$field], $after[$field]);
            }
            $this->assertSame($retained[$index], $after);
            $audit = AuditEvent::where('action', 'catalog.track.metadata_updated')->where('subject_id', $track->id)->sole();
            $this->assertSame($actor->id, $audit->actor_id);
            $this->assertSame(['tags'], $audit->context['changed_fields']);
            $this->assertSame(2, $audit->context['metadata_version']);
            $this->assertStringNotContainsString('Private bulk', json_encode($audit->context));
            $this->assertStringNotContainsString('New tag', json_encode($audit->context));
        }
    }

    public function test_no_op_review_and_fresh_retry_keep_exact_rows_timestamps_and_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $review = $this->review($tracks, $actor);
        app(BulkAddTrackTags::class)->apply($review, $actor);
        $before = $this->evidence();
        $this->travel(5)->minutes();
        $result = app(BulkAddTrackTags::class)->apply($this->review($tracks, $actor), $actor);
        $this->assertSame([], $result['changed_ids']);
        $this->assertSame(array_column($tracks, 'id'), $result['unchanged_ids']);
        $this->assertSame($before, $this->evidence());
        try {
            app(BulkAddTrackTags::class)->apply($review, $actor);
            $this->fail('Old successful review was reused.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_stale_last_target_rejects_the_entire_batch_without_overwriting_the_winner(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $review = $this->review($tracks, $actor);
        app(SaveTrackMetadata::class)->handle($tracks[1], ['metadata_version' => 1, 'tags' => ['Winning edit']], $actor);
        $before = $this->evidence();
        try {
            app(BulkAddTrackTags::class)->apply($review, $actor);
            $this->fail('A stale target allowed a partial batch.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('changed after review', $exception->errors()['additions'][0]);
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_explicit_bounds_missing_ids_and_malformed_review_or_tags_fail_without_changes(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor, 26);
        $before = $this->evidence();
        foreach ([[], [$tracks[0]->id, $tracks[0]->id], ['1'], [0], [PHP_INT_MAX], array_column($tracks, 'id'), ['hidden' => $tracks[0]->id]] as $ids) {
            try {
                app(BulkAddTrackTags::class)->review($ids, ['Valid'], $actor);
                $this->fail('Invalid selection was accepted.');
            } catch (ValidationException) {
            }
        }
        foreach ([[], [''], ['x', 'x'], [42], ['key' => 'x'], [str_repeat('x', 81)], array_map(fn ($i) => 'Tag '.$i, range(1, 21))] as $tags) {
            try {
                $this->review([$tracks[0]], $actor, $tags);
                $this->fail('Invalid additions were accepted.');
            } catch (ValidationException) {
            }
        }
        $valid = $this->review([$tracks[0]], $actor);
        foreach ([[], $valid + ['status' => 'published'], array_replace($valid, ['actor_id' => (string) $actor->id]), array_replace($valid, ['tracks' => [['id' => $tracks[0]->id]]])] as $review) {
            try {
                app(BulkAddTrackTags::class)->apply($review, $actor);
                $this->fail('Malformed review was accepted.');
            } catch (ValidationException) {
            }
        }
        $forged = $valid;
        $forged['tracks'][0]['after_tags'] = ['Replacement'];
        try {
            app(BulkAddTrackTags::class)->apply($forged, $actor);
            $this->fail('Forged replacement was accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertCount(25, $this->review(array_slice($tracks, 0, 25), $actor)['tracks']);
    }

    public function test_one_tag_overflow_or_legacy_normalization_never_changes_other_rows_or_metadata(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        app(SaveTrackMetadata::class)->handle($tracks[1], ['metadata_version' => 1, 'tags' => array_map(fn ($i) => 'Full '.$i, range(1, 20))], $actor);
        $before = $this->evidence();
        try {
            $this->review($tracks, $actor);
            $this->fail('Overflow was accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
        DB::table('tracks')->where('id', $tracks[1]->id)->update(['tags' => '[]', 'title' => ' Legacy title ']);
        $review = $this->review($tracks, $actor);
        $before = $this->evidence();
        try {
            app(BulkAddTrackTags::class)->apply($review, $actor);
            $this->fail('A tag command normalized a title.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_second_audit_failure_rolls_back_both_rows_and_the_first_audit(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $review = $this->review($tracks, $actor);
        $before = $this->evidence();
        $calls = 0;
        AuditEvent::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('Synthetic second audit failure');
            }
        });
        try {
            app(BulkAddTrackTags::class)->apply($review, $actor);
            $this->fail('Audit failure did not abort the batch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic second audit failure', $exception->getMessage());
        }
        $this->assertSame(2, $calls);
        $this->assertSame($before, $this->evidence());
    }

    public function test_command_rechecks_persisted_authority_mfa_and_review_actor(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $review = $this->review($tracks, $actor);
        $before = $this->evidence();
        $unverified = LicenseFixtures::admin();
        User::whereKey($unverified->id)->update(['email_verified_at' => null]);
        $revoked = LicenseFixtures::admin();
        User::whereKey($revoked->id)->update(['is_admin' => false]);
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        foreach ([User::factory()->create(), $unverified, $revoked, $deleted, LicenseFixtures::admin()] as $denied) {
            try {
                app(BulkAddTrackTags::class)->apply($review, $denied);
                $this->fail('Unauthorized actor applied the review.');
            } catch (AuthorizationException) {
            }
        }
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $actor->saveAppAuthenticationSecret('SYNTHETIC-MFA-SECRET');
        $this->review($tracks, $actor);
        User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
        try {
            app(BulkAddTrackTags::class)->apply($review, $actor);
            $this->fail('Removed MFA enrollment applied the review.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_published_readiness_is_authoritative_and_retained_commerce_media_and_delivery_evidence_is_unchanged(): void
    {
        DeliveryFixtures::configure();
        $this->travelTo(now()->startOfSecond());
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = DeliveryFixtures::activate(ActivationFixtures::issue(ContractFixtures::finalize(FinalizationFixtures::confirm(PaymentFixtures::started($gateway)))));
        $track = $fixture['track'];
        $actor = $fixture['actor'];
        $tables = ['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations', 'inventory_claims', 'promotion_uses',
            'offers', 'offer_revisions', 'license_versions', 'rights_declarations', 'media_assets', 'verified_payments', 'license_grants', 'contract_render_requests', 'contract_render_work', 'grant_contracts', 'test_fulfillment_activations', 'test_delivery_controls'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $before = $snapshot();
        $attrs = $track->fresh()->getAttributes();
        app(BulkAddTrackTags::class)->apply($this->review([$track], $actor), $actor);
        $this->assertSame($before, $snapshot());
        $track->refresh();
        $this->assertSame($attrs['slug'], $track->slug);
        $this->assertSame($attrs['published_slug'], $track->published_slug);
        $this->assertSame($attrs['status'], $track->status);
        $this->get('/tracks/'.$track->slug)->assertOk();
        $draft = $this->drafts($actor, 1)[0];
        $review = $this->review([$draft, $track], $actor, ['Blocked addition']);
        DB::table('rights_declarations')->where('track_id', $track->id)->update(['status' => 'draft']);
        $before = $this->evidence();
        try {
            app(BulkAddTrackTags::class)->apply($review, $actor);
            $this->fail('Published readiness was bypassed.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_filament_requires_explicit_review_and_renders_exact_affected_ids_before_apply(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']])
            ->assertSee('Review tag additions')->assertSee('2 tracks will change; 0 already contain these tags.');
        foreach ($tracks as $track) {
            $page->assertSee('Track ID: '.$track->id)->assertSee($track->title);
        }
        $this->assertSame($before, $this->evidence());
        $page->callMountedAction()->assertNotified('Tags added to 2 tracks. 0 tracks already contained these tags.');
        $this->assertNull($page->get('bulkTagReview'));
        $this->assertSame(['Original 1', 'UI tag'], $tracks[0]->fresh()->tags);
        $this->assertSame(['Original 2', 'UI tag'], $tracks[1]->fresh()->tags);
    }

    public function test_back_close_selection_change_and_client_tampering_cannot_reuse_a_review(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']]);
        $page->call('backToTagAdditions')->assertSet('bulkTagReview', null)->assertSee('Add tags to selected tracks');
        $page->callMountedAction()->assertSet('bulkTagReview.actor_id', $actor->id);
        $page->set('selectedTableRecords', [(string) $tracks[0]->id])->assertSet('bulkTagReview', null);
        $page->call('applyReviewedTagAdditions')->assertNotified('No changes were saved by this attempt.');
        $closed = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']]);
        $closed->unmountAction()->assertSet('bulkTagReview', null)->mountAction('reviewTagAdditions');
        $this->assertSame($before, $this->evidence());
        $changedInput = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']]);
        $changedInput->set('mountedActions.0.data.additions', ['Unreviewed input'])->assertSet('bulkTagReview', null);
        $this->assertSame([], $changedInput->get('mountedActions'));
        $tampered = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']]);
        try {
            $tampered->set('bulkTagReview.tracks.0.metadata_version', 999);
            $this->fail('Client changed a locked review.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_mounted_review_rechecks_role_and_mfa_and_stale_failure_requires_a_fresh_review(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $page = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']]);
        app(SaveTrackMetadata::class)->handle($tracks[1], ['metadata_version' => 1, 'tags' => ['Winning edit']], $actor);
        $before = $this->evidence();
        $page->callMountedAction()->assertNotified('No changes were saved by this attempt.')->assertSet('bulkTagReview', null)
            ->assertSee('Add tags to selected tracks');
        $this->assertSame($before, $this->evidence());
        $page->callMountedAction()->assertSee('Review tag additions');
        User::whereKey($actor->id)->update(['is_admin' => false]);
        $page->callMountedAction()->assertForbidden();
        $this->assertSame($before, $this->evidence());
        User::whereKey($actor->id)->update(['is_admin' => true]);
        $mfa = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']]);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $mfa->callMountedAction()->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_uncertain_save_result_never_claims_a_failed_save_or_offers_the_old_review_again(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $page = Livewire::test(ManageTracks::class)->callTableBulkAction('addTags', $tracks, data: ['additions' => ['UI tag']]);
        // Model a successful commit whose acknowledgement is lost. Do not suggest an old-review retry.
        $this->app->instance(BulkAddTrackTags::class, new class extends BulkAddTrackTags
        {
            public function apply(array $review, User $actor): array
            {
                parent::apply($review, $actor);
                throw new RuntimeException('Synthetic lost acknowledgement');
            }
        });
        $page->callMountedAction()->assertNotified('The save result could not be confirmed.')->assertSet('bulkTagReview', null)->assertSet('lastTagAdditions', []);
        $this->assertSame(['Original 1', 'UI tag'], $tracks[0]->fresh()->tags);
        $this->assertSame(['Original 2', 'UI tag'], $tracks[1]->fresh()->tags);
        $this->assertSame(2, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
    }
}
