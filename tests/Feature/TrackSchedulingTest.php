<?php

namespace Tests\Feature;

use App\Application\Media\MediaIntegrity;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\Models\TrackPublicationSchedule;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\TrackPublicationSchedules;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/**
 * Scheduled track publication (BL-41, FP-032, DD-39, SQ-15). The runner refuses outer transactions, so fixtures are committed
 * and the schema is rebuilt per test.
 */
class TrackSchedulingTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A ready, offer-backed track returned to draft, plus its MFA-enrolled operator. */
    private function draft(): array
    {
        $fixture = QuoteFixtures::selection();
        $fixture['track'] = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);

        return $fixture;
    }

    /** MediaIntegrity's cache key for this asset and the file's current identity. */
    private function availabilityKey(MediaAsset $asset, string $path): string
    {
        $integrity = app(MediaIntegrity::class);
        $identity = (new ReflectionMethod($integrity, 'identity'))->invoke($integrity, $asset, $path);
        $this->assertIsArray($identity);

        return 'media-integrity:'.(new ReflectionMethod($integrity, 'digest'))->invoke($integrity, $asset, $path, $identity);
    }

    private function chicago(CarbonImmutable $utc): string
    {
        return $utc->setTimezone('America/Chicago')->format('Y-m-d\TH:i');
    }

    /** Schedules the draft ten minutes ahead and returns [schedule, fixture, utcInstant]. */
    private function scheduled(string $target = 'published'): array
    {
        $fixture = $this->draft();
        $at = CarbonImmutable::now('UTC')->startOfMinute()->addMinutes(10);
        $schedule = app(TrackPublicationSchedules::class)->schedule(
            $fixture['track'], $fixture['actor'], $this->chicago($at), $target,
            $fixture['track']->metadata_version, $fixture['track']->publication_version,
        );

        return [$schedule, $fixture, $at];
    }

    private function runCommand(): array
    {
        $code = Artisan::call('vasey:publish-scheduled-tracks');

        return [$code, trim(Artisan::output())];
    }

    // ---- Time zones -----------------------------------------------------------------------------------------------------

    public static function chicagoInstants(): array
    {
        return [
            'standard time (CST, UTC-6)' => ['2026-12-01T09:30', '2026-12-01 15:30:00'],
            'daylight time (CDT, UTC-5)' => ['2027-07-01T09:30', '2027-07-01 14:30:00'],
            'last minute before spring forward' => ['2027-03-14T01:59', '2027-03-14 07:59:00'],
            'first minute after spring forward' => ['2027-03-14T03:00', '2027-03-14 08:00:00'],
            'last unambiguous minute before fall back' => ['2026-11-01T00:59', '2026-11-01 05:59:00'],
            'first minute after the repeated hour' => ['2026-11-01T02:00', '2026-11-01 08:00:00'],
            'midnight at a year boundary' => ['2027-01-01T00:00', '2027-01-01 06:00:00'],
            'space separator is accepted' => ['2026-12-01 09:30', '2026-12-01 15:30:00'],
        ];
    }

    #[DataProvider('chicagoInstants')]
    public function test_chicago_entry_converts_to_the_utc_instant(string $local, string $utc): void
    {
        $this->assertSame($utc, TrackPublicationSchedules::utcFromChicago($local)->utc()->format('Y-m-d H:i:s'));
    }

    public static function unusableLocalTimes(): array
    {
        return [
            'inside the skipped spring-forward hour' => ['2027-03-14T02:30'],
            'start of the skipped hour' => ['2027-03-14T02:00'],
            'first pass of the repeated fall-back hour' => ['2026-11-01T01:30'],
            'start of the repeated hour' => ['2026-11-01T01:00'],
            'last minute of the repeated hour' => ['2026-11-01T01:59'],
            'impossible date' => ['2026-02-30T10:00'],
            'impossible hour' => ['2026-12-01T24:00'],
            'with seconds' => ['2026-12-01T09:30:15'],
            'with an offset' => ['2026-12-01T09:30-06:00'],
            'with a zone suffix' => ['2026-12-01T09:30Z'],
            'date only' => ['2026-12-01'],
            'empty' => [''],
            'free text' => ['tomorrow morning'],
        ];
    }

    #[DataProvider('unusableLocalTimes')]
    public function test_a_local_time_that_does_not_exist_or_repeats_is_refused_rather_than_guessed(string $local): void
    {
        $this->expectException(ValidationException::class);
        TrackPublicationSchedules::utcFromChicago($local);
    }

    public function test_the_stored_instant_is_utc_and_the_entry_is_kept_for_the_audit_trail(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $this->assertSame($at->format('Y-m-d H:i:s'), DB::table('track_publication_schedules')->where('id', $schedule->id)->value('publish_at'));
        $this->assertSame($this->chicago($at), str_replace(' ', 'T', $schedule->entry_local));
        $this->assertSame('America/Chicago', $schedule->entry_zone);
        $event = AuditEvent::where('action', 'catalog.track.scheduled')->sole();
        $this->assertSame($at->toIso8601ZuluString(), $event->context['publish_at']);
        $this->assertSame($fixture['actor']->id, $event->actor_id);
    }

    public function test_a_schedule_must_be_a_minute_ahead_and_within_the_horizon(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        $now = CarbonImmutable::now('UTC');
        $service = app(TrackPublicationSchedules::class);
        foreach ([$now->subHour(), $now, $now->addSeconds(20)] as $past) {
            try {
                $service->schedule($track, $fixture['actor'], $this->chicago($past), 'published', $track->metadata_version, $track->publication_version);
                $this->fail('A time that is not at least a minute ahead was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('publish_at', $exception->errors());
            }
        }
        $this->expectException(ValidationException::class);
        $service->schedule($track, $fixture['actor'], $this->chicago($now->addDays(TrackPublicationSchedules::MAX_DAYS_AHEAD + 2)), 'published', $track->metadata_version, $track->publication_version);
    }

    // ---- Scheduling ------------------------------------------------------------------------------------------------------

    public function test_scheduling_moves_a_draft_to_scheduled_with_one_pending_row_and_an_audit_event(): void
    {
        [$schedule, $fixture] = $this->scheduled('unlisted');
        $track = $fixture['track']->fresh();
        $this->assertSame('scheduled', $track->status);
        $this->assertSame($fixture['track']->publication_version + 1, $track->publication_version);
        $this->assertSame(['pending', 1, 'unlisted'], [$schedule->state, $schedule->pending_slot, $schedule->target_status]);
        $this->assertSame(1, TrackPublicationSchedule::where('state', 'pending')->count());
        $event = AuditEvent::where('action', 'catalog.track.scheduled')->sole();
        $this->assertSame($track->publication_version, $event->context['publication_version']);
        $this->assertNull($event->context['replaced_schedule_id']);
    }

    public function test_rescheduling_supersedes_the_pending_row_and_keeps_exactly_one_pending(): void
    {
        [$first, $fixture] = $this->scheduled();
        $track = $fixture['track']->fresh();
        $later = CarbonImmutable::now('UTC')->startOfMinute()->addHours(3);
        $second = app(TrackPublicationSchedules::class)->schedule($track, $fixture['actor'], $this->chicago($later), 'unlisted', $track->metadata_version, $track->publication_version);
        $this->assertSame(['superseded', 'rescheduled', null], [$first->fresh()->state, $first->fresh()->outcome, $first->fresh()->pending_slot]);
        $this->assertSame(1, TrackPublicationSchedule::where('state', 'pending')->count());
        $this->assertSame($second->id, TrackPublicationSchedule::where('state', 'pending')->sole()->id);
        $this->assertSame('scheduled', $track->fresh()->status);
        $this->assertSame($first->id, AuditEvent::where('action', 'catalog.track.scheduled')->latest('id')->first()->context['replaced_schedule_id']);
    }

    public function test_cancelling_returns_the_track_to_draft_and_the_instant_never_publishes(): void
    {
        [$schedule, $fixture] = $this->scheduled();
        $track = $fixture['track']->fresh();
        app(TrackPublicationSchedules::class)->cancel($track, $fixture['actor'], $track->metadata_version, $track->publication_version);
        $this->assertSame('draft', $track->fresh()->status);
        $this->assertSame(['cancelled', 'cancelled', null], [$schedule->fresh()->state, $schedule->fresh()->outcome, $schedule->fresh()->pending_slot]);
        $this->travelTo(CarbonImmutable::now('UTC')->addMinutes(30));
        $this->assertSame([0, 'NOTHING_DUE'], $this->runCommand());
        $this->assertSame('draft', $track->fresh()->status);
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.schedule_cancelled')->count());
    }

    public function test_a_stale_editor_cannot_schedule_reschedule_or_cancel(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        $service = app(TrackPublicationSchedules::class);
        $at = $this->chicago(CarbonImmutable::now('UTC')->addMinutes(30));
        foreach ([[$track->metadata_version + 1, $track->publication_version], [$track->metadata_version, $track->publication_version + 1]] as [$metadata, $publication]) {
            try {
                $service->schedule($track, $fixture['actor'], $at, 'published', $metadata, $publication);
                $this->fail('A stale schedule request was accepted.');
            } catch (ValidationException) {
                $this->assertSame('draft', $track->fresh()->status);
            }
        }
        $schedule = $service->schedule($track, $fixture['actor'], $at, 'published', $track->metadata_version, $track->publication_version);
        $current = $track->fresh();
        // The editor still holds the pre-schedule versions.
        foreach (['schedule', 'cancel'] as $operation) {
            try {
                $operation === 'cancel'
                    ? $service->cancel($current, $fixture['actor'], $track->metadata_version, $track->publication_version)
                    : $service->schedule($current, $fixture['actor'], $at, 'published', $track->metadata_version, $track->publication_version);
                $this->fail('A stale '.$operation.' was accepted.');
            } catch (ValidationException) {
                $this->assertSame(['scheduled', 'pending'], [$current->fresh()->status, $schedule->fresh()->state]);
            }
        }
    }

    public function test_only_a_draft_can_be_scheduled(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        $at = $this->chicago(CarbonImmutable::now('UTC')->addMinutes(30));
        DB::table('tracks')->where('id', $track->id)->update(['status' => 'private']);
        $private = $track->fresh();
        try {
            app(TrackPublicationSchedules::class)->schedule($private, $fixture['actor'], $at, 'published', $private->metadata_version, $private->publication_version);
            $this->fail('A private track was scheduled.');
        } catch (ValidationException) {
            $this->assertSame('private', $track->fresh()->status);
        }
        $this->assertSame(0, TrackPublicationSchedule::count());
    }

    public function test_an_unknown_target_state_is_refused(): void
    {
        $fixture = $this->draft();
        $this->expectException(ValidationException::class);
        app(TrackPublicationSchedules::class)->schedule($fixture['track'], $fixture['actor'], $this->chicago(CarbonImmutable::now('UTC')->addHour()), 'private', $fixture['track']->metadata_version, $fixture['track']->publication_version);
    }

    public function test_scheduling_requires_a_current_catalog_administrator_with_mfa(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        $at = $this->chicago(CarbonImmutable::now('UTC')->addMinutes(30));
        $outsider = User::factory()->create();
        $outsider->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->expectException(AuthorizationException::class);
        app(TrackPublicationSchedules::class)->schedule($track, $outsider, $at, 'published', $track->metadata_version, $track->publication_version);
    }

    public function test_a_scheduled_track_cannot_be_published_or_unpublished_around_its_schedule(): void
    {
        [, $fixture] = $this->scheduled();
        $track = $fixture['track']->fresh();
        foreach (['handle', 'unpublish'] as $method) {
            try {
                app(PublishTrack::class)->{$method}($track, $fixture['actor']);
                $this->fail('A scheduled track changed state outside its schedule through '.$method.'.');
            } catch (ValidationException) {
                $this->assertSame('scheduled', $track->fresh()->status);
            }
        }
        $this->assertSame(1, TrackPublicationSchedule::where('state', 'pending')->count());
    }

    // ---- Activation ------------------------------------------------------------------------------------------------------

    public function test_nothing_publishes_before_the_instant_and_the_track_is_not_served_while_scheduled(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $slug = $fixture['track']->slug;
        $this->travelTo($at->subMinute());
        $this->assertSame([0, 'NOTHING_DUE'], $this->runCommand());
        $this->assertSame('scheduled', $fixture['track']->fresh()->status);
        $this->get('/tracks/'.$slug)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/catalog')->json('tracks'));
        $this->assertSame('pending', $schedule->fresh()->state);
    }

    public function test_a_due_schedule_publishes_once_after_rechecking_every_guard(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $before = $fixture['track']->fresh();
        $this->travelTo($at->addSeconds(5));
        [$code, $output] = $this->runCommand();
        $this->assertSame(0, $code);
        $this->assertSame('PUBLISHED schedule='.$schedule->id.' track='.$fixture['track']->id, $output);
        $track = $fixture['track']->fresh();
        $this->assertSame('published', $track->status);
        $this->assertSame($before->publication_version + 1, $track->publication_version);
        $this->assertNotNull($track->published_at);
        $this->assertSame(['published', 'published', null, $track->publication_version], [$schedule->fresh()->state, $schedule->fresh()->outcome, $schedule->fresh()->pending_slot, $schedule->fresh()->publication_version]);
        $event = AuditEvent::where('action', 'catalog.track.published')->latest('id')->first();
        $this->assertSame('schedule', $event->context['trigger']);
        $this->assertSame('scheduled', $event->context['from_status']);
        $this->assertSame($fixture['actor']->id, $event->actor_id);
        $this->assertNotEmpty($event->context['manifest_hash']);
        $this->assertSame(['id' => (string) $track->id], array_intersect_key($this->getJson('/api/catalog')->json('tracks.0'), ['id' => 1]));
    }

    public function test_a_due_schedule_can_publish_into_unlisted(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled('unlisted');
        $this->travelTo($at->addMinute());
        $this->assertSame(0, $this->runCommand()[0]);
        $track = $fixture['track']->fresh();
        $this->assertSame('unlisted', $track->status);
        $this->assertSame($track->slug, $track->published_slug);
        $this->assertSame('published', $schedule->fresh()->state);
        $this->assertSame('catalog.track.published_unlisted', AuditEvent::where('action', 'like', 'catalog.track.published%')->latest('id')->first()->action);
        $this->assertSame([], $this->getJson('/api/catalog')->json('tracks'));
        $this->get('/tracks/'.$track->slug)->assertOk();
    }

    public function test_running_the_schedule_again_changes_nothing(): void
    {
        [, $fixture, $at] = $this->scheduled();
        $this->travelTo($at->addMinute());
        $this->assertSame(0, $this->runCommand()[0]);
        $state = $this->snapshot($fixture['track']->id);
        foreach (range(1, 3) as $ignored) {
            $this->assertSame([0, 'NOTHING_DUE'], $this->runCommand());
        }
        $this->assertSame($state, $this->snapshot($fixture['track']->id));
    }

    public function test_a_runner_that_selected_the_schedule_before_a_competitor_finished_publishes_nothing_more(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $scheduledVersion = $fixture['track']->fresh()->publication_version;
        $this->travelTo($at->addMinute());
        $probe = TrackPublicationSchedule::query()->findOrFail($schedule->id);
        $activate = new ReflectionMethod(TrackPublicationSchedules::class, 'activate');
        $service = app(TrackPublicationSchedules::class);
        $first = $activate->invoke($service, $probe);
        // The second runner holds the same stale probe it read before the first one committed.
        $second = $activate->invoke($service, $probe);
        $this->assertSame(['published', 'already_resolved'], [$first['outcome'], $second['outcome']]);
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.published')->where('subject_id', $fixture['track']->id)->where('context->trigger', 'schedule')->count());
        $this->assertSame($scheduledVersion + 1, $fixture['track']->fresh()->publication_version);
    }

    public function test_a_schedule_cancelled_after_a_runner_selected_it_is_not_published(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $this->travelTo($at->addMinute());
        $probe = TrackPublicationSchedule::query()->findOrFail($schedule->id);
        $track = $fixture['track']->fresh();
        app(TrackPublicationSchedules::class)->cancel($track, $fixture['actor'], $track->metadata_version, $track->publication_version);
        $result = (new ReflectionMethod(TrackPublicationSchedules::class, 'activate'))->invoke(app(TrackPublicationSchedules::class), $probe);
        $this->assertSame('already_resolved', $result['outcome']);
        $this->assertSame('draft', $fixture['track']->fresh()->status);
    }

    // ---- Gates that fail at the instant -----------------------------------------------------------------------------------

    public static function failingGates(): array
    {
        return [
            'every offer was deactivated' => ['offer', 'At least one active license offer is required.'],
            'the verified preview file is gone' => ['preview', 'preview_tagged'],
            'the artwork file is gone' => ['artwork', 'artwork'],
            'required metadata was cleared' => ['metadata', 'Complete title'],
            'deliverable bytes changed on disk' => ['bytes', null],
            'a newer rights declaration is not verified' => ['rights', 'rights declaration'],
        ];
    }

    private function breakGate(string $gate, array $fixture): void
    {
        $track = $fixture['track'];
        switch ($gate) {
            case 'offer':
                app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
                break;
            case 'preview':
            case 'artwork':
                // Verified media rows are immutable by design, so the realistic failure is the derivative's bytes going missing.
                $path = app(VerifiedMedia::class)->path($fixture['media'][$gate === 'preview' ? 'preview_tagged' : 'artwork']);
                $this->assertNotNull($path);
                unlink($path);
                break;
            case 'metadata':
                DB::table('tracks')->where('id', $track->id)->update(['genre' => null]);
                break;
            case 'bytes':
                $path = app(VerifiedMedia::class)->path($fixture['media']['master_wav']);
                chmod($path, 0600); // Verified files are stored read-only; tamper as an outside writer would.
                file_put_contents($path, 'tampered after scheduling');
                break;
            case 'rights':
                // Verified evidence is immutable; a newer declaration that is not verified supersedes it, as in operation.
                RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY-NEWER-DECLARATION',
                    'sample_disclosure' => 'Synthetic source', 'status' => 'pending']);
                break;
        }
    }

    #[DataProvider('failingGates')]
    public function test_a_guard_that_fails_at_the_instant_leaves_the_track_scheduled_and_records_why(string $gate, ?string $expected): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $this->breakGate($gate, $fixture);
        $this->travelTo($at->addMinutes(1));
        [$code, $output] = $this->runCommand();
        $this->assertSame(1, $code, $output);
        $this->assertStringStartsWith('BLOCKED schedule='.$schedule->id.' track='.$fixture['track']->id.' reason=', $output);
        $track = $fixture['track']->fresh();
        $this->assertSame('scheduled', $track->status, 'a failed guard must neither publish nor cancel');
        $row = $schedule->fresh();
        $this->assertSame(['pending', 1, 1], [$row->state, $row->pending_slot, $row->attempts]);
        $this->assertNotNull($row->last_failure_reason);
        if ($expected !== null) {
            $this->assertStringContainsStringIgnoringCase($expected, $row->last_failure_reason);
        }
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.schedule_blocked')->count());
        $this->assertNull(AuditEvent::where('action', 'catalog.track.schedule_blocked')->sole()->actor_id);
        $this->assertSame(0, AuditEvent::where('action', 'catalog.track.published')->where('context->trigger', 'schedule')->count());
        $this->get('/tracks/'.$track->slug)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/catalog')->json('tracks'));
    }

    public function test_activation_hashes_the_private_files_fresh_even_when_their_availability_is_cached(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $this->travelTo($at->addSeconds(30));
        // Warm the 60-second availability cache with a passing readiness read, then change the bytes inside that window.
        $this->assertSame([], app(TrackPublicationSchedules::class)->advisories($fixture['track']->fresh()));
        $preview = app(VerifiedMedia::class)->path($fixture['media']['preview_tagged']);
        $this->assertTrue(Cache::get($this->availabilityKey($fixture['media']['preview_tagged'], $preview)), 'the readiness read must cache the availability');
        $original = file_get_contents($preview);
        // Same size, different bytes: only a fresh hash notices, which is what manual publication does too.
        chmod($preview, 0600); // Verified files are stored read-only; tamper as an outside writer would.
        file_put_contents($preview, strrev($original));
        $this->assertNotSame($original, file_get_contents($preview));
        // The cache entry is keyed by the file's identity, whose times have one-second resolution. A tamper within the second the
        // file was written keeps the entry; a later one drops it, and then the readiness read refuses before the fresh hash is
        // reached (Foundation run 43505345035). Cache the tampered file as available, so the outcome never depends on the clock.
        Cache::put($this->availabilityKey($fixture['media']['preview_tagged'], $preview), true, MediaIntegrity::CACHE_SECONDS);
        [$code, $output] = $this->runCommand();
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('fresh unchanged bytes', $output);
        $this->assertSame('scheduled', $fixture['track']->fresh()->status);
    }

    public function test_a_blocked_schedule_keeps_reporting_failure_but_retries_only_after_the_interval(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $this->breakGate('offer', $fixture);
        $this->travelTo($at->addMinute());
        $this->assertSame(1, $this->runCommand()[0]);
        $this->assertSame(1, $schedule->fresh()->attempts);
        // Inside the retry interval the run is still a failure (the alert must not flicker healthy) and does not re-hash files.
        $this->travelTo($at->addMinutes(3));
        [$code, $output] = $this->runCommand();
        $this->assertSame(1, $code);
        $this->assertStringStartsWith('BLOCKED schedule='.$schedule->id, $output);
        $this->assertSame(1, $schedule->fresh()->attempts);
        $this->travelTo($at->addMinutes(1 + TrackPublicationSchedules::RETRY_MINUTES));
        $this->assertSame(1, $this->runCommand()[0]);
        $this->assertSame(2, $schedule->fresh()->attempts);
        // The same reason is audited once, not every retry.
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.schedule_blocked')->count());
    }

    public function test_a_changed_reason_is_audited_again(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $this->breakGate('offer', $fixture);
        $this->travelTo($at->addMinute());
        $this->runCommand();
        DB::table('tracks')->where('id', $fixture['track']->id)->update(['genre' => null]);
        $this->travelTo($at->addMinutes(10));
        $this->runCommand();
        $this->assertSame(2, AuditEvent::where('action', 'catalog.track.schedule_blocked')->count());
        $this->assertSame('scheduled', $fixture['track']->fresh()->status);
    }

    public function test_a_blocked_schedule_publishes_once_the_problem_is_fixed_without_staff_touching_it(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $path = app(VerifiedMedia::class)->path($fixture['media']['master_wav']);
        $original = file_get_contents($path);
        $mode = fileperms($path) & 0777;
        chmod($path, 0600); // Verified files are stored read-only; tamper as an outside writer would, then restore the mode.
        file_put_contents($path, 'tampered');
        $this->travelTo($at->addMinute());
        $this->assertSame(1, $this->runCommand()[0]);
        $this->assertSame('scheduled', $fixture['track']->fresh()->status);
        file_put_contents($path, $original);
        chmod($path, $mode);
        $this->travelTo($at->addMinutes(2 + TrackPublicationSchedules::RETRY_MINUTES));
        $this->assertSame(0, $this->runCommand()[0]);
        $this->assertSame('published', $fixture['track']->fresh()->status);
        $this->assertSame('published', $schedule->fresh()->state);
    }

    public function test_the_operator_losing_admin_authority_or_mfa_blocks_publication_until_rescheduled(): void
    {
        foreach (['role', 'mfa'] as $loss) {
            [$schedule, $fixture, $at] = $this->scheduled();
            if ($loss === 'role') {
                DB::table('users')->where('id', $fixture['actor']->id)->update(['is_admin' => false]);
            } else {
                $panel = Filament::getPanel('admin');
                $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
                $fixture['actor']->forceFill(['app_authentication_secret' => null])->save();
            }
            $this->travelTo($at->addMinute());
            [$code, $output] = $this->runCommand();
            $this->assertSame(1, $code, $loss);
            $this->assertStringContainsString('can no longer publish', $output);
            $this->assertSame('scheduled', $fixture['track']->fresh()->status, $loss);
            $this->assertSame('pending', $schedule->fresh()->state, $loss);

            return;
        }
    }

    public function test_the_public_activation_entry_point_rechecks_the_operator_role_and_mfa_itself(): void
    {
        foreach (['role', 'mfa'] as $loss) {
            [$schedule, $fixture] = $this->scheduled();
            if ($loss === 'role') {
                DB::table('users')->where('id', $fixture['actor']->id)->update(['is_admin' => false]);
            } else {
                $panel = Filament::getPanel('admin');
                $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
                $fixture['actor']->forceFill(['app_authentication_secret' => null])->save();
            }
            $resolved = false;
            try {
                DB::transaction(function () use ($fixture, &$resolved): void {
                    $track = Track::query()->lockForUpdate()->findOrFail($fixture['track']->id);
                    app(PublishTrack::class)->publishScheduled($track, $fixture['actor'], 'published', function () use (&$resolved): void {
                        $resolved = true;
                    });
                });
                $this->fail('A scheduled publication ran for an operator who lost '.$loss.'.');
            } catch (AuthorizationException) {
                $this->assertFalse($resolved, $loss);
            }
            $this->assertSame('scheduled', $fixture['track']->fresh()->status, $loss);
            $this->assertSame('pending', $schedule->fresh()->state, $loss);

            return;
        }
    }

    public function test_the_runner_refuses_an_outer_transaction_or_a_signed_in_user(): void
    {
        $this->scheduled();
        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            app(TrackPublicationSchedules::class)->runDue();
        } finally {
            DB::rollBack();
        }
    }

    public function test_the_runner_refuses_a_signed_in_user(): void
    {
        [, $fixture] = $this->scheduled();
        $this->actingAs($fixture['actor']);
        $this->expectException(LogicException::class);
        app(TrackPublicationSchedules::class)->runDue();
    }

    public function test_one_blocked_schedule_does_not_starve_the_ones_behind_it(): void
    {
        [$blocked, $first, $at] = $this->scheduled();
        $this->breakGate('offer', $first);
        $second = $this->draft();
        $secondTrack = app(TrackPublicationSchedules::class)->schedule(
            $second['track'], $second['actor'], $this->chicago($at->addMinutes(1)), 'published', $second['track']->metadata_version, $second['track']->publication_version,
        );
        $this->travelTo($at->addMinutes(2));
        [$code, $output] = $this->runCommand();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('BLOCKED schedule='.$blocked->id, $output);
        $this->assertStringContainsString('PUBLISHED schedule='.$secondTrack->id, $output);
        $this->assertSame('scheduled', $first['track']->fresh()->status);
        $this->assertSame('published', $second['track']->fresh()->status);
    }

    public function test_the_scheduler_runs_the_command_every_minute_with_a_short_overlap_lock_and_a_tracked_outcome(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'vasey:publish-scheduled-tracks'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
        // OperationalSignals::track registers success and failure callbacks.
        $this->assertNotEmpty((new \ReflectionProperty($event, 'afterCallbacks'))->getValue($event));
    }

    // ---- Database-level integrity ----------------------------------------------------------------------------------------

    private function forged(callable $statement): void
    {
        try {
            $statement();
            $this->fail('The database accepted a forged track state or schedule.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Track visibility or publication schedule evidence is invalid or immutable', $exception->getMessage());
        }
    }

    public function test_the_database_refuses_undefined_states_and_unscheduled_scheduled_tracks(): void
    {
        $fixture = $this->draft();
        $id = $fixture['track']->id;
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'archived']));
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'scheduled']));
        $this->forged(fn () => DB::table('tracks')->insert(['title' => 'x', 'slug' => 'x-forged', 'status' => 'scheduled']));
        $this->forged(fn () => DB::table('tracks')->insert(['title' => 'x', 'slug' => 'x-forged-2', 'status' => 'live']));
        $this->assertSame('draft', $fixture['track']->fresh()->status);
    }

    public function test_the_database_refuses_skipped_steps(): void
    {
        $fixture = $this->draft();
        $id = $fixture['track']->id;
        DB::table('tracks')->where('id', $id)->update(['status' => 'private']);
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'published']));
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'unlisted']));
        DB::table('tracks')->where('id', $id)->update(['status' => 'draft']);
        $this->assertSame('draft', $fixture['track']->fresh()->status);
    }

    public function test_the_database_refuses_to_activate_or_cancel_a_scheduled_track_behind_its_schedule(): void
    {
        [$schedule, $fixture] = $this->scheduled();
        $id = $fixture['track']->id;
        // Leaving the state with the schedule still pending, or going live without a resolved schedule, is refused.
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'draft']));
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'published']));
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'unlisted']));
        // A cancelled schedule does not authorise going live either.
        DB::table('track_publication_schedules')->where('id', $schedule->id)->update(['state' => 'cancelled', 'pending_slot' => null,
            'outcome' => 'cancelled', 'resolved_at' => now()->utc()->format('Y-m-d H:i:s'), 'resolved_by' => $fixture['actor']->id]);
        $this->forged(fn () => DB::table('tracks')->where('id', $id)->update(['status' => 'published']));
        $this->assertSame('scheduled', $fixture['track']->fresh()->status);
    }

    public function test_a_resolved_schedule_is_immutable_and_nothing_is_deletable(): void
    {
        [$schedule, $fixture, $at] = $this->scheduled();
        $this->forged(fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->delete());
        $this->forged(fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->update(['publish_at' => $at->addDay()->format('Y-m-d H:i:s')]));
        $this->forged(fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->update(['target_status' => 'unlisted']));
        $this->forged(fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->update(['state' => 'published', 'pending_slot' => null,
            'outcome' => 'published', 'resolved_at' => now()->utc()->format('Y-m-d H:i:s'), 'publication_version' => 99]));
        $track = $fixture['track']->fresh();
        app(TrackPublicationSchedules::class)->cancel($track, $fixture['actor'], $track->metadata_version, $track->publication_version);
        $this->forged(fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->update(['state' => 'pending', 'pending_slot' => 1, 'outcome' => null, 'resolved_at' => null, 'resolved_by' => null]));
        $this->forged(fn () => DB::table('track_publication_schedules')->where('id', $schedule->id)->delete());
        $this->expectException(LogicException::class);
        $schedule->fresh()->update(['attempts' => 5]);
    }

    public function test_the_database_refuses_forged_schedule_rows(): void
    {
        $fixture = $this->draft();
        $base = ['track_id' => $fixture['track']->id, 'target_status' => 'published', 'publish_at' => now()->utc()->addHour()->startOfMinute()->format('Y-m-d H:i:s'),
            'entry_zone' => 'America/Chicago', 'entry_local' => '2026-12-01 09:30', 'created_by' => $fixture['actor']->id,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'state' => 'pending', 'pending_slot' => 1];
        foreach ([
            'target outside the set' => ['target_status' => 'private'],
            'a non-Chicago entry zone' => ['entry_zone' => 'UTC'],
            'not a whole minute' => ['publish_at' => now()->utc()->addHour()->startOfMinute()->addSeconds(7)->format('Y-m-d H:i:s')],
            'in the past' => ['publish_at' => now()->utc()->subHour()->startOfMinute()->format('Y-m-d H:i:s')],
            'already resolved' => ['state' => 'published', 'pending_slot' => null],
            'with attempts' => ['attempts' => 3],
        ] as $label => $override) {
            $this->forged(fn () => DB::table('track_publication_schedules')->insert($override + $base));
        }
        DB::table('track_publication_schedules')->insert($base);
        // A second pending row for the same track collides with the single slot.
        try {
            DB::table('track_publication_schedules')->insert($base);
            $this->fail('Two pending schedules were accepted for one track.');
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase('unique', $exception->getMessage());
        }
    }

    // ---- Helpers ---------------------------------------------------------------------------------------------------------

    private function snapshot(int $trackId): string
    {
        return json_encode([
            Track::query()->whereKey($trackId)->first()->only(['status', 'publication_version', 'published_at', 'metadata_version']),
            DB::table('track_publication_schedules')->orderBy('id')->get()->all(),
            AuditEvent::query()->orderBy('id')->pluck('action')->all(),
        ], JSON_THROW_ON_ERROR);
    }
}
