<?php

namespace Tests\Feature;

use App\Application\Media\MediaIntegrity;
use App\Domain\Catalog\ActivateExclusiveOffer;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Catalog\TrackPublicationManifest;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Delivery\MediaEvidenceValues;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class TrackPublicationManifestTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function capture(Track $track, User $actor): TrackPublicationManifest
    {
        $this->assertSame(0, DB::transactionLevel(), 'Manifest fixtures must be committed.');

        return app(ReadTrackPublicationManifest::class)->handle($track->id, $actor);
    }

    private function evidence(): array
    {
        $tables = ['tracks', 'media_assets', 'media_processing_runs', 'stems_recordings', 'rights_declarations', 'license_templates',
            'license_versions', 'license_review_evidence', 'offers', 'offer_revisions', 'exclusive_activations', 'rights_scopes',
            'rights_scope_offers', 'exclusive_sales', 'quotes', 'quote_lines', 'orders', 'license_grants',
            'site_publications', 'site_publication_schedules', 'audit_events'];
        $rows = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $files = Storage::disk('local')->allFiles();
        sort($files, SORT_STRING);

        return [$rows, array_map(fn ($path) => [$path, hash_file('sha256', Storage::disk('local')->path($path))], $files)];
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Ineligible current dependencies produced a publication manifest.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('publication', $error->errors());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Withdrawn authority received a publication manifest.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    private function containsFraction(mixed $value): bool
    {
        if (is_float($value) && floor($value) !== $value) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsFraction($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertNoPrivateKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            $this->assertNotContains($key, ['storage_path', 'disk', 'original_name', 'authored_source', 'structured_terms',
                'source', 'run', 'source_scan', 'tag_scan', 'profile', 'evidence',
                'provenance_reference', 'sample_disclosure', 'approval_reference']);
            if (is_array($item)) {
                $this->assertNoPrivateKeys($item);
            }
        }
    }

    public function test_actual_fractional_media_produces_minimized_read_only_identity_without_relaxing_integer_canonical_json(): void
    {
        $fixture = QuoteFixtures::selection();
        $track = $fixture['track'];
        $preview = $fixture['media']['preview_tagged']->fresh();
        $this->assertTrue($this->containsFraction($preview->technical_metadata), 'Actual FFmpeg evidence must exercise fractional duration or waveform values.');
        try {
            CanonicalJson::encode(['fraction' => 0.125]);
            $this->fail('Global integer-only canonical JSON was relaxed for media.');
        } catch (InvalidArgumentException) {
        }
        $before = $this->evidence();
        $manifest = $this->capture($track, $fixture['actor']);
        $payload = $manifest->payload();
        $this->assertSame($before, $this->evidence());
        $this->assertSame(CanonicalJson::hash($payload), $manifest->hash());
        $this->assertEqualsCanonicalizing(['schema_version', 'canonicalization_version', 'track', 'rights', 'artwork', 'preview_tagged', 'offers'], array_keys($payload));
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame(CanonicalJson::VERSION, $payload['canonicalization_version']);
        $this->assertSame($track->only(['id', 'status', 'metadata_version', 'publication_version', 'title', 'slug', 'published_slug', 'artist',
            'bpm', 'musical_key', 'genre', 'mood', 'tags', 'description']), $payload['track']);
        $this->assertSame(MediaEvidenceValues::reference($preview->technical_metadata['duration_seconds']), $payload['preview_tagged']['duration_seconds_reference']);
        $this->assertSame($preview->technical_metadata['waveform_sha256'], $payload['preview_tagged']['waveform_sha256']);
        $this->assertSame($preview->technical_metadata['tag_sha256'], $payload['preview_tagged']['tag_sha256']);
        $this->assertSame($fixture['revision']->snapshot['commercial'], $payload['offers'][0]['commercial']);
        $this->assertSame($fixture['revision']->snapshot_hash, $payload['offers'][0]['snapshot_hash']);
        $this->assertSame(CanonicalJson::hash($fixture['revision']->snapshot['license']), $payload['offers'][0]['license']['identity_hash']);
        $this->assertNull($payload['offers'][0]['exclusive']);
        $this->assertSame(['id' => $fixture['revision']->rights_declaration_id,
            'identity_hash' => $fixture['revision']->snapshot['rights']['identity_hash']], $payload['rights']);
        $derivativeKeys = ['asset_id', 'role', 'sha256', 'size_bytes', 'parent_asset_id', 'processing_run_id', 'profile_fingerprint'];
        $this->assertEqualsCanonicalizing($derivativeKeys, array_keys($payload['artwork']));
        $this->assertEqualsCanonicalizing([...$derivativeKeys, 'waveform_sha256', 'tag_sha256', 'duration_seconds_reference'], array_keys($payload['preview_tagged']));
        foreach (['artwork', 'preview_tagged'] as $role) {
            $asset = $fixture['media'][$role];
            $this->assertSame($asset->id, $payload[$role]['asset_id']);
            $this->assertSame($asset->sha256, $payload[$role]['sha256']);
            $this->assertSame($asset->processingRun->profile_fingerprint, $payload[$role]['profile_fingerprint']);
        }
        $entry = $payload['offers'][0];
        $this->assertEqualsCanonicalizing(['offer_id', 'revision_id', 'revision', 'schema_version', 'snapshot_hash',
            'canonicalization_version', 'commercial', 'license', 'deliverables', 'exclusive'], array_keys($entry));
        $this->assertEqualsCanonicalizing(['id', 'template_id', 'version', 'type', 'source_hash', 'model_hash', 'renderer_version',
            'render_fixture_hash', 'submission_hash', 'review_evidence_id', 'review_evidence_hash', 'effective_from', 'effective_until', 'identity_hash'], array_keys($entry['license']));
        $this->assertEqualsCanonicalizing(['asset_id', 'role', 'sha256', 'mime_type', 'size_bytes', 'parent_asset_id',
            'processing_run_id', 'recording_binding'], array_keys($entry['deliverables'][0]));
        $this->assertSame($fixture['media']['master_wav']->id, $entry['deliverables'][0]['asset_id']);
        $this->assertSame($fixture['media']['master_wav']->sha256, $entry['deliverables'][0]['sha256']);
        $this->assertNull($entry['deliverables'][0]['recording_binding']);
        $this->assertSame($fixture['actor']->id, $manifest->actorId());
        $this->assertInstanceOf(CarbonImmutable::class, $manifest->capturedAt());
        $this->assertFalse($manifest instanceof JsonSerializable);
        $this->assertFalse($manifest instanceof Arrayable);
        $this->assertSame('{}', json_encode($manifest, JSON_THROW_ON_ERROR), 'The internal value must not silently become an HTTP payload.');
        $this->assertNoPrivateKeys($payload);
        $encoded = CanonicalJson::encode($payload);
        foreach ($fixture['media'] as $asset) {
            $this->assertStringNotContainsString($asset->storage_path, $encoded);
            $this->assertStringNotContainsString($asset->original_name, $encoded);
        }
        $this->assertStringNotContainsString($fixture['offer']->licenseVersion->authored_source, $encoded);
        $this->assertStringNotContainsString('TEST-ONLY-QUOTE-RIGHTS', $encoded);
    }

    public function test_hash_is_stable_across_current_authorized_actors_time_and_reads_and_returned_payload_cannot_mutate_the_value(): void
    {
        $this->freezeSecond();
        $fixture = QuoteFixtures::selection();
        $other = LicenseFixtures::admin();
        $before = $this->evidence();
        $first = $this->capture($fixture['track'], $fixture['actor']);
        $firstPayload = $first->payload();
        $firstTime = $first->capturedAt();
        $this->travel(10)->seconds();
        $second = $this->capture($fixture['track'], $other);
        $third = $this->capture($fixture['track'], $fixture['actor']);
        $this->assertSame($firstPayload, $second->payload());
        $this->assertSame($first->hash(), $second->hash());
        $this->assertSame($first->hash(), $third->hash());
        $this->assertSame($other->id, $second->actorId());
        $this->assertTrue($second->capturedAt()->greaterThan($firstTime));
        $copy = $first->payload();
        $copy['track']['title'] = 'Changed outside value';
        $copy['offers'] = [];
        $this->assertSame($firstPayload, $first->payload());
        $this->assertSame(CanonicalJson::hash($firstPayload), $first->hash());
        $this->assertEquals($firstTime, $first->capturedAt());
        $this->assertSame($before, $this->evidence());
    }

    public function test_current_metadata_and_publication_commands_change_identity_while_old_value_remains_exact(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $first = $this->capture($track, $actor);
        $original = $first->payload();
        $track = app(SaveTrackMetadata::class)->handle($track, ['mood' => 'Current publication metadata',
            'tags' => ['Ordered last', 'Ordered first'], 'description' => 'Current private descriptive note', 'metadata_version' => $track->metadata_version], $actor);
        $second = $this->capture($track, $actor);
        $this->assertNotSame($first->hash(), $second->hash());
        $this->assertSame($track->metadata_version, $second->payload()['track']['metadata_version']);
        $this->assertSame(['Ordered last', 'Ordered first'], $second->payload()['track']['tags']);
        $this->assertSame('Current private descriptive note', $second->payload()['track']['description']);
        $track = app(PublishTrack::class)->unpublish($track, $actor);
        $third = $this->capture($track, $actor);
        $this->assertNotSame($second->hash(), $third->hash());
        $this->assertSame('draft', $third->payload()['track']['status']);
        $this->assertSame($track->publication_version, $third->payload()['track']['publication_version']);
        $this->assertSame($track->published_slug, $third->payload()['track']['published_slug']);
        $this->assertSame($original, $first->payload());
        $this->assertSame(CanonicalJson::hash($original), $first->hash());
    }

    public function test_editable_offer_fields_do_not_replace_frozen_commercial_identity_but_successor_and_active_set_changes_do(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = $fixture['track'];
        $offer = $fixture['offer'];
        $first = $this->capture($track, $actor);
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 1234, 'deliverable_asset_ids' => []], $actor);
        $this->assertSame($first->payload(), $this->capture($track, $actor)->payload(), 'Editable drafts must not replace the active immutable revision.');
        $this->assertSame($first->hash(), $this->capture($track, $actor)->hash());
        app(SaveOfferDraft::class)->handle($offer, ['price_minor' => 1234, 'deliverable_asset_ids' => [$fixture['media']['master_wav']->id]], $actor);
        $successor = app(PublishOffer::class)->handle($offer, $actor);
        $second = $this->capture($track, $actor);
        $this->assertNotSame($first->hash(), $second->hash());
        $this->assertSame($successor->id, $second->payload()['offers'][0]['revision_id']);
        $newOffer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $offer->license_version_id,
            'price_minor' => 2345, 'currency' => 'USD', 'deliverable_asset_ids' => [$fixture['media']['master_wav']->id]], $actor);
        app(PublishOffer::class)->handle($newOffer, $actor);
        $third = $this->capture($track, $actor);
        $this->assertNotSame($second->hash(), $third->hash());
        $this->assertCount(2, $third->payload()['offers']);
        $this->assertSame([$offer->id, $newOffer->id], array_column($third->payload()['offers'], 'offer_id'));
        app(DeactivateOffer::class)->handle($offer, $actor);
        $fourth = $this->capture($track, $actor);
        $this->assertCount(1, $fourth->payload()['offers']);
        $this->assertSame($newOffer->id, $fourth->payload()['offers'][0]['offer_id']);
        $this->assertNotSame($third->hash(), $fourth->hash());
    }

    public static function revocations(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null],
            'MFA enrollment' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_current_persisted_authority_and_mfa_are_required_even_when_the_supplied_actor_model_is_stale(string $field, mixed $value): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->capture($fixture['track'], $actor);
            $oldActor = $actor->fresh();
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $this->denied(fn () => $this->capture($fixture['track'], $oldActor));
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_unverified_nonstaff_deleted_and_forged_actor_models_do_not_receive_private_evidence(): void
    {
        $fixture = QuoteFixtures::selection();
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        $forged = new User;
        $forged->forceFill(['id' => $fixture['actor']->id, 'is_admin' => true, 'email_verified_at' => now()]);
        $before = $this->evidence();
        foreach ([User::factory()->create(), $unverified, $deleted, $forged] as $actor) {
            $this->denied(fn () => $this->capture($fixture['track'], $actor));
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_ambient_transaction_is_rejected_before_any_private_read_and_no_caller_state_is_consumed(): void
    {
        $fixture = QuoteFixtures::selection();
        $before = $this->evidence();
        $queries = [];
        $inspect = true;
        DB::listen(function ($query) use (&$queries, &$inspect): void {
            if ($inspect) {
                $queries[] = $query->sql;
            }
        });
        DB::beginTransaction();
        try {
            try {
                app(ReadTrackPublicationManifest::class)->handle($fixture['track']->id, $fixture['actor']);
                $this->fail('Manifest capture inherited a caller snapshot.');
            } catch (LogicException) {
            }
            $inspect = false;
            $this->assertSame([], $queries, 'Standalone rejection must precede private queries.');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($before, $this->evidence());
        } finally {
            $inspect = false;
            DB::rollBack();
        }
        $this->capture($fixture['track'], $fixture['actor']);
        $this->assertSame($before, $this->evidence());
    }

    public function test_incomplete_and_missing_tracks_are_refused_without_any_row_audit_or_file_write(): void
    {
        $actor = LicenseFixtures::admin();
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Incomplete manifest track', 'slug' => 'incomplete-manifest-track'], $actor);
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($track, $actor));
        try {
            app(ReadTrackPublicationManifest::class)->handle(999999999, $actor);
            $this->fail('Missing track produced private evidence.');
        } catch (ModelNotFoundException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_an_actual_new_ready_artwork_changes_identity_and_damaged_latest_evidence_has_no_older_fallback(): void
    {
        $fixture = QuoteFixtures::selection();
        $fixture['track'] = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $old = $fixture['media']['artwork'];
        $first = $this->capture($fixture['track'], $fixture['actor']);
        $source = MediaFixtures::source($fixture['track'], 'artwork');
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $fixture['actor'])->id);
        $latest = $run->outputs()->sole();
        $this->assertTrue(app(VerifiedMedia::class)->available($latest));
        $second = $this->capture($fixture['track'], $fixture['actor']);
        $this->assertSame($latest->id, $second->payload()['artwork']['asset_id']);
        $this->assertNotSame($first->hash(), $second->hash());
        $this->damage($latest->storage_path);
        $this->travel(MediaIntegrity::CACHE_SECONDS + 1)->seconds();
        $this->assertTrue(app(VerifiedMedia::class)->available($old));
        $this->assertFalse(app(VerifiedMedia::class)->available($latest->fresh()));
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($fixture['track'], $fixture['actor']));
        $this->assertSame($before, $this->evidence());
    }

    public function test_damaged_latest_preview_is_refused_even_though_the_previous_verified_recording_remains_available(): void
    {
        $fixture = QuoteFixtures::selection();
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $old = $fixture['media']['preview_tagged'];
        $media = MediaFixtures::readyTrackMedia($track, $fixture['actor']);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['deliverable_asset_ids' => [$media['master_wav']->id]], $fixture['actor']);
        app(PublishOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $manifest = $this->capture($track, $fixture['actor']);
        $this->assertSame($media['preview_tagged']->id, $manifest->payload()['preview_tagged']['asset_id']);
        $this->damage($media['preview_tagged']->storage_path);
        $this->travel(MediaIntegrity::CACHE_SECONDS + 1)->seconds();
        $this->assertTrue(app(VerifiedMedia::class)->available($old));
        $this->assertFalse(app(VerifiedMedia::class)->available($media['preview_tagged']->fresh()));
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($track, $fixture['actor']));
        $this->assertSame($before, $this->evidence());
    }

    public function test_exact_license_effective_end_is_pinned_but_actual_expiry_denies_a_new_capture_without_changing_the_old_value(): void
    {
        $this->freezeSecond();
        $fixture = QuoteFixtures::selection();
        $license = LicenseFixtures::published($fixture['actor'], content: ['effective_from' => now()->subMinute(), 'effective_until' => now()->addMinute()]);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $license->id], $fixture['actor']);
        app(PublishOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $manifest = $this->capture($fixture['track'], $fixture['actor']);
        $payload = $manifest->payload();
        $this->assertSame($license->effective_until->toISOString(), $payload['offers'][0]['license']['effective_until']);
        $this->travel(60)->seconds();
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($fixture['track'], $fixture['actor']));
        $this->assertSame($before, $this->evidence());
        $this->assertSame($payload, $manifest->payload());
        $this->assertSame(CanonicalJson::hash($payload), $manifest->hash());
    }

    public function test_schema_two_identity_requires_current_active_eligibility_and_returned_capture_is_not_a_future_scope_fence(): void
    {
        ExclusiveSelectionFixtures::configure();
        $fixture = ExclusiveSelectionFixtures::prepared();
        $inactive = $this->capture($fixture['track'], $fixture['actor']);
        $this->assertSame([$fixture['legacy']['offer']->id], array_column($inactive->payload()['offers'], 'offer_id'));
        $fixture['activation'] = app(ActivateExclusiveOffer::class)->handle($fixture['offer'], $fixture['revision']->id, $fixture['actor']);
        $active = $this->capture($fixture['track'], $fixture['actor']);
        $payload = $active->payload();
        $this->assertCount(2, $payload['offers']);
        $entry = collect($payload['offers'])->firstWhere('offer_id', $fixture['offer']->id);
        $this->assertSame(2, $entry['schema_version']);
        $this->assertSame($fixture['revision']->snapshot_hash, $entry['snapshot_hash']);
        $this->assertTrue($entry['exclusive']['eligible']);
        $this->assertSame($fixture['activation']->id, $entry['exclusive']['activation']['id']);
        $this->assertSame($fixture['activation']->snapshot_hash, $entry['exclusive']['activation']['snapshot_hash']);
        $this->assertSame(CanonicalJson::encode(ExclusiveSelectionFixtures::policy()), CanonicalJson::encode($entry['exclusive']['policy']));
        $this->assertSame(CanonicalJson::hash(ExclusiveSelectionFixtures::policy()), $entry['exclusive']['policy_hash']);
        $this->assertSame($fixture['scope']->id, $entry['exclusive']['scope']['scope_id']);
        $this->assertFalse($entry['exclusive']['scope']['blocked']);
        $this->assertNull($entry['exclusive']['scope']['exclusive_sale_id']);
        $this->assertSame(0, $entry['exclusive']['scope']['control_version']);
        app(ManageRightsScope::class)->block($fixture['scope']->id, true, 0, 'SYNTHETIC-MANIFEST-BLOCK', $fixture['actor']);
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($fixture['track'], $fixture['actor']));
        $this->assertSame($before, $this->evidence());
        $this->assertSame($payload, $active->payload(), 'A returned consistent-point value cannot promise continuing eligibility.');
        $this->assertSame(CanonicalJson::hash($payload), $active->hash());
    }

    public function test_stems_deliverable_binds_only_minimized_exact_recording_attestation_identity(): void
    {
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $track = app(PublishTrack::class)->unpublish($fixture['track'], $actor);
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Parts/Synthetic.wav']]));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $stems = $run->outputs()->sole();
        $binding = app(BindStemsToRecording::class)->handle($stems, ['master_asset_id' => $fixture['media']['master_wav']->id,
            'preview_asset_id' => $fixture['media']['preview_tagged']->id, 'verification_reference' => 'PRIVATE-MANIFEST-TEST-ONLY',
            'same_recording_confirmed' => true], $actor);
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1, 'features' => ['Synthetic stems and master'], 'required_asset_roles' => ['stems_zip', 'master_wav']]);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
            'price_minor' => 5678, 'currency' => 'USD', 'deliverable_asset_ids' => [$stems->id, $fixture['media']['master_wav']->id]], $actor);
        app(PublishOffer::class)->handle($offer, $actor);
        $before = $this->evidence();
        $manifest = $this->capture($track, $actor);
        $entry = collect($manifest->payload()['offers'])->firstWhere('offer_id', $offer->id);
        $this->assertSame(['master_wav', 'stems_zip'], array_column($entry['deliverables'], 'role'));
        $this->assertNull($entry['deliverables'][0]['recording_binding']);
        $this->assertSame(app(RecordingAssociation::class)->snapshot($stems), $entry['deliverables'][1]['recording_binding']);
        $this->assertSame($binding->evidence_hash, $entry['deliverables'][1]['recording_binding']['evidence_hash']);
        $this->assertStringNotContainsString('PRIVATE-MANIFEST-TEST-ONLY', CanonicalJson::encode($manifest->payload()));
        $this->assertSame($before, $this->evidence());
    }

    public function test_value_constructor_severs_caller_references_and_rejects_mutable_or_fractional_payload_members(): void
    {
        $fixture = QuoteFixtures::selection();
        $captured = $this->capture($fixture['track'], $fixture['actor']);
        $payload = $captured->payload();
        $originalTitle = $payload['track']['title'];
        $referencedTitle = $originalTitle;
        $payload['track']['title'] = &$referencedTitle;
        $value = new TrackPublicationManifest($payload, $captured->actorId(), $captured->capturedAt());
        $hash = $value->hash();
        $referencedTitle = 'Caller changed reference';
        $this->assertSame($originalTitle, $value->payload()['track']['title']);
        $this->assertSame($hash, $value->hash());
        foreach ([new \stdClass, 0.125] as $mutable) {
            $invalid = $captured->payload();
            $invalid['track']['mood'] = $mutable;
            try {
                new TrackPublicationManifest($invalid, $captured->actorId(), $captured->capturedAt());
                $this->fail('Unsupported manifest member entered immutable identity.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_typed_metadata_corruption_cannot_be_projected_even_when_ordinary_readiness_is_otherwise_satisfied(): void
    {
        $fixture = QuoteFixtures::selection();
        DB::table('tracks')->where('id', $fixture['track']->id)->update(['tags' => json_encode(['unexpected' => 'object'], JSON_THROW_ON_ERROR)]);
        $this->assertSame([], app(PublicationReadiness::class)->blockers($fixture['track']->fresh()));
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($fixture['track'], $fixture['actor']));
        $this->assertSame($before, $this->evidence());
    }

    private function damage(string $storagePath): void
    {
        $path = Storage::disk('local')->path($storagePath);
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $this->assertTrue(chmod($path, 0600));
        $this->assertSame(strlen($bytes), file_put_contents($path, $bytes));
    }

    public function test_current_verified_rights_identity_changes_only_after_the_active_immutable_offer_binds_it(): void
    {
        $fixture = QuoteFixtures::selection();
        $first = $this->capture($fixture['track'], $fixture['actor']);
        $rights = RightsDeclaration::create(['track_id' => $fixture['track']->id, 'status' => 'pending',
            'provenance_reference' => 'PRIVATE-MANIFEST-SUCCESSOR-RIGHTS', 'sample_disclosure' => 'Synthetic successor test identity']);
        $rights = app(VerifyRightsDeclaration::class)->handle($rights, $fixture['actor']);
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($fixture['track'], $fixture['actor']));
        $this->assertSame($before, $this->evidence());
        app(PublishOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $second = $this->capture($fixture['track'], $fixture['actor']);
        $this->assertSame(['id' => $rights->id, 'identity_hash' => app(OfferSnapshot::class)->rightsHash($rights)], $second->payload()['rights']);
        $this->assertNotSame($first->hash(), $second->hash());
        $this->assertStringNotContainsString('PRIVATE-MANIFEST-SUCCESSOR-RIGHTS', CanonicalJson::encode($second->payload()));
    }

    public function test_active_schema_two_policy_drift_refuses_capture_without_exposing_or_replacing_the_frozen_offer(): void
    {
        ExclusiveSelectionFixtures::configure();
        $fixture = ExclusiveSelectionFixtures::active();
        $manifest = $this->capture($fixture['track'], $fixture['actor']);
        $policy = ExclusiveSelectionFixtures::policy();
        $policy['non_exclusive_cutoff'] = 'unapproved';
        config(['commerce.test_exclusive_selection_policy' => json_encode($policy, JSON_THROW_ON_ERROR)]);
        $before = $this->evidence();
        $this->invalid(fn () => $this->capture($fixture['track'], $fixture['actor']));
        $this->assertSame($before, $this->evidence());
        $this->assertSame($fixture['revision']->snapshot_hash, collect($manifest->payload()['offers'])->firstWhere('offer_id', $fixture['offer']->id)['snapshot_hash']);
    }

    public function test_duration_identity_is_stable_under_ambient_serialize_precision_changes(): void
    {
        $original = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '-1');
            $fixture = QuoteFixtures::selection();
            $preview = $fixture['media']['preview_tagged'];
            $duration = $preview->technical_metadata['duration_seconds'];
            $baselineNumericToken = json_encode($duration, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $first = $this->capture($fixture['track'], $fixture['actor']);
            $before = $this->evidence();
            ini_set('serialize_precision', '6');
            $this->assertNotSame($baselineNumericToken, json_encode($duration, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'The actual fractional duration must exercise the ambient serialization defect.');
            $this->assertSame($preview->technical_metadata['waveform_sha256'], hash('sha256', json_encode($preview->technical_metadata['waveform'], JSON_THROW_ON_ERROR)),
                'The genuine rounded waveform evidence must remain available in this precision regression.');
            $this->assertSame([], app(PublicationReadiness::class)->blockers($fixture['track']->fresh()));
            $second = $this->capture($fixture['track'], $fixture['actor']);
            $this->assertSame($first->payload(), $second->payload());
            $this->assertSame($first->hash(), $second->hash());
            $this->assertSame($before, $this->evidence());
        } finally {
            ini_set('serialize_precision', $original);
        }
    }

    public function test_active_licenses_with_adjacent_nonoverlapping_effective_windows_cannot_be_accepted_at_different_clock_instants(): void
    {
        $this->freezeSecond();
        $start = CarbonImmutable::instance(now());
        $boundary = $start->addSeconds(10);
        $fixture = QuoteFixtures::selection();
        $actor = $fixture['actor'];
        $oldLicense = LicenseFixtures::published($actor, content: ['effective_from' => $start->subSeconds(100), 'effective_until' => $boundary]);
        app(SaveOfferDraft::class)->handle($fixture['offer'], ['license_version_id' => $oldLicense->id], $actor);
        app(PublishOffer::class)->handle($fixture['offer'], $actor);
        $nextLicense = LicenseFixtures::published($actor, content: ['effective_from' => $boundary, 'effective_until' => $boundary->addSeconds(100)]);
        $this->travelTo($boundary);
        $nextOffer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $fixture['track']->id, 'license_version_id' => $nextLicense->id,
            'price_minor' => 3456, 'currency' => 'USD', 'deliverable_asset_ids' => [$fixture['media']['master_wav']->id]], $actor);
        app(PublishOffer::class)->handle($nextOffer, $actor);
        $this->travelTo($start);
        $advanced = false;
        $inspect = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use ($nextLicense, $boundary, &$advanced, &$inspect): void {
            if ($inspect && ! $advanced && preg_match('/\bfrom\s+["`]?license_versions["`]?\b/i', $query)
                && in_array($nextLicense->id, $bindings, true)) {
                $advanced = true;
                // Advance only the isolated test clock at the genuine second-license query; services are unchanged.
                $this->travelTo($boundary);
            }
        });
        try {
            $this->assertSame([], app(PublicationReadiness::class)->blockers($fixture['track']->fresh()),
                'The ordinary successive now() checks must demonstrate that the two windows can straddle the boundary.');
            $this->assertTrue($advanced);
            $this->travelTo($start);
            $advanced = false;
            $before = $this->evidence();
            $this->invalid(fn () => $this->capture($fixture['track'], $actor));
            $this->assertTrue($advanced);
            $this->assertSame($before, $this->evidence());
        } finally {
            $inspect = false;
            $this->travelTo($start);
        }
    }
}
