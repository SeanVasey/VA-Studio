<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\Models\TrackMetadataPreset;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Catalog\TrackMetadataPresets;
use App\Domain\Commerce\CreateQuote;
use App\Filament\Resources\TrackMetadataPresetResource\Pages\ManageTrackMetadataPresets;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
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

class TrackMetadataPresetsTest extends TestCase
{
    use RefreshDatabase;

    private const CREATED = 'catalog.track_metadata_preset.created';

    private const UPDATED = 'catalog.track_metadata_preset.updated';

    private const ARCHIVED = 'catalog.track_metadata_preset.archived';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Synthetic instrumental defaults', 'artist' => 'Synthetic artist', 'bpm' => 92,
            'musical_key' => 'D minor', 'genre' => 'Synthetic genre', 'mood' => 'Reflective',
            'tags' => ['Strings', 'Low brass', 'strings'], 'description' => 'Private synthetic working note',
        ], $overrides);
    }

    private function preset(User $actor, array $overrides = []): TrackMetadataPreset
    {
        return app(TrackMetadataPresets::class)->handle(null, $this->payload($overrides), $actor);
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['track_metadata_presets', 'tracks', 'audit_events']);
    }

    private function rejects(callable $operation, ?string $field = null): void
    {
        try {
            $operation();
            $this->fail('Invalid metadata-preset operation was accepted.');
        } catch (ValidationException $exception) {
            if ($field !== null) {
                $this->assertNotEmpty(array_filter(array_keys($exception->errors()), fn ($key) => $key === $field || str_starts_with($key, $field.'.')));
            }
        }
    }

    public function test_persisted_create_edit_and_archive_have_minimized_actor_and_revision_evidence(): void
    {
        $author = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($author)->fresh();
        $snapshot = $command->snapshot($preset->id, $author, 1);
        $this->assertSame(['id', 'version', 'name', 'metadata'], array_keys($snapshot));
        $this->assertSame($this->payload()['name'], $snapshot['name']);
        $this->assertSame(1, $snapshot['version']);
        $expectedMetadata = array_diff_key($this->payload(), ['name' => true]);
        $actualMetadata = $snapshot['metadata'];
        ksort($expectedMetadata, SORT_STRING);
        ksort($actualMetadata, SORT_STRING);
        $this->assertSame($expectedMetadata, $actualMetadata);
        $created = AuditEvent::where('action', self::CREATED)->sole();
        $this->assertSame($author->id, $created->actor_id);
        $this->assertSame($preset->id, $created->subject_id);
        $this->assertSame(TrackMetadataPreset::class, $created->subject_type);
        $this->assertNull($created->context['before_hash']);
        $this->assertSame(1, $created->context['version']);
        $this->assertSame(CanonicalJson::VERSION, $created->context['canonicalization_version']);
        $this->assertSame(CanonicalJson::hash(['name' => $snapshot['name'], 'metadata' => $snapshot['metadata'], 'archived' => false]), $created->context['after_hash']);

        $preset = $command->handle($preset, ['version' => 1, 'mood' => 'Restless', 'tags' => ['Third', 'First', 'third']], $editor);
        $this->assertSame(2, $preset->version);
        $this->assertSame(['Third', 'First', 'third'], $command->snapshot($preset->id, $author, 2)['metadata']['tags']);
        $updated = AuditEvent::where('action', self::UPDATED)->sole();
        $this->assertSame($editor->id, $updated->actor_id);
        $this->assertSame(['mood', 'tags'], $updated->context['changed_fields']);
        $this->assertSame($created->context['after_hash'], $updated->context['before_hash']);
        $this->assertNotSame($updated->context['before_hash'], $updated->context['after_hash']);

        $preset = $command->archive($preset, 2, $editor);
        $this->assertSame(3, $preset->version);
        $this->assertNotNull($preset->archived_at);
        $this->assertSame([], $command->active($author));
        $archived = AuditEvent::where('action', self::ARCHIVED)->sole();
        $this->assertSame($editor->id, $archived->actor_id);
        $this->assertSame(['archived_at'], $archived->context['changed_fields']);
        $this->assertSame($updated->context['after_hash'], $archived->context['before_hash']);
        $this->rejects(fn () => $command->snapshot($preset->id, $author), 'name');
        $this->rejects(fn () => $command->handle($preset, ['version' => 3, 'mood' => 'Forbidden resurrection'], $editor), 'name');
        foreach (AuditEvent::whereIn('action', [self::CREATED, self::UPDATED, self::ARCHIVED])->get() as $audit) {
            $this->assertStringNotContainsString('Private synthetic', json_encode($audit->context));
            $this->assertStringNotContainsString('Synthetic artist', json_encode($audit->context));
            $this->assertStringNotContainsString('Low brass', json_encode($audit->context));
        }
        $this->assertDatabaseCount('tracks', 0);
    }

    public function test_omitted_metadata_defaults_are_explicit_and_active_projection_is_sorted_and_read_only(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $last = $command->handle(null, ['name' => 'Zulu'], $actor);
        $first = $command->handle(null, ['name' => 'Alpha'], $actor);
        $before = $this->evidence();
        $this->assertSame([
            ['id' => $first->id, 'version' => 1, 'name' => 'Alpha'],
            ['id' => $last->id, 'version' => 1, 'name' => 'Zulu'],
        ], $command->active($actor));
        $this->assertSame([
            'artist' => 'VASEY.AUDIO', 'bpm' => null, 'musical_key' => null, 'genre' => null,
            'mood' => null, 'tags' => [], 'description' => null,
        ], $command->snapshot($first->id, $actor)['metadata']);
        $this->assertSame($before, $this->evidence());
    }

    public function test_no_op_saves_and_repeated_archive_preserve_exact_rows_timestamps_versions_and_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $before = $this->evidence();
        $this->travel(5)->minutes();
        $command->handle($preset, ['version' => 1] + $this->payload(), $actor);
        $this->assertSame($before, $this->evidence());
        $preset = $command->archive($preset, 1, $actor);
        $before = $this->evidence();
        $this->travel(5)->minutes();
        $command->archive($preset, 2, $actor);
        $this->assertSame($before, $this->evidence());
        $this->rejects(fn () => $command->archive($preset, 1, $actor), 'name');
        $this->assertSame($before, $this->evidence());
    }

    public function test_stale_save_archive_and_snapshot_never_overwrite_the_winner(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $stale = $this->preset($actor);
        $command->handle($stale, ['version' => 1, 'mood' => 'Winning edit'], $actor);
        $before = $this->evidence();
        $this->rejects(fn () => $command->handle($stale, ['version' => 1, 'mood' => 'Losing edit'], $actor), 'name');
        $this->rejects(fn () => $command->archive($stale, 1, $actor), 'name');
        $this->rejects(fn () => $command->snapshot($stale->id, $actor, 1), 'name');
        $this->rejects(fn () => $command->snapshot(PHP_INT_MAX, $actor), 'name');
        $this->assertSame($before, $this->evidence());
        $this->assertSame('Winning edit', $command->snapshot($stale->id, $actor, 2)['metadata']['mood']);
    }

    public function test_unknown_identity_publication_commercial_rights_and_media_fields_are_rejected_atomically(): void
    {
        $actor = LicenseFixtures::admin();
        $preset = $this->preset($actor);
        $command = app(TrackMetadataPresets::class);
        $before = $this->evidence();
        foreach ([
            'id' => 999, 'title' => 'Wrong identity', 'slug' => 'wrong-url', 'status' => 'published',
            'published_at' => now(), 'published_slug' => 'reserved', 'archived_at' => now(),
            'price_minor' => 1, 'currency' => 'USD', 'license_version_id' => 1, 'offer_id' => 1,
            'rights_declarations' => ['verified'], 'deliverable_asset_ids' => [1], 'asset_id' => 1,
            'storage_path' => 'private/source.wav', 'waveform' => [0.9], 'duration_seconds' => 120,
            'actor_id' => $actor->id, 'metadata_version' => 1, 'metadata' => ['artist' => 'Nested bypass'],
        ] as $field => $value) {
            $this->rejects(fn () => $command->handle(null, $this->payload([$field => $value]), $actor));
            $this->rejects(fn () => $command->handle($preset, ['version' => 1, $field => $value], $actor));
        }
        $this->rejects(fn () => $command->handle(null, $this->payload(['version' => 1]), $actor), 'name');
        $this->assertSame($before, $this->evidence());
    }

    public function test_bounds_shapes_normalization_and_tag_order_are_enforced_without_mutation_on_failure(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $before = $this->evidence();
        foreach ([
            ['name', ''], ['name', str_repeat('x', 256)], ['artist', ''], ['artist', str_repeat('x', 256)],
            ['bpm', 19], ['bpm', 401], ['bpm', 92.5], ['musical_key', str_repeat('x', 25)],
            ['genre', str_repeat('x', 256)], ['mood', str_repeat('x', 256)], ['description', str_repeat('x', 10001)],
            ['tags', ['key' => 'Tag']], ['tags', ['Duplicate', 'Duplicate']], ['tags', [42]], ['tags', ['']],
            ['tags', [str_repeat('x', 81)]], ['tags', array_map(fn ($i) => 'Tag '.$i, range(1, 21))],
            ['version', null], ['version', -1], ['version', 2147483648],
        ] as [$field, $value]) {
            $this->rejects(fn () => $command->handle($preset, ['version' => 1, $field => $value], $actor), $field === 'version' ? null : $field);
        }
        $this->rejects(fn () => $command->handle($preset, ['mood' => 'Missing revision'], $actor));
        $this->assertSame($before, $this->evidence());
        $tags = array_map(fn ($i) => 'Tag '.$i, range(20, 1));
        $tags[0] = str_repeat('x', 80);
        $saved = $command->handle($preset, ['version' => 1, 'name' => '  Trimmed name  ', 'artist' => str_repeat('a', 255),
            'bpm' => '20', 'musical_key' => str_repeat('k', 24), 'genre' => str_repeat('g', 255),
            'mood' => '   ', 'description' => str_repeat('d', 10000), 'tags' => $tags], $actor);
        $snapshot = $command->snapshot($saved->id, $actor, 2);
        $this->assertSame('Trimmed name', $snapshot['name']);
        $this->assertSame(20, $snapshot['metadata']['bpm']);
        $this->assertNull($snapshot['metadata']['mood']);
        $this->assertSame($tags, $snapshot['metadata']['tags']);
        $saved = $command->handle($saved, ['version' => 2, 'bpm' => 400, 'tags' => []], $actor);
        $this->assertSame(400, $command->snapshot($saved->id, $actor, 3)['metadata']['bpm']);
    }

    public function test_reviewed_snapshot_is_an_independent_copy_and_creates_only_an_ordinary_private_draft(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $snapshot = $command->snapshot($preset->id, $actor, 1);
        $reviewed = ['title' => 'Synthetic reviewed copy', 'slug' => 'synthetic-reviewed-copy'] + $snapshot['metadata'];
        $command->handle($preset, ['version' => 1, 'artist' => 'Later artist', 'tags' => ['Later tags'], 'description' => 'Later private note'], $actor);
        $command->archive($preset->fresh(), 2, $actor);
        $presetBefore = DB::table('track_metadata_presets')->get()->toJson();
        $draft = $command->createDraft($reviewed, $actor)->fresh();
        foreach ($reviewed as $field => $expected) {
            $this->assertSame($expected, $draft->{$field}, $field.' did not retain the reviewed snapshot.');
        }
        $this->assertSame('draft', $draft->status);
        $this->assertSame(1, $draft->metadata_version);
        $this->assertNull($draft->published_at);
        $this->assertNull($draft->published_slug);
        $this->assertNull($draft->duration_seconds);
        $this->assertCount(0, $draft->assets);
        $this->assertCount(0, $draft->offers);
        $this->assertCount(0, $draft->rightsDeclarations);
        $this->assertSame($presetBefore, DB::table('track_metadata_presets')->get()->toJson());
        $this->get('/tracks/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        app(SaveTrackMetadata::class)->handle($draft, ['metadata_version' => 1, 'tags' => ['Independent draft tags']], $actor);
        $this->assertSame($presetBefore, DB::table('track_metadata_presets')->get()->toJson());
        $this->assertSame(['Strings', 'Low brass', 'strings'], $snapshot['metadata']['tags']);
        $created = AuditEvent::where('action', 'catalog.track.created')->sole();
        $this->assertSame($actor->id, $created->actor_id);
        $this->assertSame($draft->id, $created->subject_id);
        $this->assertStringNotContainsString('Private synthetic', json_encode($created->context));
    }

    public function test_corrupt_persisted_metadata_and_exhausted_revisions_fail_closed_without_normalizing_or_overwriting_rows(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $valid = $command->snapshot($preset->id, $actor)['metadata'];
        foreach ([array_diff_key($valid, ['tags' => true]), $valid + ['status' => 'published'],
            array_replace($valid, ['artist' => '  Unnormalized artist  ']), array_replace($valid, ['tags' => ['Duplicate', 'Duplicate']])] as $corrupt) {
            DB::table('track_metadata_presets')->where('id', $preset->id)->update(['metadata' => json_encode($corrupt, JSON_THROW_ON_ERROR)]);
            $before = $this->evidence();
            $this->rejects(fn () => $command->snapshot($preset->id, $actor));
            $this->rejects(fn () => $command->handle($preset, ['version' => 1, 'mood' => 'No repair by edit'], $actor));
            $this->rejects(fn () => $command->archive($preset, 1, $actor));
            $this->assertSame($before, $this->evidence());
        }
        DB::table('track_metadata_presets')->where('id', $preset->id)->update(['metadata' => json_encode($valid, JSON_THROW_ON_ERROR), 'version' => 2147483647]);
        $before = $this->evidence();
        $this->rejects(fn () => $command->handle($preset, ['version' => 2147483647, 'mood' => 'Overflow'], $actor), 'name');
        $this->rejects(fn () => $command->archive($preset, 2147483647, $actor), 'name');
        $this->assertSame($before, $this->evidence());
    }

    public function test_snapshot_draft_creation_requires_identity_and_denies_preset_commerce_publication_and_media_injection(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $metadata = $command->snapshot($this->preset($actor)->id, $actor)['metadata'];
        $valid = ['title' => 'Synthetic copy', 'slug' => 'synthetic-copy'] + $metadata;
        $before = $this->evidence();
        $this->rejects(fn () => $command->createDraft($metadata, $actor), 'title');
        foreach (['preset_id' => 1, 'version' => 1, 'status' => 'published', 'published_slug' => 'forged',
            'price_minor' => 1, 'license_version_id' => 1, 'deliverable_asset_ids' => [1], 'duration_seconds' => 120, 'waveform' => [1]] as $field => $value) {
            $this->rejects(fn () => $command->createDraft($valid + [$field => $value], $actor), 'title');
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_every_mutation_rolls_back_when_audit_recording_fails(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $metadata = $command->snapshot($preset->id, $actor)['metadata'];
        $before = $this->evidence();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic preset audit failure'));
        foreach ([
            fn () => $command->handle(null, $this->payload(['name' => 'Must roll back']), $actor),
            fn () => $command->handle($preset, ['version' => 1, 'mood' => 'Must roll back'], $actor),
            fn () => $command->archive($preset, 1, $actor),
            fn () => $command->createDraft(['title' => 'Must roll back', 'slug' => 'must-roll-back'] + $metadata, $actor),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Audit failure did not abort the preset operation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic preset audit failure', $exception->getMessage());
            }
            $this->assertSame($before, $this->evidence());
        }
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_retained_actor_models_cannot_read_or_write_after_persisted_authority_withdrawal(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $metadata = $command->snapshot($preset->id, $actor)['metadata'];
        User::whereKey($actor->id)->update([$field => $value]);
        $before = $this->evidence();
        foreach ([
            fn () => $command->active($actor), fn () => $command->snapshot($preset->id, $actor),
            fn () => $command->handle(null, $this->payload(), $actor),
            fn () => $command->handle($preset, ['version' => 1, 'mood' => 'Denied'], $actor),
            fn () => $command->archive($preset, 1, $actor),
            fn () => $command->createDraft(['title' => 'Denied', 'slug' => 'denied'] + $metadata, $actor),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Stale operator authority allowed a preset operation.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame($before, $this->evidence());
        $this->actingAs($actor)->get('/admin/track-metadata-presets')->assertForbidden();
    }

    public static function authorityWithdrawals(): array
    {
        return ['staff role' => ['is_admin', false], 'email verification' => ['email_verified_at', null]];
    }

    public function test_direct_operations_reject_customers_deleted_and_locally_elevated_users_and_current_mfa_removal(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $customer = User::factory()->create();
        $customer->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        $before = $this->evidence();
        foreach ([$customer, $deleted] as $denied) {
            foreach ([fn () => $command->active($denied), fn () => $command->handle(null, $this->payload(), $denied),
                fn () => $command->archive($preset, 1, $denied)] as $operation) {
                try {
                    $operation();
                    $this->fail('Invalid persisted actor retained preset authority.');
                } catch (AuthorizationException) {
                }
            }
        }
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $metadata = $command->snapshot($preset->id, $actor)['metadata'];
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            foreach ([fn () => $command->active($actor), fn () => $command->snapshot($preset->id, $actor),
                fn () => $command->handle($preset, ['version' => 1, 'mood' => 'Denied'], $actor),
                fn () => $command->archive($preset, 1, $actor),
                fn () => $command->createDraft(['title' => 'Denied', 'slug' => 'denied'] + $metadata, $actor)] as $operation) {
                try {
                    $operation();
                    $this->fail('Removed MFA enrollment retained preset authority.');
                } catch (AuthorizationException) {
                }
            }
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_real_filament_create_edit_and_confirmed_archive_persist_the_expected_revision(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        Livewire::test(ManageTrackMetadataPresets::class)->callAction('create', data: $this->payload())->assertHasNoActionErrors();
        $preset = TrackMetadataPreset::sole();
        $this->assertSame(1, $preset->version);
        Livewire::test(ManageTrackMetadataPresets::class)->callTableAction('edit', $preset, data: ['name' => 'Edited UI defaults', 'tags' => ['UI first', 'UI second']])
            ->assertHasNoTableActionErrors();
        $preset->refresh();
        $this->assertSame('Edited UI defaults', $preset->name);
        $this->assertSame(2, $preset->version);
        $this->assertSame(['UI first', 'UI second'], app(TrackMetadataPresets::class)->snapshot($preset->id, $actor)['metadata']['tags']);
        $before = $this->evidence();
        $archive = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('archive', $preset)
            ->assertSet('mountedActions.0.name', 'archive')->assertSet('expectedPresetId', $preset->id)->assertSet('expectedPresetVersion', 2);
        $action = $archive->instance()->getMountedAction();
        $this->assertTrue($action->shouldOpenModal());
        $this->assertSame('Archive metadata preset', $action->getModalHeading());
        $this->assertSame('Archive preset', $action->getModalSubmitActionLabel());
        // Filament mounts this confirmation with an action-modals partial. Testable::html()
        // keeps the previous full response, so request a full render to inspect its actual heading.
        $archive->call('forceRender')->assertSee('Archive metadata preset');
        $this->assertSame($before, $this->evidence());
        $archive->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertNotNull($preset->fresh()->archived_at);
        $this->assertSame(3, $preset->fresh()->version);
        $this->assertDatabaseCount('tracks', 0);
        $this->assertSame(1, AuditEvent::where('action', self::CREATED)->count());
        $this->assertSame(1, AuditEvent::where('action', self::UPDATED)->count());
        $this->assertSame(1, AuditEvent::where('action', self::ARCHIVED)->count());
    }

    public function test_filament_validation_and_stale_edit_or_archive_leave_forms_and_winner_unchanged(): void
    {
        $actor = LicenseFixtures::admin();
        $preset = $this->preset($actor);
        $this->actingAs($actor);
        Livewire::test(ManageTrackMetadataPresets::class)->callAction('create', data: $this->payload(['bpm' => 401]))->assertHasActionErrors(['bpm']);
        Livewire::test(ManageTrackMetadataPresets::class)->callTableAction('edit', $preset, data: ['tags' => ['same', 'same']])->assertHasTableActionErrors(['tags.0', 'tags.1']);
        $stale = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('edit', $preset)->setTableActionData(['mood' => 'Losing UI edit']);
        $archive = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('archive', $preset);
        app(TrackMetadataPresets::class)->handle($preset, ['version' => 1, 'mood' => 'Winning domain edit'], $actor);
        $before = $this->evidence();
        $stale->callMountedTableAction()->assertHasTableActionErrors(['name']);
        $archive->callMountedTableAction()->assertNotified('Preset could not be archived');
        $this->assertSame($before, $this->evidence());
        Livewire::test(ManageTrackMetadataPresets::class)->callTableAction('edit', $preset->fresh(), data: ['genre' => 'Reopened UI edit'])->assertHasNoTableActionErrors();
        $snapshot = app(TrackMetadataPresets::class)->snapshot($preset->id, $actor, 3);
        $this->assertSame('Winning domain edit', $snapshot['metadata']['mood']);
        $this->assertSame('Reopened UI edit', $snapshot['metadata']['genre']);
    }

    public function test_real_track_action_copies_metadata_for_review_and_does_not_write_until_private_draft_confirmation(): void
    {
        $actor = LicenseFixtures::admin();
        $preset = $this->preset($actor);
        $command = app(TrackMetadataPresets::class);
        $expected = $command->snapshot($preset->id, $actor, 1);
        $this->actingAs($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->callAction('createFromPreset', data: ['preset_id' => $preset->id])
            ->assertHasNoActionErrors()->assertSee('Create private draft from preset');
        $this->assertSame($expected, $page->get('presetMetadataSnapshot'));
        $this->assertSame($expected['metadata']['artist'], $page->get('mountedActions.0.data.artist'));
        $this->assertSame($expected['metadata']['tags'], $page->get('mountedActions.0.data.tags'));
        $this->assertSame($before, $this->evidence());
        $page->setActionData(['title' => '', 'slug' => 'reviewed-ui-draft'])->callMountedAction()->assertHasActionErrors(['title']);
        $this->assertSame($expected, $page->get('presetMetadataSnapshot'));
        $this->assertSame($expected['metadata']['tags'], $page->get('mountedActions.0.data.tags'));
        $this->assertSame($before, $this->evidence());
        $command->handle($preset, ['version' => 1, 'tags' => ['Later edit'], 'artist' => 'Later artist'], $actor);
        $command->archive($preset->fresh(), 2, $actor);
        $page->setActionData(['title' => 'Reviewed UI draft', 'slug' => 'reviewed-ui-draft'])->callMountedAction()->assertHasNoActionErrors();
        $draft = Track::sole();
        $this->assertSame('draft', $draft->status);
        $this->assertSame($expected['metadata']['artist'], $draft->artist);
        $this->assertSame($expected['metadata']['tags'], $draft->tags);
        $this->assertSame($expected['metadata']['description'], $draft->description);
        $this->assertNull($draft->published_slug);
        $this->get('/tracks/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->assertNull($page->get('presetMetadataSnapshot'));
    }

    public function test_closing_copy_review_or_mounting_draft_directly_never_creates_a_track_and_snapshot_is_locked(): void
    {
        $actor = LicenseFixtures::admin();
        $preset = $this->preset($actor);
        $this->actingAs($actor);
        $before = $this->evidence();
        Livewire::test(ManageTracks::class)->call('createPresetDraft', ['title' => 'Direct bypass', 'slug' => 'direct-bypass'])->assertForbidden();
        Livewire::test(ManageTracks::class)->mountAction('createPresetDraft')->callMountedAction();
        $page = Livewire::test(ManageTracks::class)->callAction('createFromPreset', data: ['preset_id' => $preset->id]);
        $page->unmountAction()->assertSet('presetMetadataSnapshot', null)->mountAction('createPresetDraft')->callMountedAction();
        $tampered = Livewire::test(ManageTracks::class)->callAction('createFromPreset', data: ['preset_id' => $preset->id]);
        try {
            $tampered->set('presetMetadataSnapshot.metadata.artist', 'Forged snapshot');
            $this->fail('Client changed the locked preset snapshot.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        $archive = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('archive', $preset);
        try {
            $archive->set('expectedPresetVersion', 999);
            $this->fail('Client changed the locked archive version.');
        } catch (CannotUpdateLockedPropertyException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_every_mounted_preset_and_draft_action_reauthorizes_after_persisted_withdrawal(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $preset = $this->preset($actor);
        $this->actingAs($actor);
        $create = Livewire::test(ManageTrackMetadataPresets::class)->mountAction('create')->setActionData($this->payload());
        $edit = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('edit', $preset)->setTableActionData(['mood' => 'Denied edit']);
        $archive = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('archive', $preset);
        $copy = Livewire::test(ManageTracks::class)->mountAction('createFromPreset')->setActionData(['preset_id' => $preset->id]);
        $draft = Livewire::test(ManageTracks::class)->callAction('createFromPreset', data: ['preset_id' => $preset->id])
            ->setActionData(['title' => 'Denied draft', 'slug' => 'denied-draft']);
        User::whereKey($actor->id)->update([$field => $value]);
        $before = $this->evidence();
        $create->callMountedAction()->assertForbidden();
        $edit->callMountedTableAction()->assertForbidden();
        $archive->callMountedTableAction()->assertForbidden();
        $copy->callMountedAction()->assertForbidden();
        $draft->callMountedAction()->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public function test_mounted_presets_and_reviewed_draft_recheck_current_mfa_enrollment(): void
    {
        $actor = LicenseFixtures::admin();
        $preset = $this->preset($actor);
        $this->actingAs($actor);
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $create = Livewire::test(ManageTrackMetadataPresets::class)->mountAction('create')->setActionData($this->payload());
            $edit = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('edit', $preset)->setTableActionData(['mood' => 'Denied']);
            $archive = Livewire::test(ManageTrackMetadataPresets::class)->mountTableAction('archive', $preset);
            $copy = Livewire::test(ManageTracks::class)->mountAction('createFromPreset')->setActionData(['preset_id' => $preset->id]);
            $draft = Livewire::test(ManageTracks::class)->callAction('createFromPreset', data: ['preset_id' => $preset->id])
                ->setActionData(['title' => 'Denied draft', 'slug' => 'denied-draft']);
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            $before = $this->evidence();
            $create->callMountedAction()->assertForbidden();
            $edit->callMountedTableAction()->assertForbidden();
            $archive->callMountedTableAction()->assertForbidden();
            $copy->callMountedAction()->assertForbidden();
            $draft->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_presets_and_new_draft_cannot_change_a_published_track_or_retained_commerce_and_media(): void
    {
        // One real readiness fixture covers public and immutable commerce boundaries; the other cases need no media work.
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        app(CreateQuote::class)->handle(str_repeat('a', 64), 'synthetic-preset-retained-quote', $fixture['items']);
        $tables = ['tracks', 'media_assets', 'media_processing_runs', 'rights_declarations', 'license_templates', 'license_versions',
            'offers', 'offer_revisions', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations', 'inventory_claims',
            'orders', 'order_lines', 'order_attempts', 'verified_payments', 'license_grants', 'grant_contracts'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $before = $snapshot();
        $command = app(TrackMetadataPresets::class);
        $preset = $this->preset($actor);
        $reviewed = $command->snapshot($preset->id, $actor)['metadata'];
        $preset = $command->handle($preset, ['version' => 1, 'artist' => 'Later preset artist'], $actor);
        $command->archive($preset, 2, $actor);
        $this->assertSame($before, $snapshot());
        $draft = $command->createDraft(['title' => 'Independent private copy', 'slug' => 'independent-private-copy'] + $reviewed, $actor);
        $this->assertSame($before[0], DB::table('tracks')->where('id', $track->id)->get()->toJson());
        $this->assertSame(array_slice($before, 1), array_slice($snapshot(), 1));
        $this->get('/tracks/'.$track->slug)->assertOk()->assertSee($track->title);
        $this->get('/tracks/'.$draft->slug)->assertNotFound();
        $catalog = $this->getJson('/api/catalog')->assertOk()->assertJsonCount(1, 'tracks')->json();
        $this->assertStringNotContainsString('Independent private copy', json_encode($catalog));
    }
}
