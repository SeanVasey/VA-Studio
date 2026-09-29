<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationRevision;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** The runner refuses outer transactions, so these fixtures are committed and the schema is rebuilt per test. */
class SiteScheduleRunnerTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function draft(User $actor, string $title): SiteRelease
    {
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = $title;

        return app(SiteContent::class)->create($content, 'Private runner label '.$title, $actor);
    }

    /** @return array{0: SitePublicationSchedule, 1: SiteRelease, 2: User} */
    private function scheduled(string $title = 'SCHEDULED'): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:30', 'UTC'));
        $actor = LicenseFixtures::admin();
        $release = $this->draft($actor, $title);

        return [app(SiteContent::class)->schedule($release->id, CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'), 0, $actor), $release, $actor];
    }

    private function runScheduler(): array
    {
        $code = Artisan::call('vasey:publish-scheduled-site-release');

        return [$code, trim(Artisan::output())];
    }

    public function test_runner_waits_for_the_time_then_publishes_once_through_the_guarded_path(): void
    {
        [$schedule, $release, $actor] = $this->scheduled();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:04:59', 'UTC'));
        $this->assertNull(app(SiteContent::class)->runDueSchedule());
        $this->assertSame([0, 'NOTHING_DUE'], $this->runScheduler());
        $this->assertSame(SiteContentSchema::defaults(), app(SiteContent::class)->current());
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:05:07', 'UTC'));
        $this->assertSame([0, 'PUBLISHED schedule='.$schedule->id.' revision=1'], $this->runScheduler());
        $this->assertSame('SCHEDULED', app(SiteContent::class)->current()['hero']['title']);
        $schedule->refresh();
        $this->assertSame(['published', 'published', null, null, 1],
            [$schedule->state, $schedule->outcome, $schedule->pending_slot, $schedule->resolved_by, $schedule->publication_revision]);
        $this->assertSame('2026-10-01 12:05:07', $schedule->resolved_at->format('Y-m-d H:i:s'));
        // The first publication still retains the original baseline, captured by the scheduling operator.
        $history = SitePublicationRevision::orderBy('revision')->get();
        $this->assertSame([[0, 'baseline', $actor->id], [1, 'publish', $actor->id]],
            $history->map(fn ($row) => [$row->revision, $row->operation, $row->actor_id])->all());
        $this->assertSame($release->id, $history[1]->release_id);
        $this->assertSame($release->content_hash, $history[1]->content_hash);
        $publish = AuditEvent::where('action', 'site.release.publish')->sole();
        $this->assertSame($actor->id, $publish->actor_id);
        $this->assertSame([$schedule->id, 'schedule'], [$publish->context['schedule_id'], $publish->context['trigger']]);
        $this->assertSame($schedule->id, AuditEvent::where('action', 'site.release.baseline_retained')->sole()->context['schedule_id']);
        $this->assertSame(1, AuditEvent::where('action', 'site.schedule.published')->where('actor_id', $actor->id)->count());
        $this->assertSame([0, 'NOTHING_DUE'], $this->runScheduler());
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
        foreach (['tracks', 'orders', 'license_grants', 'pending_entitlements', 'stripe_webhook_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function lateness(): array
    {
        return ['last second of the grace window publishes' => ['2026-10-01 13:05:00', 'published'],
            'one second later expires unpublished' => ['2026-10-01 13:05:01', 'expired']];
    }

    #[DataProvider('lateness')]
    public function test_runner_publishes_within_the_grace_window_and_expires_after_it(string $time, string $state): void
    {
        [$schedule] = $this->scheduled();
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
        [$code, $output] = $this->runScheduler();
        $this->assertSame($state, $schedule->fresh()->state);
        if ($state === 'expired') {
            $this->assertSame([1, 'EXPIRED schedule='.$schedule->id], [$code, $output]);
            $this->assertSame(['grace_expired', null], [$schedule->fresh()->outcome, $schedule->fresh()->resolved_by]);
            $this->assertSame(0, SitePublication::findOrFail(1)->revision);
            $this->assertNull(AuditEvent::where('action', 'site.schedule.expired')->sole()->actor_id);
        } else {
            $this->assertSame(0, $code);
            $this->assertSame(1, SitePublication::findOrFail(1)->revision);
        }
    }

    public function test_runner_fails_closed_when_the_scheduling_administrator_lost_authority(): void
    {
        [$schedule, , $actor] = $this->scheduled();
        DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'));
        $this->assertSame([1, 'FAILED actor_unauthorized schedule='.$schedule->id], $this->runScheduler());
        $this->assertSame(['failed', 'actor_unauthorized'], [$schedule->fresh()->state, $schedule->fresh()->outcome]);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $this->assertNull(AuditEvent::where('action', 'site.schedule.failed')->sole()->actor_id);
    }

    public function test_runner_fails_closed_on_corrupt_release_evidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:30', 'UTC'));
        $actor = LicenseFixtures::admin();
        $content = SiteContentSchema::defaults(); $content['hero']['title'] = 'CORRUPTED';
        $releaseId = DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic corrupt scheduled release', 'schema_version' => 1, 'content' => json_encode($content),
            'content_hash' => str_repeat('c', 64), 'canonicalization_version' => CanonicalJson::VERSION, 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        // Only a forged row can reference corrupt content; the service refuses to schedule it.
        $scheduleId = DB::table('site_publication_schedules')->insertGetId([
            'release_id' => $releaseId, 'release_content_hash' => str_repeat('c', 64), 'publish_at' => '2026-10-01 12:05:00',
            'expected_revision' => 0, 'created_by' => $actor->id, 'created_at' => '2026-10-01 12:00:30', 'state' => 'pending', 'pending_slot' => 1,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'));
        $this->assertSame([1, 'FAILED integrity schedule='.$scheduleId], $this->runScheduler());
        $this->assertSame('failed', SitePublicationSchedule::findOrFail($scheduleId)->state);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertSame(SiteContentSchema::defaults(), app(SiteContent::class)->current());
    }

    public function test_runner_fails_closed_when_a_publication_bypassed_superseding(): void
    {
        [$schedule, , $actor] = $this->scheduled();
        // Simulate code that predates scheduling: a valid publication that did not resolve the pending schedule.
        $other = $this->draft($actor, 'LEGACY PUBLISH');
        DB::transaction(function () use ($other, $actor): void {
            DB::table('site_publication_revisions')->insert(['revision' => 1, 'release_id' => $other->id, 'previous_release_id' => null,
                'operation' => 'publish', 'content_hash' => $other->content_hash, 'actor_id' => $actor->id, 'created_at' => now()]);
            DB::table('site_publications')->where('id', 1)->update(['revision' => 1, 'active_release_id' => $other->id, 'updated_at' => now()]);
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'));
        $this->assertSame([1, 'FAILED stale_revision schedule='.$schedule->id], $this->runScheduler());
        $this->assertSame(['failed', 'stale_revision'], [$schedule->fresh()->state, $schedule->fresh()->outcome]);
        $this->assertSame('LEGACY PUBLISH', app(SiteContent::class)->current()['hero']['title']);
        $this->assertSame(1, SitePublication::findOrFail(1)->revision);
    }

    public function test_runner_refuses_an_outer_transaction_or_a_signed_in_user_and_reports_only_the_error_class(): void
    {
        [$schedule, , $actor] = $this->scheduled();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:05:00', 'UTC'));
        [$code, $output] = DB::transaction(fn () => $this->runScheduler());
        $this->assertSame([1, 'UNAVAILABLE LogicException'], [$code, $output]);
        $this->actingAs($actor);
        try {
            app(SiteContent::class)->runDueSchedule();
            $this->fail('The runner accepted a signed-in user.');
        } catch (LogicException) {
        }
        $this->assertSame('pending', $schedule->fresh()->state);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
    }

    public function test_scheduler_runs_the_publisher_every_minute_with_a_short_overlap_lock(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'vasey:publish-scheduled-site-release'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
    }
}
