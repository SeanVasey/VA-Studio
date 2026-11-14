<?php

namespace Tests\Feature\MediaLifecycle;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\TrackPublicationSchedules;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Media\Lifecycle\MediaLifecycleInventory;
use App\Domain\Media\Lifecycle\MediaReferenceProof;
use App\Domain\Media\Lifecycle\MediaReferenceRegistry;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * FP-034 tier 3: a completed media family is "unattached" only when every reference check finds nothing and coverage is
 * complete. Every kind of retained reference keeps it attached (or at least unknown); the report never changes anything.
 */
final class MediaReferenceProofTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->travelTo(now()->startOfSecond());
        $this->actor = LicenseFixtures::admin();
    }

    /**
     * Real processed media for a fresh draft track: [master family root, artwork family root or null, outputs by role, track].
     * Each family gets its own WAV bytes: identical bytes in two families are (conservatively) a reference between them.
     */
    private function family(string $slug, int $frequency = 440, bool $artwork = true): array
    {
        $track = Track::create(['title' => 'SYNTHETIC '.$slug, 'slug' => $slug]);
        $roots = [];
        foreach ($artwork ? ['master_wav', 'artwork'] : ['master_wav'] as $role) {
            $source = MediaFixtures::source($track, $role, $role === 'artwork' ? null : MediaFixtures::wav(1.2, $frequency));
            $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
            $this->assertSame('completed', app(MediaProcessor::class)->handle($run->id)->status);
            $roots[] = (int) $source->id;
        }
        $outputs = MediaAsset::where('track_id', $track->id)->whereNotNull('parent_asset_id')->get()->keyBy('role')->all();

        return [$roots[0], $roots[1] ?? null, $outputs, $track];
    }

    /** $extra registers the columns of a probe table, as a migration adding such a table would have to. */
    private function prove(array $roots, array $extra = []): array
    {
        return (new MediaReferenceProof(new MediaReferenceRegistry($extra)))->prove($roots);
    }

    private function snapshot(): array
    {
        $tables = [];
        foreach (['media_assets', 'media_processing_runs', 'media_upload_sessions', 'audit_events', 'offers', 'offer_revisions', 'tracks'] as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $files = Storage::disk('local')->allFiles();
        sort($files);

        return [$tables, $files];
    }

    public function test_an_unreferenced_completed_family_is_unattached_with_a_proof_its_own_lineage_and_audit_do_not_count(): void
    {
        [$master, $artwork, $outputs] = $this->family('synthetic-unreferenced');
        $before = $this->snapshot();

        $result = $this->prove([$master, $artwork]);

        $this->assertSame(['complete', []], [$result['coverage'], $result['blockers']]);
        foreach ([$master, $artwork] as $root) {
            $family = $result['families'][$root];
            $this->assertSame('unattached', $family['state']);
            $this->assertSame(0, array_sum($family['checks']));
            foreach (['fk:media_assets.parent_asset_id', 'fk:media_processing_runs.source_asset_id', 'fk:media_assets.processing_run_id',
                'fk:pending_entitlements.media_asset_id', 'fk:stems_recordings.master_asset_id', 'fk:media_upload_sessions.asset_id',
                'json:offers.deliverable_asset_ids', 'json:offer_revisions.snapshot', 'json:media_processing_runs.output_asset_ids',
                'content:audit_events', 'content:media_assets', 'content:offer_revisions', 'content:site_images', 'publication:track'] as $check) {
                $this->assertArrayHasKey($check, $family['checks']);
            }
        }
        $this->assertContains($outputs['master_wav']->id, $result['families'][$master]['members']);
        $this->assertContains($outputs['preview_tagged']->id, $result['families'][$master]['members']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_offer_deliverables_offer_revision_snapshots_publication_and_shared_bytes_keep_a_family_attached(): void
    {
        [$offered] = $this->family('synthetic-offered', 220, false);
        [$revised, , $revisedOutputs, $revisedTrack] = $this->family('synthetic-revised', 330, false);
        [$published, , , $publishedTrack] = $this->family('synthetic-published', 550, false);
        [, $shared] = $this->family('synthetic-shared', 660);
        [$free] = $this->family('synthetic-free', 770, false);
        $offeredMaster = MediaAsset::where('parent_asset_id', $offered)->where('role', 'master_wav')->value('id');
        $version = LicenseFixtures::published();
        DB::table('offers')->insert(['track_id' => MediaAsset::find($offered)->track_id, 'license_version_id' => $version->id,
            'price_minor' => 100, 'currency' => 'USD', 'deliverable_asset_ids' => json_encode([$offeredMaster]), 'is_active' => false,
            'created_at' => now(), 'updated_at' => now()]);
        // A frozen commercial revision that names the preview by id only (no hash): the JSON check alone must catch it.
        $rights = RightsDeclaration::create(['track_id' => $revisedTrack->id, 'provenance_reference' => 'SYNTHETIC', 'sample_disclosure' => 'None']);
        $offer = DB::table('offers')->insertGetId(['track_id' => $revisedTrack->id, 'license_version_id' => $version->id, 'price_minor' => 100,
            'currency' => 'USD', 'deliverable_asset_ids' => '[]', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('offer_revisions')->insert(['offer_id' => $offer, 'track_id' => $revisedTrack->id, 'license_version_id' => $version->id,
            'rights_declaration_id' => $rights->id, 'revision' => 1, 'price_minor' => 100, 'currency' => 'USD',
            'snapshot' => json_encode(['product' => ['id' => $revisedTrack->id], 'preview' => ['id' => $revisedOutputs['preview_tagged']->id]]),
            'snapshot_hash' => str_repeat('a', 64), 'canonicalization_version' => 'synthetic', 'published_by' => $this->actor->id, 'published_at' => now()]);
        DB::table('tracks')->where('id', $publishedTrack->id)->update(['status' => 'published', 'published_at' => now(), 'published_slug' => $publishedTrack->slug]);
        // A site image uploaded from the very same bytes as this artwork source (any state: the content check is state-agnostic).
        $source = MediaAsset::findOrFail($shared);
        DB::table('site_images')->insert(['slot' => 'share', 'original_name' => 'synthetic.png', 'source_path' => 'site-images/quarantine/'.Str::uuid().'/source',
            'source_sha256' => $source->sha256, 'size_bytes' => $source->size_bytes, 'mime_type' => 'image/png', 'width' => 1, 'height' => 1,
            'credit' => 'Synthetic', 'rights_confirmed_at' => now(), 'uploaded_by' => $this->actor->id, 'status' => 'quarantined',
            'created_at' => now(), 'updated_at' => now()]);

        $result = $this->prove([$offered, $revised, $published, $shared, $free]);

        $this->assertSame('complete', $result['coverage']);
        $attached = fn (int $root): array => array_keys(array_filter($result['families'][$root]['checks']));
        $this->assertSame('attached', $result['families'][$offered]['state']);
        $this->assertContains('json:offers.deliverable_asset_ids', $attached($offered));
        $this->assertSame('attached', $result['families'][$revised]['state']);
        // The frozen revision names the preview by id and, as a published commercial row, its track.
        $this->assertSame(['json:offer_revisions.snapshot', 'track:offer_revisions.track_id'], $attached($revised));
        $this->assertSame(['publication:track'], $attached($published));
        $this->assertSame(['content:site_images'], $attached($shared));
        $this->assertSame('unattached', $result['families'][$free]['state']);
    }

    public function test_a_pending_publication_schedule_keeps_a_never_published_tracks_families_attached_until_it_is_cancelled(): void
    {
        [$scheduled, , , $scheduledTrack] = $this->family('synthetic-scheduled', 240, false);
        [$cancelled, , , $cancelledTrack] = $this->family('synthetic-cancelled', 350, false);
        [$pending, , , $pendingTrack] = $this->family('synthetic-pending-draft', 460, false);
        [$none] = $this->family('synthetic-no-schedule', 570, false);
        $at = CarbonImmutable::now('UTC')->startOfMinute()->addMinutes(10)->setTimezone('America/Chicago')->format('Y-m-d\TH:i');
        $service = app(TrackPublicationSchedules::class);
        $schedule = function (Track $track) use ($service, $at): Track {
            $track->refresh();
            $service->schedule($track, $this->actor, $at, 'published', $track->metadata_version, $track->publication_version);

            return $track->refresh();
        };

        // Through the real service: the track is `scheduled`, was never published and holds a pending row.
        $scheduledTrack = $schedule($scheduledTrack);
        $this->assertSame(['scheduled', null, null], [$scheduledTrack->status, $scheduledTrack->published_at, $scheduledTrack->published_slug]);
        $cancelledTrack = $schedule($cancelledTrack);
        // A pending row on a track that is still `draft` (the trigger allows it): the schedule alone must count.
        DB::table('track_publication_schedules')->insert(['track_id' => $pendingTrack->id, 'target_status' => 'published',
            'publish_at' => now()->addHour()->startOfMinute(), 'entry_zone' => 'America/Chicago', 'entry_local' => '2030-01-01 00:00',
            'created_by' => $this->actor->id, 'created_at' => now(), 'state' => 'pending', 'pending_slot' => 1]);
        $this->assertSame('draft', $pendingTrack->refresh()->status);

        $result = $this->prove([$scheduled, $cancelled, $pending, $none]);
        $this->assertSame('complete', $result['coverage']);
        foreach ([$scheduled, $cancelled, $pending] as $root) {
            $this->assertSame(['attached', ['publication:track']], [$result['families'][$root]['state'],
                array_keys(array_filter($result['families'][$root]['checks']))], (string) $root);
        }
        $this->assertSame('unattached', $result['families'][$none]['state']);
        $this->assertSame(1, app(MediaReferenceProof::class)->structural([$scheduled])[$scheduled]['checks']['publication:track']);

        // Cancelling releases the track to draft and resolves the row: nothing was ever published, so nothing keeps the family.
        $service->cancel($cancelledTrack, $this->actor, $cancelledTrack->metadata_version, $cancelledTrack->publication_version);
        $this->assertSame(['draft', 'cancelled'], [$cancelledTrack->refresh()->status,
            DB::table('track_publication_schedules')->where('track_id', $cancelledTrack->id)->value('state')]);
        $result = $this->prove([$scheduled, $cancelled]);
        $this->assertSame(['attached', 'unattached'], [$result['families'][$scheduled]['state'], $result['families'][$cancelled]['state']]);
        $this->assertSame(0, array_sum($result['families'][$cancelled]['checks']));
    }

    public function test_a_purchased_and_contracted_asset_is_never_unattached_and_its_payload_is_not_changed(): void
    {
        ContractFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        ContractFixtures::paid($gateway);
        $before = ContractFixtures::retained();
        $entitled = MediaAsset::findOrFail(PendingEntitlement::orderBy('id')->firstOrFail()->media_asset_id);
        $roots = MediaAsset::whereNull('parent_asset_id')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $result = $this->prove(array_slice($roots, 0, MediaReferenceProof::MAX_PROOF_FAMILIES));

        $family = $result['families'][(int) $entitled->parent_asset_id];
        $this->assertSame('attached', $family['state']);
        $this->assertGreaterThan(0, $family['checks']['fk:pending_entitlements.media_asset_id']);
        foreach ($result['families'] as $root => $other) {
            $this->assertNotSame('unattached', $other['state'], 'Family '.$root.' of a sold catalog must not be proven unattached.');
        }
        $this->assertSame($before, ContractFixtures::retained());
    }

    public function test_ciphertext_anywhere_outside_the_allowlist_makes_an_unreferenced_family_unknown(): void
    {
        [$master] = $this->family('synthetic-opaque');
        // An encrypted payload (as support attachments, orders and grants store theirs) could name the asset invisibly.
        DB::table('jobs')->insert(['queue' => 'synthetic', 'payload' => json_encode(['data' => Crypt::encryptString(json_encode(['asset' => $master]))]),
            'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        // Allowlisted ciphertext (an enrolled authenticator) does not block on its own.
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $result = $this->prove([$master]);

        $this->assertSame(['incomplete', ['ciphertext:jobs']], [$result['coverage'], $result['blockers']]);
        $this->assertSame('unknown', $result['families'][$master]['state']);
        DB::table('jobs')->delete();
        $this->assertSame('unattached', $this->prove([$master])['families'][$master]['state']);
    }

    public function test_lifecycle_states_count_processing_failures_and_upload_sessions(): void
    {
        [$master, $artwork] = $this->family('synthetic-states');
        $track = Track::create(['title' => 'SYNTHETIC pending', 'slug' => 'synthetic-pending']);
        $queued = MediaFixtures::source($track, 'artwork', 'synthetic queued source bytes');
        DB::table('media_processing_runs')->insert(['source_asset_id' => $queued->id, 'requested_by' => $this->actor->id, 'profile_version' => 'synthetic',
            'profile_fingerprint' => str_repeat('b', 64), 'input_sha256' => $queued->sha256, 'profile' => '{}', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $failed = MediaFixtures::source($track, 'artwork', 'synthetic failed source bytes');
        DB::table('media_processing_runs')->insert(['source_asset_id' => $failed->id, 'requested_by' => $this->actor->id, 'profile_version' => 'synthetic',
            'profile_fingerprint' => str_repeat('c', 64), 'input_sha256' => $failed->sha256, 'profile' => '{}', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        $this->travel(31)->days();

        $inventory = app(MediaLifecycleInventory::class);
        // Without an explicit proof the cheap counts never call a family unattached.
        $cheap = $inventory->summary(30, CarbonImmutable::now());
        $this->assertSame(0, $cheap['states']['unattached']['families']);
        $this->assertSame(2, $cheap['states']['unknown']['families']);
        $this->assertNull($cheap['candidates']);
        $proof = $inventory->candidates(30, CarbonImmutable::now(), MediaReferenceProof::MAX_PROOF_FAMILIES);
        $summary = $inventory->summary(30, CarbonImmutable::now(), $proof);

        $this->assertSame(1, $summary['states']['in_progress']['families']);
        $this->assertSame(1, $summary['states']['failed_processing']['families']);
        $this->assertSame(2, $summary['states']['unattached']['families']);
        $this->assertSame(0, $summary['states']['attached']['families']);
        $this->assertSame((int) $queued->size_bytes, $summary['states']['in_progress']['recorded_bytes']);
        $this->assertSame([$master, $artwork], array_column($summary['candidates']['families'], 'id'));
        // Younger than the age: not proven, so not unattached.
        $younger = $inventory->summary(40, CarbonImmutable::now(), $inventory->candidates(40, CarbonImmutable::now(), 10));
        $this->assertSame(0, $younger['states']['unattached']['families']);
        $this->assertSame(2, $younger['states']['unknown']['families']);
        $this->assertSame(['active', 'expired', 'cleanup_pending'], array_keys($summary['sessions']['media']));
    }

    public function test_the_report_command_is_a_dry_run_that_prints_ids_and_the_proof_only(): void
    {
        [$master, $artwork] = $this->family('synthetic-report');
        $this->travel(31)->days();
        $before = $this->snapshot();

        $exit = Artisan::call('vasey:media-unattached-report', ['--older-than-days' => 30, '--proof' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Dry run: nothing was changed.', $output);
        $this->assertStringContainsString('Coverage: complete', $output);
        $this->assertMatchesRegularExpression('/^#'.$master.' unattached track #\d+ assets /m', $output);
        $this->assertMatchesRegularExpression('/^#'.$artwork.' unattached /m', $output);
        $this->assertMatchesRegularExpression('/^  proof: fk:media_assets\.parent_asset_id .*json:offers\.deliverable_asset_ids .*publication:track .*content:audit_events .*content:support_attachments /m', $output);
        $this->assertStringContainsString('Unattached: 2.', $output);
        foreach (MediaAsset::all() as $asset) {
            $this->assertStringNotContainsString($asset->storage_path, $output);
            $this->assertStringNotContainsString($asset->sha256, $output);
        }
        $this->assertStringNotContainsString('SYNTHETIC synthetic-report', $output);
        $this->assertSame($before, $this->snapshot());

        $exit = Artisan::call('vasey:media-unattached-report', ['--older-than-days' => 40]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Unattached: 0.', Artisan::output());
        $this->assertSame(2, Artisan::call('vasey:media-unattached-report', ['--limit' => 0]));
    }

    public function test_hash_only_manifests_unreadable_types_and_polymorphic_pairs_are_checked_and_one_proof_is_bounded(): void
    {
        [$master, , $outputs] = $this->family('synthetic-fences', 880, false);
        Schema::create('fp034_probe', function (Blueprint $table): void {
            $table->id();
            $table->char('asset_manifest_hash', 64)->nullable();
            $table->binary('payload')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
        });
        $extra = ['manifest_digests' => ['fp034_probe.asset_manifest_hash' => 'digest'], 'polymorphic' => ['fp034_probe.subject' => 'poly']];
        $this->assertSame(['complete', 'unattached'], [$this->prove([$master], $extra)['coverage'], $this->prove([$master], $extra)['families'][$master]['state']]);

        DB::table('fp034_probe')->insert(['asset_manifest_hash' => str_repeat('d', 64)]);
        $this->assertSame(['hash_only_manifest:fp034_probe.asset_manifest_hash'], $this->prove([$master], $extra)['blockers']);
        $this->assertSame('unknown', $this->prove([$master], $extra)['families'][$master]['state']);
        DB::table('fp034_probe')->delete();

        DB::table('fp034_probe')->insert(['payload' => "\x00synthetic"]);
        $this->assertSame(['uninspected_type:fp034_probe.payload'], $this->prove([$master], $extra)['blockers']);
        DB::table('fp034_probe')->delete();

        DB::table('fp034_probe')->insert(['subject_type' => MediaAsset::class, 'subject_id' => $outputs['download_mp3']->id]);
        $result = $this->prove([$master], $extra);
        $this->assertSame('complete', $result['coverage']);
        $this->assertSame('attached', $result['families'][$master]['state']);
        $this->assertSame(1, $result['families'][$master]['checks']['poly:fp034_probe.subject']);

        $this->expectException(\InvalidArgumentException::class);
        $this->prove(range(1, MediaReferenceProof::MAX_PROOF_FAMILIES + 1));
    }

    public function test_json_escaped_paths_count_and_too_many_matching_rows_make_coverage_incomplete(): void
    {
        [$master, , $outputs] = $this->family('synthetic-bounds', 990, false);
        Schema::create('fp034_notes', function (Blueprint $table): void {
            $table->id();
            $table->text('note');
        });
        $note = json_encode(['file' => MediaAsset::findOrFail($outputs['master_wav']->id)->storage_path]);
        $this->assertStringContainsString('\\/', $note);
        DB::table('fp034_notes')->insert(['note' => $note]);
        $extra = ['reviewed' => ['fp034_notes.note' => 'A synthetic note: only the content check reads it.']];
        $result = $this->prove([$master], $extra);
        $this->assertSame('attached', $result['families'][$master]['state']);
        $this->assertSame(1, $result['families'][$master]['checks']['content:fp034_notes']);

        DB::table('fp034_notes')->delete();
        $sha = MediaAsset::findOrFail($master)->sha256;
        foreach (array_chunk(range(1, MediaReferenceProof::MAX_CONTENT_ROWS + 1), 200) as $chunk) {
            DB::table('fp034_notes')->insert(array_map(fn (int $i): array => ['note' => 'synthetic '.$i.' '.$sha], $chunk));
        }
        $result = $this->prove([$master], $extra);
        $this->assertSame(['incomplete', ['content_bound:fp034_notes']], [$result['coverage'], $result['blockers']]);
        $this->assertNotSame('unattached', $result['families'][$master]['state']);
    }
}
