<?php

namespace Tests\Feature\MediaLifecycle;

use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Media\Lifecycle\MediaLifecycleInventory;
use App\Domain\Media\Lifecycle\MediaReferenceProof;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

    private function prove(array $roots): array
    {
        return app(MediaReferenceProof::class)->prove($roots);
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
        $this->assertSame(['json:offer_revisions.snapshot'], $attached($revised));
        $this->assertSame(['publication:track'], $attached($published));
        $this->assertSame(['content:site_images'], $attached($shared));
        $this->assertSame('unattached', $result['families'][$free]['state']);
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

        $summary = app(MediaLifecycleInventory::class)->summary(30, CarbonImmutable::now());

        $this->assertSame(1, $summary['states']['in_progress']['families']);
        $this->assertSame(1, $summary['states']['failed_processing']['families']);
        $this->assertSame(2, $summary['states']['unattached']['families']);
        $this->assertSame(0, $summary['states']['attached']['families']);
        $this->assertSame((int) $queued->size_bytes, $summary['states']['in_progress']['recorded_bytes']);
        $this->assertSame([$master, $artwork], array_column($summary['candidates']['families'], 'id'));
        // Younger than the age: not proven, so not unattached.
        $younger = app(MediaLifecycleInventory::class)->summary(40, CarbonImmutable::now());
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
}
