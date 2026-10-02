<?php

namespace Tests\Feature;

use App\Domain\Catalog\BulkUpdateTrackMetadata;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\CreateQuote;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class BulkUpdateTrackMetadataTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = ['artist', 'bpm', 'musical_key', 'genre', 'mood'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function changes(array $overrides = []): array
    {
        return array_replace(array_fill_keys(self::FIELDS, ['mode' => 'keep']), $overrides);
    }

    private function drafts(User $actor, int $count = 2): array
    {
        return array_map(fn ($index) => app(SaveTrackMetadata::class)->handle(null, [
            'title' => 'Synthetic bulk metadata '.$index, 'slug' => 'synthetic-bulk-metadata-'.$index,
            'artist' => 'Original artist '.$index, 'bpm' => 90 + $index, 'musical_key' => 'D minor',
            'genre' => 'Original genre', 'mood' => 'Original mood', 'tags' => ['Last '.$index, 'First '.$index],
            'description' => 'Private synthetic note '.$index,
        ], $actor), range(1, $count));
    }

    private function review(array $tracks, User $actor, ?array $changes = null): array
    {
        return app(BulkUpdateTrackMetadata::class)->review(array_map(fn ($track) => $track->id, $tracks),
            $changes ?? $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']]), $actor);
    }

    private function evidence(): array
    {
        return [DB::table('tracks')->orderBy('id')->get()->toJson(), DB::table('audit_events')->orderBy('id')->get()->toJson()];
    }

    private function rejects(callable $operation, ?string $field = null): void
    {
        try {
            $operation();
            $this->fail('Invalid bulk metadata operation was accepted.');
        } catch (ValidationException $error) {
            if ($field !== null) {
                $this->assertNotEmpty(array_filter(array_keys($error->errors()), fn ($key) => $key === $field || str_starts_with($key, $field.'.')));
            }
        }
    }

    public function test_review_is_read_only_exact_sorted_and_normalized_before_atomic_apply_records_only_minimized_per_track_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $before = $this->evidence();
        $choices = $this->changes(['artist' => ['mode' => 'set', 'value' => '  Revised artist  '],
            'bpm' => ['mode' => 'set', 'value' => '100'], 'musical_key' => ['mode' => 'clear'],
            'genre' => ['mode' => 'keep'], 'mood' => ['mode' => 'set', 'value' => '  Reviewed mood  ']]);
        $review = $this->review(array_reverse($tracks), $actor, $choices);
        $this->assertSame($before, $this->evidence());
        $this->assertEqualsCanonicalizing(['schema_version', 'actor_id', 'changes', 'tracks'], array_keys($review));
        $this->assertSame(1, $review['schema_version']);
        $this->assertSame($actor->id, $review['actor_id']);
        $this->assertSame(array_column($tracks, 'id'), array_column($review['tracks'], 'id'));
        $this->assertSame(['mode' => 'set', 'value' => 'Revised artist'], $review['changes']['artist']);
        $this->assertSame(['mode' => 'set', 'value' => 100], $review['changes']['bpm']);
        foreach ($review['tracks'] as $index => $row) {
            $this->assertEqualsCanonicalizing(['id', 'metadata_version', 'title', 'before_metadata', 'after_metadata', 'row_hash'], array_keys($row));
            $this->assertSame(1, $row['metadata_version']);
            $this->assertSame($tracks[$index]->title, $row['title']);
            $this->assertSame(CanonicalJson::hash($tracks[$index]->fresh()->getAttributes()), $row['row_hash']);
            $this->assertSame('Original artist '.($index + 1), $row['before_metadata']['artist']);
            $this->assertSame('Revised artist', $row['after_metadata']['artist']);
            $this->assertSame(100, $row['after_metadata']['bpm']);
            $this->assertNull($row['after_metadata']['musical_key']);
            $this->assertSame('Original genre', $row['after_metadata']['genre']);
            $this->assertSame('Reviewed mood', $row['after_metadata']['mood']);
            $this->assertEqualsCanonicalizing(self::FIELDS, array_keys($row['before_metadata']));
            $this->assertEqualsCanonicalizing(self::FIELDS, array_keys($row['after_metadata']));
        }
        $retained = array_map(fn ($track) => $track->fresh()->getAttributes(), $tracks);
        $result = app(BulkUpdateTrackMetadata::class)->apply($review, $actor);
        $this->assertSame(['changed_ids' => array_column($tracks, 'id'), 'unchanged_ids' => []], $result);
        foreach ($tracks as $index => $track) {
            $after = $track->fresh()->getAttributes();
            foreach (['artist', 'bpm', 'musical_key', 'mood', 'metadata_version', 'updated_at'] as $field) {
                unset($retained[$index][$field], $after[$field]);
            }
            $this->assertSame($retained[$index], $after, 'Unchosen metadata, tags, note, identity and publication state must stay exact.');
            $audit = AuditEvent::where('action', 'catalog.track.metadata_updated')->where('subject_id', $track->id)->sole();
            $this->assertSame($actor->id, $audit->actor_id);
            $this->assertSame(['artist', 'bpm', 'musical_key', 'mood'], $audit->context['changed_fields']);
            $this->assertSame(2, $audit->context['metadata_version']);
            $this->assertStringNotContainsString('Reviewed mood', json_encode($audit->context));
            $this->assertStringNotContainsString('Private synthetic', json_encode($audit->context));
        }
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
    }

    public function test_fresh_no_op_and_mixed_changed_unchanged_reviews_preserve_exact_rows_timestamps_and_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $command = app(BulkUpdateTrackMetadata::class);
        $keepCurrent = $this->changes(['genre' => ['mode' => 'set', 'value' => 'Original genre']]);
        $before = $this->evidence();
        $this->travel(5)->minutes();
        $result = $command->apply($this->review($tracks, $actor, $keepCurrent), $actor);
        $this->assertSame(['changed_ids' => [], 'unchanged_ids' => array_column($tracks, 'id')], $result);
        $this->assertSame($before, $this->evidence());
        app(SaveTrackMetadata::class)->handle($tracks[0], ['metadata_version' => 1, 'mood' => 'Reviewed mood'], $actor);
        $untouched = $tracks[0]->fresh()->getAttributes();
        $review = $this->review($tracks, $actor);
        $result = $command->apply($review, $actor);
        $this->assertSame(['changed_ids' => [$tracks[1]->id], 'unchanged_ids' => [$tracks[0]->id]], $result);
        $this->assertSame($untouched, $tracks[0]->fresh()->getAttributes());
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($review, $actor), 'changes');
        $this->assertSame($before, $this->evidence());
        $this->travel(5)->minutes();
        $command->apply($this->review($tracks, $actor), $actor);
        $this->assertSame($before, $this->evidence());
    }

    public function test_exact_selection_bounds_and_unknown_or_malformed_change_instructions_fail_without_writes(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor, 26);
        $command = app(BulkUpdateTrackMetadata::class);
        $valid = $this->changes(['mood' => ['mode' => 'set', 'value' => 'Valid']]);
        $before = $this->evidence();
        foreach ([[], [$tracks[0]->id, $tracks[0]->id], ['1'], [0], [-1], [PHP_INT_MAX], ['hidden' => $tracks[0]->id], array_column($tracks, 'id')] as $ids) {
            $this->rejects(fn () => $command->review($ids, $valid, $actor), 'changes');
        }
        foreach ([[], $this->changes(), array_diff_key($valid, ['artist' => true]), $valid + ['title' => ['mode' => 'set', 'value' => 'Forbidden']],
            $this->changes(['artist' => ['mode' => 'clear']]), $this->changes(['artist' => ['mode' => 'keep', 'value' => 'Injected']]),
            $this->changes(['mood' => ['mode' => 'clear', 'value' => 'Injected']]), $this->changes(['mood' => ['mode' => 'set']]),
            $this->changes(['mood' => ['mode' => 'replace', 'value' => 'Injected']]), $this->changes(['mood' => ['mode' => 'set', 'value' => 'Valid', 'status' => 'published']]),
            $this->changes(['mood' => 'Invalid scalar'])] as $choices) {
            $this->rejects(fn () => $this->review([$tracks[0]], $actor, $choices), 'changes');
        }
        foreach (['title', 'slug', 'description', 'tags', 'status', 'published_at', 'published_slug', 'duration_seconds', 'waveform',
            'price_minor', 'currency', 'offer_id', 'license_version_id', 'deliverable_asset_ids', 'actor_id', 'metadata_version'] as $field) {
            $this->rejects(fn () => $this->review([$tracks[0]], $actor, $valid + [$field => ['mode' => 'set', 'value' => 'Injected']]), 'changes');
        }
        $this->assertSame($before, $this->evidence());
        $this->assertCount(25, $this->review(array_slice($tracks, 0, 25), $actor)['tracks']);
        $this->assertCount(1, $this->review([$tracks[0]], $actor)['tracks']);
    }

    public function test_set_and_clear_follow_ordinary_bounds_and_preserve_literal_unchosen_metadata(): void
    {
        $actor = LicenseFixtures::admin();
        $track = $this->drafts($actor, 1)[0];
        $before = $this->evidence();
        foreach ([['artist', null], ['artist', ''], ['artist', str_repeat('x', 256)], ['bpm', 19], ['bpm', 401], ['bpm', 90.5],
            ['bpm', null], ['musical_key', str_repeat('x', 25)], ['genre', str_repeat('x', 256)], ['mood', str_repeat('x', 256)],
            ['mood', '   '], ['mood', ['nested']], ['mood', false]] as [$field, $value]) {
            $this->rejects(fn () => $this->review([$track], $actor, $this->changes([$field => ['mode' => 'set', 'value' => $value]])), 'changes.'.$field);
        }
        $this->assertSame($before, $this->evidence());
        $command = app(BulkUpdateTrackMetadata::class);
        $command->apply($this->review([$track], $actor, $this->changes(['artist' => ['mode' => 'set', 'value' => str_repeat('a', 255)],
            'bpm' => ['mode' => 'set', 'value' => 20], 'musical_key' => ['mode' => 'set', 'value' => str_repeat('k', 24)],
            'genre' => ['mode' => 'set', 'value' => str_repeat('g', 255)], 'mood' => ['mode' => 'set', 'value' => str_repeat('m', 255)]])), $actor);
        $this->assertSame(20, $track->fresh()->bpm);
        $command->apply($this->review([$track], $actor, $this->changes(['bpm' => ['mode' => 'set', 'value' => 400]])), $actor);
        $this->assertSame(400, $track->fresh()->bpm);
        $command->apply($this->review([$track], $actor, $this->changes(['bpm' => ['mode' => 'clear'], 'musical_key' => ['mode' => 'clear'],
            'genre' => ['mode' => 'clear'], 'mood' => ['mode' => 'clear']])), $actor);
        $track->refresh();
        foreach (['bpm', 'musical_key', 'genre', 'mood'] as $field) {
            $this->assertNull($track->{$field});
        }
        $this->assertSame(str_repeat('a', 255), $track->artist);
        $this->assertSame(['Last 1', 'First 1'], $track->tags);
        $this->assertSame('Private synthetic note 1', $track->description);
        $this->assertSame('draft', $track->status);
    }

    public function test_malformed_or_forged_review_cannot_bypass_exact_affected_rows_choices_before_after_or_protected_hash(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $command = app(BulkUpdateTrackMetadata::class);
        $review = $this->review($tracks, $actor);
        $before = $this->evidence();
        $invalid = [[], $review + ['status' => 'published'], array_replace($review, ['schema_version' => '1']),
            array_replace($review, ['actor_id' => (string) $actor->id]), array_replace($review, ['tracks' => []])];
        foreach (['id' => (string) $tracks[0]->id, 'metadata_version' => -1, 'row_hash' => str_repeat('x', 64),
            'title' => 'Forged reviewed title', 'status' => 'published'] as $key => $value) {
            $forged = $review;
            $forged['tracks'][0][$key] = $value;
            $invalid[] = $forged;
        }
        $forged = $review;
        $forged['tracks'][1]['id'] = $tracks[0]->id;
        $invalid[] = $forged;
        $forged = $review;
        $forged['tracks'][0]['before_metadata']['genre'] = 'Forged before';
        $invalid[] = $forged;
        $forged = $review;
        $forged['tracks'][0]['after_metadata']['artist'] = 'Unchosen replacement';
        $invalid[] = $forged;
        $forged = $review;
        $forged['changes']['mood']['value'] = 'Unreviewed choice';
        $invalid[] = $forged;
        $forged = $review;
        $forged['changes']['mood']['value'] = '  Reviewed mood  ';
        $invalid[] = $forged;
        foreach ($invalid as $forged) {
            $this->rejects(fn () => $command->apply($forged, $actor), 'changes');
        }
        $this->assertSame($before, $this->evidence());
        try {
            $command->apply($review, LicenseFixtures::admin());
            $this->fail('Another authorized operator consumed the original actor review.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_stale_final_target_deleted_target_and_protected_field_drift_abort_all_selected_changes(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor, 3);
        $command = app(BulkUpdateTrackMetadata::class);
        $review = $this->review($tracks, $actor);
        app(SaveTrackMetadata::class)->handle($tracks[2], ['metadata_version' => 1, 'mood' => 'Winning edit'], $actor);
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($review, $actor), 'changes');
        $this->assertSame($before, $this->evidence());
        $review = $this->review($tracks, $actor);
        DB::table('tracks')->where('id', $tracks[2]->id)->update(['description' => 'Changed protected note without revision']);
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($review, $actor), 'changes');
        $this->assertSame($before, $this->evidence());
        $review = $this->review($tracks, $actor);
        $tracks[2]->delete();
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($review, $actor), 'changes');
        $this->assertSame($before, $this->evidence());
        $this->assertSame(1, $tracks[0]->fresh()->metadata_version);
        $this->assertSame(1, $tracks[1]->fresh()->metadata_version);
    }

    public function test_legacy_normalization_and_revision_overflow_never_modify_unselected_fields_or_partially_apply(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $command = app(BulkUpdateTrackMetadata::class);
        DB::table('tracks')->where('id', $tracks[1]->id)->update(['title' => ' Legacy unnormalized title ']);
        $review = $this->review($tracks, $actor);
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($review, $actor), 'changes');
        $this->assertSame($before, $this->evidence());
        DB::table('tracks')->where('id', $tracks[1]->id)->update(['title' => 'Synthetic bulk metadata 2', 'metadata_version' => 2147483647]);
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($this->review($tracks, $actor), $actor), 'changes');
        $this->assertSame($before, $this->evidence());
        $result = $command->apply($this->review([$tracks[1]], $actor, $this->changes(['genre' => ['mode' => 'set', 'value' => 'Original genre']])), $actor);
        $this->assertSame(['changed_ids' => [], 'unchanged_ids' => [$tracks[1]->id]], $result);
        $this->assertSame($before, $this->evidence());
    }

    public function test_second_audit_failure_rolls_back_every_selected_row_and_the_first_audit(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $review = $this->review($tracks, $actor);
        $before = $this->evidence();
        $calls = 0;
        AuditEvent::creating(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('Synthetic second metadata audit failure');
            }
        });
        try {
            app(BulkUpdateTrackMetadata::class)->apply($review, $actor);
            $this->fail('Audit failure did not abort the bulk metadata transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic second metadata audit failure', $error->getMessage());
        }
        $this->assertSame(2, $calls);
        $this->assertSame($before, $this->evidence());
    }

    public function test_json_object_key_order_is_irrelevant_while_the_reviewed_track_list_order_is_exact(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $command = app(BulkUpdateTrackMetadata::class);
        $review = $this->review($tracks, $actor);
        $outOfOrder = $review;
        $outOfOrder['tracks'] = array_reverse($outOfOrder['tracks']);
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($outOfOrder, $actor), 'changes');
        $this->assertSame($before, $this->evidence());
        $review = array_reverse($review, preserve_keys: true);
        $review['changes'] = array_reverse($review['changes'], preserve_keys: true);
        foreach ($review['changes'] as &$choice) {
            $choice = array_reverse($choice, preserve_keys: true);
        }
        unset($choice);
        foreach ($review['tracks'] as &$row) {
            $row = array_reverse($row, preserve_keys: true);
            $row['before_metadata'] = array_reverse($row['before_metadata'], preserve_keys: true);
            $row['after_metadata'] = array_reverse($row['after_metadata'], preserve_keys: true);
        }
        unset($row);
        $this->assertSame(['changed_ids' => array_column($tracks, 'id'), 'unchanged_ids' => []], $command->apply($review, $actor));
    }

    public static function authorityWithdrawals(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null]];
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_review_and_apply_recheck_persisted_authority_after_review(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $review = $this->review($tracks, $actor);
        User::whereKey($actor->id)->update([$field => $value]);
        $before = $this->evidence();
        foreach ([fn () => $this->review($tracks, $actor), fn () => app(BulkUpdateTrackMetadata::class)->apply($review, $actor)] as $operation) {
            try {
                $operation();
                $this->fail('Revoked actor retained bulk metadata authority.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_review_and_apply_recheck_current_mfa_and_reject_deleted_or_locally_elevated_customers(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $review = $this->review($tracks, $actor);
        $before = $this->evidence();
        $customer = User::factory()->create();
        $customer->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        foreach ([$customer, $deleted] as $denied) {
            foreach ([fn () => $this->review($tracks, $denied), fn () => app(BulkUpdateTrackMetadata::class)->apply($review, $denied)] as $operation) {
                try {
                    $operation();
                    $this->fail('Unpersisted authority allowed a bulk metadata command.');
                } catch (AuthorizationException) {
                }
            }
        }
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $review = $this->review($tracks, $actor);
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            foreach ([fn () => $this->review($tracks, $actor), fn () => app(BulkUpdateTrackMetadata::class)->apply($review, $actor)] as $operation) {
                try {
                    $operation();
                    $this->fail('Removed MFA enrollment retained bulk metadata authority.');
                } catch (AuthorizationException) {
                }
            }
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_published_readiness_is_rechecked_and_success_preserves_public_urls_media_rights_and_retained_commerce(): void
    {
        // Only this case needs genuine synthetic media/publication; the other cases stay metadata-only.
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        // This fixture predates the metadata command and stores SQL NULL tags. Review that
        // legacy row through the ordinary editor before bulk changes; its canonical tags
        // then become part of the exact protected evidence below.
        $track = app(SaveTrackMetadata::class)->handle($track, ['metadata_version' => $track->metadata_version, 'mood' => 'Original mood'], $actor);
        app(CreateQuote::class)->handle(str_repeat('a', 64), 'synthetic-bulk-metadata-retained-quote', $fixture['items']);
        $command = app(BulkUpdateTrackMetadata::class);
        $tables = ['media_assets', 'media_processing_runs', 'rights_declarations', 'license_templates', 'license_versions',
            'offers', 'offer_revisions', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations', 'inventory_claims',
            'orders', 'order_lines', 'order_attempts', 'verified_payments', 'license_grants', 'grant_contracts'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $before = $snapshot();
        $original = $track->fresh()->getAttributes();
        $command->apply($this->review([$track], $actor), $actor);
        $this->assertSame($before, $snapshot());
        $track->refresh();
        foreach (['slug', 'published_slug', 'status', 'published_at', 'title', 'tags', 'description'] as $field) {
            $this->assertSame($original[$field], $track->getAttributes()[$field]);
        }
        $this->get('/tracks/'.$track->slug)->assertOk();
        $draft = $this->drafts($actor, 1)[0];
        $review = $this->review([$draft, $track], $actor, $this->changes(['genre' => ['mode' => 'clear']]));
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($review, $actor));
        $this->assertSame($before, $this->evidence());
        $review = $this->review([$draft, $track], $actor, $this->changes(['mood' => ['mode' => 'set', 'value' => 'Blocked addition']]));
        $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-PENDING-REVIEW',
            'sample_disclosure' => 'New synthetic declaration awaiting verification', 'status' => 'pending']);
        $before = $this->evidence();
        $this->rejects(fn () => $command->apply($review, $actor));
        $this->assertSame($before, $this->evidence());
    }

    public function test_real_filament_selection_transport_review_back_and_explicit_apply_show_exact_before_and_after_without_early_writes(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $choices = $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']]);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($page->html());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $buttons = (new DOMXPath($document))->query('//button[normalize-space(.)="Edit metadata"]');
        $this->assertCount(1, $buttons);
        $button = $buttons->item(0);
        $this->assertStringContainsString("mountAction('editMetadata'", $button->getAttribute('x-on:click'));
        $this->assertFalse($button->hasAttribute('wire:click'));
        $this->assertFalse($page->instance()->getTable()->getBulkAction('editMetadata')->shouldFetchSelectedRecords());
        $ids = array_map(fn ($track) => (string) $track->id, $tracks);
        $page->set('selectedTableRecords', $ids)->call('mountAction', 'editMetadata', [], ['table' => true, 'bulk' => true])
            ->assertSet('mountedActions.0.name', 'editMetadata')
            ->setActionData(['changes' => $choices])->call('callMountedAction')->assertHasNoErrors()
            ->assertSet('mountedActions.0.name', 'reviewMetadataChanges')->assertSee('Review metadata changes')
            ->assertSee('2 tracks will change; 0 already match these choices.')->assertSee('Current metadata')->assertSee('Proposed metadata')
            ->assertSee('Original mood')->assertSee('Reviewed mood')->assertSee('Keep')->assertSee('Set');
        foreach ($tracks as $track) {
            $page->assertSee($track->title)->assertSee('Track ID: '.$track->id);
        }
        $this->assertSame(array_column($tracks, 'id'), array_column($page->get('bulkMetadataReview')['tracks'], 'id'));
        $this->assertSame($before, $this->evidence());
        $page->call('mountAction', 'backToMetadataChanges')->assertSet('bulkMetadataReview', null)->assertSet('bulkMetadataTableContext', null)
            ->assertSet('mountedActions.0.name', 'editMetadata');
        $this->assertSame($before, $this->evidence());
        $page->setActionData(['changes' => $this->changes(['mood' => ['mode' => 'set', 'value' => 'Revised review']])])
            ->call('callMountedAction')->assertHasNoErrors()->assertSee('Revised review');
        $this->assertSame($before, $this->evidence());
        $page->call('callMountedAction')->assertHasNoErrors()->assertNotified('Metadata saved for 2 tracks. 0 tracks already matched these choices.')
            ->assertSet('bulkMetadataReview', null)->assertSet('bulkMetadataTableContext', null)->assertSet('lastMetadataChoices', [])
            ->assertSet('mountedActions', [])->assertDispatched('deselectAllTableRecords');
        // Filament clears Alpine's selection in response to this event. Reproduce its next
        // selection transport; Testable has no browser listener to dispatch that state update.
        $page->set('selectedTableRecords', [])->assertSet('selectedTableRecords', []);
        $this->assertSame('Revised review', $tracks[0]->fresh()->mood);
        $this->assertSame('Revised review', $tracks[1]->fresh()->mood);
        $this->assertSame(2, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
        $this->assertNull($page->get('bulkTagReview'));
        $this->assertNull($page->get('presetMetadataSnapshot'));
    }

    public function test_filament_defaults_to_keep_maps_visible_errors_and_acknowledges_true_no_op_without_writes(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->set('selectedTableRecords', array_map(fn ($track) => (string) $track->id, $tracks))
            ->call('mountAction', 'editMetadata', [], ['table' => true, 'bulk' => true]);
        foreach (self::FIELDS as $field) {
            $page->assertSet('mountedActions.0.data.changes.'.$field.'.mode', 'keep');
        }
        $page->call('callMountedAction')->assertHasActionErrors(['changes.artist.mode']);
        $this->assertSame($before, $this->evidence());
        $page->setActionData(['changes' => $this->changes(['bpm' => ['mode' => 'set', 'value' => 401]])])
            ->call('callMountedAction')->assertHasActionErrors(['changes.bpm.value']);
        $this->assertSame($before, $this->evidence());
        $page->setActionData(['changes' => $this->changes(['genre' => ['mode' => 'set', 'value' => 'Original genre']])])
            ->call('callMountedAction')->assertHasNoErrors()->assertSee('0 tracks will change; 2 already match these choices.');
        $page->call('callMountedAction')->assertNotified('No metadata changed. All 2 reviewed tracks already matched these choices.');
        $this->assertSame($before, $this->evidence());
    }

    public static function reviewInvalidations(): array
    {
        return ['selection' => ['selectedTableRecords', ['1']], 'deselection mode' => ['isTrackingDeselectedTableRecords', true],
            'search' => ['tableSearch', 'Synthetic'], 'column search' => ['tableColumnSearches', ['title' => 'Synthetic']],
            'filters' => ['tableFilters', ['synthetic' => ['value' => 'draft']]], 'deferred filters' => ['tableDeferredFilters', ['synthetic' => ['value' => 'draft']]],
            'sort' => ['tableSort', 'title:desc'], 'page size' => ['tableRecordsPerPage', 5],
            'unreviewed input' => ['mountedActions.0.data.changes.mood.value', 'Unreviewed'],
            'client page' => ['paginators.page', 2]];
    }

    #[DataProvider('reviewInvalidations')]
    public function test_selection_input_or_table_scope_change_consumes_review_and_never_reuses_it(string $property, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->callTableBulkAction('editMetadata', $tracks,
            data: ['changes' => $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']])]);
        $this->assertNotNull($page->get('bulkMetadataReview'));
        $page->set($property, $value)->assertSet('bulkMetadataReview', null)->assertSet('bulkMetadataTableContext', null)
            ->call('applyReviewedMetadataChanges')->assertNotified('No changes were saved by this attempt.');
        $this->assertSame($before, $this->evidence());
    }

    public function test_server_page_change_cancel_new_action_and_client_review_tampering_cannot_reuse_a_confirmation(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor, 11);
        $this->actingAs($actor);
        $before = $this->evidence();
        $review = fn () => Livewire::test(ManageTracks::class)->callTableBulkAction('editMetadata', array_slice($tracks, 0, 2),
            data: ['changes' => $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']])]);
        $page = $review();
        $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName())
            ->assertSet('bulkMetadataReview', null)->assertSet('bulkMetadataTableContext', null);
        $review()->unmountAction()->assertSet('bulkMetadataReview', null)->assertSet('bulkMetadataTableContext', null)
            ->mountAction('reviewMetadataChanges')->callMountedAction();
        $review()->mountAction('create')->assertSet('bulkMetadataReview', null)->assertSet('bulkMetadataTableContext', null);
        $tampered = $review();
        try {
            $tampered->set('bulkMetadataReview.tracks.0.row_hash', str_repeat('a', 64));
            $this->fail('Client changed the locked reviewed metadata.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_filament_rejects_forged_selection_outside_the_current_filtered_page_or_unsupported_bounds(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor, 26);
        $this->actingAs($actor);
        $before = $this->evidence();
        $choices = ['changes' => $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']])];
        foreach ([[], ['0'], ['01'], [(string) $tracks[0]->id, (string) $tracks[0]->id], array_map(fn ($track) => (string) $track->id, $tracks),
            [(string) $tracks[25]->id]] as $ids) {
            Livewire::test(ManageTracks::class)->set('tableRecordsPerPage', 25)->set('selectedTableRecords', $ids)
                ->call('mountAction', 'editMetadata', [], ['table' => true, 'bulk' => true])->setActionData($choices)
                ->call('callMountedAction')->assertHasActionErrors(['changes.artist.mode'])->assertSet('bulkMetadataReview', null);
        }
        Livewire::test(ManageTracks::class)->set('tableSearch', 'Synthetic bulk metadata 1')->set('selectedTableRecords', [(string) $tracks[1]->id])
            ->call('mountAction', 'editMetadata', [], ['table' => true, 'bulk' => true])->setActionData($choices)
            ->call('callMountedAction')->assertHasActionErrors(['changes.artist.mode'])->assertSet('bulkMetadataReview', null);
        Livewire::test(ManageTracks::class)->set('tableRecordsPerPage', 100)->set('selectedTableRecords', [(string) $tracks[0]->id])
            ->call('mountAction', 'editMetadata', [], ['table' => true, 'bulk' => true])->setActionData($choices)
            ->call('callMountedAction')->assertHasActionErrors(['changes.artist.mode'])->assertSet('bulkMetadataReview', null);
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_mounted_choices_and_review_reauthorize_after_persisted_role_or_verification_withdrawal(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $choices = ['changes' => $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']])];
        $unreviewed = Livewire::test(ManageTracks::class)->set('selectedTableRecords', array_map(fn ($track) => (string) $track->id, $tracks))
            ->call('mountAction', 'editMetadata', [], ['table' => true, 'bulk' => true])->setActionData($choices);
        $reviewed = Livewire::test(ManageTracks::class)->callTableBulkAction('editMetadata', $tracks, data: $choices);
        User::whereKey($actor->id)->update([$field => $value]);
        $before = $this->evidence();
        $unreviewed->call('callMountedAction')->assertForbidden();
        $reviewed->call('callMountedAction')->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_mounted_choices_and_review_reauthorize_current_mfa_enrollment(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $choices = ['changes' => $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']])];
            $unreviewed = Livewire::test(ManageTracks::class)->set('selectedTableRecords', array_map(fn ($track) => (string) $track->id, $tracks))
                ->call('mountAction', 'editMetadata', [], ['table' => true, 'bulk' => true])->setActionData($choices);
            $reviewed = Livewire::test(ManageTracks::class)->callTableBulkAction('editMetadata', $tracks, data: $choices);
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            $before = $this->evidence();
            $unreviewed->call('callMountedAction')->assertForbidden();
            $reviewed->call('callMountedAction')->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_stale_ui_save_requires_fresh_review_and_uncertain_acknowledgement_never_reoffers_the_consumed_review(): void
    {
        $actor = LicenseFixtures::admin();
        $tracks = $this->drafts($actor);
        $this->actingAs($actor);
        $choices = ['changes' => $this->changes(['mood' => ['mode' => 'set', 'value' => 'Reviewed mood']])];
        $page = Livewire::test(ManageTracks::class)->callTableBulkAction('editMetadata', $tracks, data: $choices);
        app(SaveTrackMetadata::class)->handle($tracks[1], ['metadata_version' => 1, 'mood' => 'Winning edit'], $actor);
        $before = $this->evidence();
        $page->call('callMountedAction')->assertNotified('No changes were saved by this attempt.')->assertSet('bulkMetadataReview', null)
            ->assertSet('mountedActions.0.name', 'editMetadata');
        $this->assertSame($before, $this->evidence());
        $page->call('callMountedAction')->assertSee('Review metadata changes');
        $this->app->instance(BulkUpdateTrackMetadata::class, new class extends BulkUpdateTrackMetadata
        {
            public function apply(array $review, User $actor): array
            {
                parent::apply($review, $actor);
                throw new RuntimeException('Synthetic lost metadata acknowledgement');
            }
        });
        $page->call('callMountedAction')->assertNotified('The save result could not be confirmed.')
            ->assertSet('bulkMetadataReview', null)->assertSet('bulkMetadataTableContext', null)->assertSet('lastMetadataChoices', []);
        $this->assertSame('Reviewed mood', $tracks[0]->fresh()->mood);
        $this->assertSame('Reviewed mood', $tracks[1]->fresh()->mood);
        $this->assertSame(3, AuditEvent::where('action', 'catalog.track.metadata_updated')->count());
    }
}
