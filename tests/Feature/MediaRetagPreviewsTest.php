<?php

namespace Tests\Feature;

use App\Console\Commands\MediaRetagPreviews;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\RetagPreviews;
use App\Domain\Media\StagingSellerTag;
use App\Domain\Media\VerifiedMedia;
use App\Jobs\ProcessMedia;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/**
 * `vasey:media-retag-previews`: selection by the preview's own recorded tag hash, fresh runs through the unchanged
 * processor, draft-only handling, idempotent replay, and refusals before any write.
 */
class MediaRetagPreviewsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'SyntheticRetag-Pass-5512';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        $this->actor->forceFill(['password' => Hash::make(self::PASSWORD)])->save();
    }

    /** Installs a tag under the private disk and makes it the configured one; returns its SHA-256. */
    private function useTag(string $path, int $frequency): string
    {
        $bytes = MediaFixtures::wav(0.2, $frequency);
        Storage::disk('local')->put($path, $bytes);
        config(['media.tag_path' => $path, 'media.tag_sha256' => hash('sha256', $bytes)]);

        return hash('sha256', $bytes);
    }

    private function track(string $slug, string $status = 'draft'): Track
    {
        $track = Track::create(['title' => 'Synthetic '.$slug, 'slug' => $slug]);
        if ($status !== 'draft') {
            DB::table('tracks')->where('id', $track->id)->update(['status' => $status, 'published_slug' => $status === 'published' ? $slug : null]);
        }

        return $track->fresh();
    }

    /** Processes a fresh master for the track under the currently configured tag. */
    private function processed(Track $track): MediaProcessingRun
    {
        DB::table('tracks')->where('id', $track->id)->update(['status' => 'draft']);
        $run = app(QueueMediaProcessing::class)->handle(MediaFixtures::source($track), $this->actor);
        $done = app(MediaProcessor::class)->handle($run->id);
        // Setup dispatches are not under test: start counting from a clean fake.
        Queue::fake();

        return $done;
    }

    private function preview(Track $track): ?MediaAsset
    {
        return $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->first();
    }

    private function retag(array $options = [], bool $password = true): PendingCommand
    {
        $pending = $this->artisan('vasey:media-retag-previews', $options + ['--actor' => $this->actor->email]);
        if ($password && ! isset($options['--check'])) {
            $pending->expectsQuestion(MediaRetagPreviews::PASSWORD_PROMPT, self::PASSWORD)->doesntExpectOutputToContain(self::PASSWORD);
        }

        return $pending;
    }

    private function census(): array
    {
        $tables = ['media_assets', 'media_processing_runs', 'audit_events', 'tracks'];

        return array_combine($tables, array_map(fn (string $table): int => DB::table($table)->count(), $tables))
            + ['files' => count(Storage::disk('local')->allFiles('media'))];
    }

    private function assertRefused(string $message, array $options = [], bool $password = false): void
    {
        $before = $this->census();
        $this->retag($options, $password)->expectsOutputToContain($message)->assertExitCode(1);
        $this->assertSame($before, $this->census());
        Queue::assertNotPushed(ProcessMedia::class);
    }

    private function inEnvironment(string $detected, Closure $probe, ?string $configured = null): void
    {
        $this->app->detectEnvironment(fn (): string => $detected);
        config(['app.env' => $configured ?? $detected]);
        try {
            $probe();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
            config(['app.env' => 'testing']);
        }
    }

    public function test_check_lists_only_stale_draft_previews_and_writes_nothing(): void
    {
        $old = $this->useTag('approved-tags/old-tag.wav', 700);
        $stale = $this->track('stale');
        $live = $this->track('live');
        $private = $this->track('private');
        $this->processed($stale);
        $this->processed($live);
        $this->processed($private);
        DB::table('tracks')->where('id', $live->id)->update(['status' => 'published', 'published_slug' => 'live']);
        DB::table('tracks')->where('id', $private->id)->update(['status' => 'private']);
        $new = $this->useTag('approved-tags/new-tag.wav', 900);
        $fresh = $this->track('fresh');
        $this->processed($fresh);
        $this->assertNotSame($old, $new);
        $before = $this->census();

        $this->retag(['--check' => true])
            ->expectsOutputToContain('current=1 stale_draft=1')
            ->expectsOutputToContain('track #'.$stale->id.' (stale) new')
            ->expectsOutputToContain('skipped_live=1 tracks #'.$live->id)
            ->expectsOutputToContain('skipped_not_draft=1 tracks #'.$private->id)
            ->expectsOutputToContain('Checked only. Nothing was written.')
            ->assertSuccessful();

        $this->assertSame($before, $this->census());
        Queue::assertNotPushed(ProcessMedia::class, fn () => true);
    }

    public function test_a_write_runs_a_fresh_processing_run_and_the_new_preview_carries_the_new_tag(): void
    {
        $old = $this->useTag('approved-tags/old-tag.wav', 700);
        $track = $this->track('retag-me');
        $first = $this->processed($track);
        $oldPreview = $this->preview($track);
        $this->assertSame($old, $oldPreview->technical_metadata['tag_sha256']);
        $oldHash = $oldPreview->sha256;
        $new = $this->useTag('approved-tags/new-tag.wav', 900);
        // The old preview is still a valid verified revision: this is the gap the command closes.
        $this->assertTrue(app(VerifiedMedia::class)->available($oldPreview));

        $this->retag()->expectsOutputToContain('queued=1 in_flight=0 refused=0')->assertSuccessful();

        $run = MediaProcessingRun::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $run->id);
        $this->assertSame($first->source_asset_id, $run->source_asset_id);
        $this->assertSame($new, $run->profile['tag_sha256']);
        Queue::assertPushed(ProcessMedia::class, fn (ProcessMedia $job) => $job->runId === $run->id);
        $done = app(MediaProcessor::class)->handle($run->id);

        $this->assertSame('completed', $done->status);
        $current = $this->preview($track);
        $this->assertNotSame($oldPreview->id, $current->id);
        $this->assertSame($new, $current->technical_metadata['tag_sha256']);
        $this->assertTrue(app(VerifiedMedia::class)->available($current));
        // Old derivatives are untouched evidence, but no longer the track's current revision.
        $this->assertSame($oldHash, $oldPreview->fresh()->sha256);
        $this->assertSame(1, $track->assets()->where('role', 'preview_tagged')->where('technical_metadata->tag_sha256', $old)->count());
        $this->assertSame('draft', $track->fresh()->status);

        $this->retag(['--check' => true])->expectsOutputToContain('current=1 stale_draft=0')->assertSuccessful();
    }

    public function test_replay_never_queues_or_processes_twice(): void
    {
        $this->useTag('approved-tags/old-tag.wav', 700);
        $track = $this->track('replay');
        $this->processed($track);
        $this->useTag('approved-tags/new-tag.wav', 900);

        $this->retag()->expectsOutputToContain('queued=1 in_flight=0')->assertSuccessful();
        $runs = MediaProcessingRun::count();
        // The run is queued and fresh: a second invocation leaves it alone.
        $this->retag()->expectsOutputToContain('queued=0 in_flight=1')->assertSuccessful();
        $this->assertSame($runs, MediaProcessingRun::count());
        Queue::assertPushed(ProcessMedia::class, 1);

        app(MediaProcessor::class)->handle((int) MediaProcessingRun::query()->latest('id')->value('id'));
        $outputs = MediaAsset::where('status', 'ready')->count();
        // Once processed, the track is current: nothing to queue, nothing duplicated.
        $this->retag()->expectsOutputToContain('queued=0 in_flight=0')->assertSuccessful();
        $this->assertSame($outputs, MediaAsset::where('status', 'ready')->count());
        $this->assertSame($runs, MediaProcessingRun::count());
        Queue::assertPushed(ProcessMedia::class, 1);
    }

    public function test_an_abandoned_queued_run_is_redispatched_and_a_failed_run_is_retried(): void
    {
        $this->useTag('approved-tags/old-tag.wav', 700);
        $track = $this->track('recover');
        $this->processed($track);
        $this->useTag('approved-tags/new-tag.wav', 900);
        $this->retag()->assertSuccessful();
        $run = MediaProcessingRun::query()->latest('id')->firstOrFail();

        // Past the claim lease a queued run counts as lost and is dispatched again, on the same run.
        MediaProcessingRun::query()->whereKey($run->id)->update(['updated_at' => now()->subSeconds(config('media.claim_lease_seconds') + 5)]);
        $this->retag()->expectsOutputToContain('queued=1 in_flight=0')->assertSuccessful();
        $this->assertSame(1, MediaProcessingRun::where('source_asset_id', $run->source_asset_id)->where('profile_fingerprint', $run->profile_fingerprint)->count());
        Queue::assertPushed(ProcessMedia::class, 2);

        MediaProcessingRun::query()->whereKey($run->id)->update(['status' => 'failed', 'failure_code' => 'scan_not_clean', 'failed_at' => now()]);
        $this->retag(['--check' => true])->expectsOutputToContain('track #'.$track->id.' (recover) retry')->assertSuccessful();
        $this->retag()->expectsOutputToContain('queued=1')->assertSuccessful();
        $this->assertSame('queued', $run->fresh()->status);
        Queue::assertPushed(ProcessMedia::class, 3);
    }

    public function test_live_and_non_draft_tracks_are_reported_and_never_altered(): void
    {
        $this->useTag('approved-tags/old-tag.wav', 700);
        $live = $this->track('live');
        $unlisted = $this->track('unlisted');
        $private = $this->track('private');
        foreach ([$live, $unlisted, $private] as $track) {
            $this->processed($track);
        }
        DB::table('tracks')->where('id', $live->id)->update(['status' => 'published', 'published_slug' => 'live']);
        DB::table('tracks')->where('id', $unlisted->id)->update(['status' => 'unlisted', 'published_slug' => 'unlisted']);
        DB::table('tracks')->where('id', $private->id)->update(['status' => 'private']);
        $this->useTag('approved-tags/new-tag.wav', 900);
        $previews = [$live->id => $this->preview($live)->id, $unlisted->id => $this->preview($unlisted)->id, $private->id => $this->preview($private)->id];
        $before = $this->census();

        $this->retag()->expectsOutputToContain('queued=0 in_flight=0 refused=0')->expectsOutputToContain('skipped_live=2')->expectsOutputToContain('skipped_not_draft=1')->assertSuccessful();

        $this->assertSame(['published', 'unlisted', 'private'], [$live->fresh()->status, $unlisted->fresh()->status, $private->fresh()->status]);
        foreach ([$live, $unlisted, $private] as $track) {
            $this->assertSame($previews[$track->id], $this->preview($track)->id);
        }
        $this->assertSame($before['media_assets'], MediaAsset::count());
        $this->assertSame($before['media_processing_runs'], MediaProcessingRun::count());
        Queue::assertNotPushed(ProcessMedia::class, fn () => true);
    }

    public function test_a_track_published_after_planning_is_refused_by_the_queue_not_retagged(): void
    {
        $this->useTag('approved-tags/old-tag.wav', 700);
        $track = $this->track('race');
        $this->processed($track);
        $this->useTag('approved-tags/new-tag.wav', 900);
        $retag = app(RetagPreviews::class);
        $plan = $retag->plan($retag->configuredTag());
        $this->assertCount(1, $plan['stale']);
        DB::table('tracks')->where('id', $track->id)->update(['status' => 'published', 'published_slug' => 'race']);

        $result = $retag->queue($this->actor, $plan);

        $this->assertSame([$track->id], $result['refused']);
        $this->assertSame([], $result['queued']);
        Queue::assertNotPushed(ProcessMedia::class, fn () => true);
    }

    public function test_a_write_audits_the_actor_without_any_path(): void
    {
        $this->useTag('approved-tags/old-tag.wav', 700);
        $track = $this->track('audited');
        $this->processed($track);
        $new = $this->useTag('approved-tags/new-tag.wav', 900);

        $this->retag()->assertSuccessful();

        $event = AuditEvent::query()->where('action', 'media.retag_previews.requested')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame($new, $event->context['tag_sha256']);
        $this->assertCount(1, $event->context['queued_run_ids']);
        $encoded = json_encode($event->context);
        $this->assertStringNotContainsString('approved-tags', $encoded);
        $this->assertStringNotContainsString('quarantine', $encoded);
        $this->assertStringNotContainsString('media/revisions', $encoded);
        // One queued event from the fixture's own processing and one from the retag, both attributed to the actor.
        $this->assertSame(2, AuditEvent::query()->where('action', 'media.processing.queued')->where('actor_id', $this->actor->id)->count());
    }

    public function test_production_and_ambiguous_environments_are_refused_for_write_and_check(): void
    {
        $this->useTag('approved-tags/new-tag.wav', 900);
        $this->inEnvironment('production', function (): void {
            $this->assertRefused('refused in production');
            $this->assertRefused('refused in production', ['--check' => true]);
        });
        $this->inEnvironment('staging', fn () => $this->assertRefused('refused in production'), 'production');
        foreach ([['staging', 'testing'], ['testing', 'staging']] as [$detected, $configured]) {
            $this->inEnvironment($detected, fn () => $this->assertRefused('running environment differ'), $configured);
        }
        foreach (['development', 'Staging', 'staging-eu', ''] as $environment) {
            $this->inEnvironment($environment, fn () => $this->assertRefused('runs only when APP_ENV is local, testing or staging'));
        }
    }

    public function test_the_tag_configuration_must_be_usable_and_not_the_staging_placeholder(): void
    {
        config(['media.tag_path' => null, 'media.tag_sha256' => null]);
        $this->assertRefused('must be configured first');
        $sha = $this->useTag('approved-tags/new-tag.wav', 900);
        config(['media.tag_sha256' => 'ABC']);
        $this->assertRefused('must be configured first');
        config(['media.tag_sha256' => str_repeat('0', 64)]);
        $this->assertRefused('does not match MEDIA_TAG_SHA256');
        config(['media.tag_sha256' => $sha, 'media.tag_path' => 'approved-tags/missing.wav']);
        $this->assertRefused('missing or not a safe private file');
        config(['media.tag_path' => '../outside.wav']);
        $this->assertRefused('missing or not a safe private file');
        // The placeholder is refused by path and by content, even where the processor would admit it (staging, testing).
        $placeholder = $this->useTag(StagingSellerTag::PATH, 900);
        $this->assertTrue(StagingSellerTag::admitted());
        $this->assertRefused('synthetic staging placeholder');
        $this->useTag('approved-tags/innocent-name.wav', 900);
        Storage::disk('local')->put('approved-tags/innocent-name.wav', MediaFixtures::wav(0.2, 900).StagingSellerTag::MARKER);
        config(['media.tag_sha256' => hash('sha256', MediaFixtures::wav(0.2, 900).StagingSellerTag::MARKER)]);
        $this->assertRefused('synthetic staging placeholder');
        $this->assertNotSame($placeholder, '');
    }

    public function test_the_actor_and_password_are_required_for_a_write(): void
    {
        $this->useTag('approved-tags/old-tag.wav', 700);
        $track = $this->track('guarded');
        $this->processed($track);
        $this->useTag('approved-tags/new-tag.wav', 900);

        $this->assertRefused('Give --actor', ['--actor' => '']);
        $this->assertRefused('No staff account has that --actor email', ['--actor' => 'nobody@example.test']);
        $this->assertRefused('lacks catalog authority', ['--actor' => User::factory()->create()->email]);
        $this->actor->forceFill(['is_admin' => false])->save();
        $this->assertRefused('lacks catalog authority');
        $this->actor->forceFill(['is_admin' => true])->save();

        // A wrong password, and a session that cannot prompt, write nothing.
        $before = $this->census();
        $this->artisan('vasey:media-retag-previews', ['--actor' => $this->actor->email])
            ->expectsQuestion(MediaRetagPreviews::PASSWORD_PROMPT, 'wrong-password')->expectsOutputToContain('password was not confirmed')->assertExitCode(1);
        $this->artisan('vasey:media-retag-previews', ['--actor' => $this->actor->email, '--no-interaction' => true])
            ->expectsOutputToContain('requires an interactive session')->assertExitCode(1);
        $this->assertSame($before, $this->census());
        Queue::assertNotPushed(ProcessMedia::class, fn () => true);
    }

    public function test_staging_admits_a_write_only_for_a_staff_actor_that_satisfies_mfa(): void
    {
        $this->useTag('approved-tags/old-tag.wav', 700);
        $track = $this->track('staged');
        $this->processed($track);
        $this->useTag('approved-tags/new-tag.wav', 900);

        $this->inEnvironment('staging', function () use ($track): void {
            $this->assertRefused("does not satisfy the admin panel's MFA rule");
            $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
            $this->assertInstanceOf(AppAuthentication::class, $provider);
            $this->actor->saveAppAuthenticationSecret($provider->generateSecret());
            // Staging past the gates. The test-only scanner evidence is accepted in `testing` alone, so this fixture's
            // preview reads as unverified here and is reported rather than reprocessed: the plan never trusts what it cannot verify.
            $this->retag(['--check' => true])->expectsOutputToContain('skipped_unverified=1 tracks #'.$track->id)->assertSuccessful();
            $this->retag()->expectsOutputToContain('queued=0 in_flight=0 refused=0')->assertSuccessful();
        });
        Queue::assertNotPushed(ProcessMedia::class, fn () => true);
        $this->assertSame(1, AuditEvent::query()->where('action', 'media.retag_previews.requested')->count());
    }
}
