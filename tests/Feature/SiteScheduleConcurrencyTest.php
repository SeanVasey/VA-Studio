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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteContentRace;
use Tests\TestCase;

class SiteScheduleConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent scheduled-publication locking is verified on MySQL, not SQLite.');
        }
    }

    private function draft(User $actor, string $title): SiteRelease
    {
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = $title;

        return app(SiteContent::class)->create($content, 'Race label '.$title, $actor);
    }

    /**
     * Workers read the real clock, so the schedule is created in the past and is due, within its grace window, when they run.
     *
     * @return array{0: SitePublicationSchedule, 1: SiteRelease, 2: User}
     */
    private function dueSchedule(): array
    {
        $real = CarbonImmutable::now('UTC');
        $this->travelTo($real->subMinutes(10));
        $actor = LicenseFixtures::admin();
        $release = $this->draft($actor, 'SCHEDULED RACE');
        $schedule = app(SiteContent::class)->schedule($release->id, $real->subMinutes(5)->startOfMinute(), 0, $actor);
        $this->travelBack();

        return [$schedule, $release, $actor];
    }

    public static function orders(): array
    {
        return ['scheduler takes the lock first' => [0], 'staff take the lock first' => [1]];
    }

    #[DataProvider('orders')]
    public function test_scheduler_and_manual_publication_serialize_to_one_consistent_outcome(int $first): void
    {
        [$schedule, $scheduled, $scheduler] = $this->dueSchedule();
        $staff = LicenseFixtures::admin();
        $manual = $this->draft($staff, 'MANUAL RACE');
        $race = SiteContentRace::run($this, [
            ['operation' => 'run_schedule'],
            ['operation' => 'publish', 'release_id' => $manual->id, 'revision' => 0, 'actor_id' => $staff->id, 'expected_schedule_id' => $schedule->id],
        ], function (array $results, int $winner): void {
            if ($winner === 0) {
                $this->assertSame(['schedule', 'published', 1], [$results[0]['result'], $results[0]['outcome'], $results[0]['revision']]);
                $this->assertSame('rejected', $results[1]['result']);
                $this->assertStringContainsString('published site changed', $results[1]['errors']['publication'][0]);
            } else {
                $this->assertSame(['published', 1], [$results[1]['result'], $results[1]['revision']]);
                $this->assertSame(['schedule', 'already_resolved'], [$results[0]['result'], $results[0]['outcome']]);
            }
        }, $first);
        $this->assertSame($first, $race['winner']);
        $schedule->refresh();
        $publication = SitePublication::findOrFail(1);
        $this->assertSame(1, $publication->revision);
        if ($race['winner'] === 0) {
            $this->assertSame([$scheduled->id, 'published', 'published', null], [$publication->active_release_id, $schedule->state, $schedule->outcome, $schedule->resolved_by]);
            $this->assertSame($scheduler->id, SitePublicationRevision::where('revision', 1)->sole()->actor_id);
        } else {
            $this->assertSame([$manual->id, 'superseded', 'manual_publish', $staff->id], [$publication->active_release_id, $schedule->state, $schedule->outcome, $schedule->resolved_by]);
            $this->assertSame($staff->id, SitePublicationRevision::where('revision', 1)->sole()->actor_id);
        }
        $this->assertSame(1, $schedule->publication_revision);
        $this->assertDatabaseCount('site_publication_revisions', 2);
        $this->assertSame(1, AuditEvent::where('action', 'site.release.publish')->count());
        $this->assertSame(1, AuditEvent::where('action', 'site.release.baseline_retained')->count());
    }

    public function test_overlapping_scheduler_runs_publish_the_schedule_once(): void
    {
        [$schedule, $scheduled, $scheduler] = $this->dueSchedule();
        SiteContentRace::run($this, [['operation' => 'run_schedule'], ['operation' => 'run_schedule']],
            function (array $results, int $winner, int $loser): void {
                $this->assertSame(['schedule', 'published', 1], [$results[$winner]['result'], $results[$winner]['outcome'], $results[$winner]['revision']]);
                $this->assertSame(['schedule', 'already_resolved', null], [$results[$loser]['result'], $results[$loser]['outcome'], $results[$loser]['revision']]);
            });
        $this->assertSame([1, $scheduled->id], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
        $this->assertSame(['published', 1], [$schedule->fresh()->state, $schedule->fresh()->publication_revision]);
        $this->assertDatabaseCount('site_publication_revisions', 2);
        $this->assertSame(1, AuditEvent::where('action', 'site.release.publish')->where('actor_id', $scheduler->id)->count());
        $this->assertSame(1, AuditEvent::where('action', 'site.schedule.published')->count());
    }

    #[DataProvider('orders')]
    public function test_scheduler_and_cancellation_serialize_to_one_consistent_outcome(int $first): void
    {
        [$schedule, $scheduled] = $this->dueSchedule();
        $staff = LicenseFixtures::admin();
        $race = SiteContentRace::run($this, [
            ['operation' => 'run_schedule'],
            ['operation' => 'cancel_schedule', 'schedule_id' => $schedule->id, 'actor_id' => $staff->id],
        ], function (array $results, int $winner): void {
            if ($winner === 0) {
                $this->assertSame(['schedule', 'published'], [$results[0]['result'], $results[0]['outcome']]);
                $this->assertSame('rejected', $results[1]['result']);
                $this->assertStringContainsString('already resolved', $results[1]['errors']['publication'][0]);
            } else {
                $this->assertSame(['cancelled', 'cancelled'], [$results[1]['result'], $results[1]['state']]);
                $this->assertSame(['schedule', 'already_resolved'], [$results[0]['result'], $results[0]['outcome']]);
            }
        }, $first);
        $this->assertSame($first, $race['winner']);
        $schedule->refresh();
        if ($race['winner'] === 0) {
            $this->assertSame([1, $scheduled->id], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
            $this->assertSame(['published', null], [$schedule->state, $schedule->resolved_by]);
            $this->assertSame(0, AuditEvent::where('action', 'site.schedule.cancelled')->count());
        } else {
            $this->assertSame([0, null], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
            $this->assertSame(['cancelled', $staff->id], [$schedule->state, $schedule->resolved_by]);
            $this->assertDatabaseCount('site_publication_revisions', 0);
            $this->assertSame(0, AuditEvent::where('action', 'site.release.publish')->count());
        }
    }
}
