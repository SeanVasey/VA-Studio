<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Diagnostics\InstallationReport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class SiteScheduleTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-10-01 12:00:30', 'UTC');
        $this->travelTo($this->now);
    }

    private function draft(User $actor, string $title): SiteRelease
    {
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = $title;

        return app(SiteContent::class)->create($content, 'Private schedule label '.$title, $actor);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'UTC');
    }

    private function rejected(callable $operation, string $key): ValidationException
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());

            return $exception;
        }
        $this->fail('The schedule operation was accepted.');
    }

    private function forged(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Forged schedule evidence was accepted.');
        } catch (QueryException) {
        }
    }

    public function test_scheduling_retains_the_exact_release_revision_and_time_without_changing_the_live_site(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $release = $this->draft($actor, 'SCHEDULED HERO');
        $schedule = $site->schedule($release->id, $this->at('2026-10-01 12:05:00'), 0, $actor);
        $this->assertSame('pending', $schedule->state);
        $this->assertSame(1, $schedule->fresh()->pending_slot);
        $this->assertSame(0, $schedule->expected_revision);
        $this->assertSame($release->content_hash, $schedule->release_content_hash);
        $this->assertSame($actor->id, $schedule->created_by);
        $this->assertSame('2026-10-01 12:05:00', $schedule->fresh()->publish_at->format('Y-m-d H:i:s'));
        $this->assertTrue($site->pendingSchedule()->is($schedule));
        $this->assertSame(SiteContentSchema::defaults(), $site->current());
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $audit = AuditEvent::where('action', 'site.schedule.created')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(SitePublicationSchedule::class, $audit->subject_type);
        $this->assertEquals(['release_id' => $release->id, 'content_hash' => $release->content_hash,
            'publish_at' => '2026-10-01T12:05:00Z', 'expected_revision' => 0], $audit->context);
        $this->assertStringNotContainsString('Private schedule label', json_encode($audit->context));
    }

    public function test_schedule_time_is_a_whole_utc_minute_at_least_one_minute_ahead_and_within_the_limit(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $release = $this->draft($actor, 'BOUNDS');
        foreach (['2026-10-01 11:59:00', '2026-10-01 12:00:00', '2026-10-01 12:01:00', '2026-10-01 12:03:30', '2027-10-01 12:01:00'] as $invalid) {
            $this->rejected(fn () => $site->schedule($release->id, $this->at($invalid), 0, $actor), 'publish_at');
        }
        $this->rejected(fn () => $site->schedule($release->id, $this->at('2026-10-01 12:03:00.250'), 0, $actor), 'publish_at');
        $this->assertDatabaseCount('site_publication_schedules', 0);
        // The earliest and latest accepted whole minutes; a non-UTC input is normalized to the same instant.
        $site->cancelSchedule($site->schedule($release->id, $this->at('2026-10-01 12:02:00'), 0, $actor)->id, $actor);
        $site->cancelSchedule($site->schedule($release->id, CarbonImmutable::parse('2027-10-01 07:00:00', 'America/New_York'), 0, $actor)->id, $actor);
        $this->assertSame(['2026-10-01 12:02:00', '2027-10-01 11:00:00'],
            SitePublicationSchedule::orderBy('id')->get()->map(fn ($schedule) => $schedule->publish_at->format('Y-m-d H:i:s'))->all());
    }

    public function test_scheduling_rejects_an_active_corrupt_missing_or_stale_release_and_a_second_pending_schedule(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $active = $this->draft($actor, 'ACTIVE');
        $site->publish($active->id, 0, $actor);
        $this->rejected(fn () => $site->schedule($active->id, $this->at('2026-10-01 12:10:00'), 1, $actor), 'publication');
        $next = $this->draft($actor, 'NEXT');
        $this->assertStringContainsString('before scheduling',
            $this->rejected(fn () => $site->schedule($next->id, $this->at('2026-10-01 12:10:00'), 0, $actor), 'publication')->errors()['publication'][0]);
        $content = SiteContentSchema::defaults(); $content['hero']['title'] = 'CORRUPTED';
        $corruptId = DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic corrupt schedule target', 'schema_version' => 1, 'content' => json_encode($content),
            'content_hash' => str_repeat('a', 64), 'canonicalization_version' => CanonicalJson::VERSION, 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        $this->rejected(fn () => $site->schedule($corruptId, $this->at('2026-10-01 12:10:00'), 1, $actor), 'publication');
        try {
            $site->schedule(999999, $this->at('2026-10-01 12:10:00'), 1, $actor);
            $this->fail('A missing release was scheduled.');
        } catch (ModelNotFoundException) {
        }
        $site->schedule($next->id, $this->at('2026-10-01 12:10:00'), 1, $actor);
        $other = $this->draft($actor, 'OTHER');
        $this->assertStringContainsString('already scheduled',
            $this->rejected(fn () => $site->schedule($other->id, $this->at('2026-10-01 12:20:00'), 1, $actor), 'publication')->errors()['publication'][0]);
        $this->assertSame(1, SitePublicationSchedule::where('state', 'pending')->count());
        $this->assertSame(1, AuditEvent::where('action', 'site.schedule.created')->count());
    }

    public function test_scheduling_and_cancellation_use_fresh_administrator_authority(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $release = $this->draft($actor, 'AUTHORITY');
        $pending = $site->schedule($release->id, $this->at('2026-10-01 12:10:00'), 0, $actor);
        $demoted = LicenseFixtures::admin(); DB::table('users')->where('id', $demoted->id)->update(['is_admin' => false]);
        $unverified = LicenseFixtures::admin(); DB::table('users')->where('id', $unverified->id)->update(['email_verified_at' => null]);
        foreach ([$demoted, $unverified, User::factory()->create(), new User] as $denied) {
            foreach ([fn () => $site->cancelSchedule($pending->id, $denied), fn () => $site->schedule($release->id, $this->at('2026-10-01 12:20:00'), 0, $denied)] as $operation) {
                try {
                    $operation();
                    $this->fail('A non-administrator changed a site schedule.');
                } catch (AuthorizationException) {
                }
            }
        }
        $this->assertSame('pending', $pending->fresh()->state);
        $this->assertDatabaseCount('site_publication_schedules', 1);
    }

    public function test_cancellation_resolves_the_pending_schedule_once_and_frees_the_slot(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $release = $this->draft($actor, 'CANCEL');
        $schedule = $site->schedule($release->id, $this->at('2026-10-01 12:10:00'), 0, $actor);
        $this->travel(2)->minutes();
        $site->cancelSchedule($schedule->id, $other);
        $schedule->refresh();
        $this->assertSame(['cancelled', 'cancelled', null, $other->id, null],
            [$schedule->state, $schedule->outcome, $schedule->pending_slot, $schedule->resolved_by, $schedule->publication_revision]);
        $this->assertSame('2026-10-01 12:02:30', $schedule->resolved_at->format('Y-m-d H:i:s'));
        $this->assertNull($site->pendingSchedule());
        $this->rejected(fn () => $site->cancelSchedule($schedule->id, $actor), 'publication');
        $audit = AuditEvent::where('action', 'site.schedule.cancelled')->sole();
        $this->assertSame($other->id, $audit->actor_id);
        $this->assertEquals(['release_id' => $release->id, 'outcome' => 'cancelled', 'publish_at' => '2026-10-01T12:10:00Z'], $audit->context);
        $this->assertSame('pending', $site->schedule($release->id, $this->at('2026-10-01 12:30:00'), 0, $actor)->state);
        $this->assertSame(SiteContentSchema::defaults(), $site->current());
    }

    public function test_manual_publish_and_rollback_supersede_the_reviewed_schedule_in_the_same_transaction(): void
    {
        $site = app(SiteContent::class);
        $scheduler = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $first = $this->draft($scheduler, 'FIRST');
        $second = $this->draft($scheduler, 'SECOND');
        $scheduled = $this->draft($scheduler, 'SCHEDULED');
        $one = $site->schedule($scheduled->id, $this->at('2026-10-01 13:00:00'), 0, $scheduler);
        $this->assertSame(1, $site->publish($first->id, 0, $editor, $one->id)->revision);
        $one->refresh();
        $this->assertSame(['superseded', 'manual_publish', $editor->id, 1, null],
            [$one->state, $one->outcome, $one->resolved_by, $one->publication_revision, $one->pending_slot]);
        $site->publish($second->id, 1, $editor);
        $two = $site->schedule($scheduled->id, $this->at('2026-10-01 13:00:00'), 2, $scheduler);
        $this->assertSame(3, $site->rollback($first->id, 2, $editor, $two->id)->revision);
        $two->refresh();
        $this->assertSame(['superseded', 'manual_rollback', $editor->id, 3], [$two->state, $two->outcome, $two->resolved_by, $two->publication_revision]);
        $this->assertSame('FIRST', $site->current()['hero']['title']);
        $audits = AuditEvent::where('action', 'site.schedule.superseded')->orderBy('id')->get();
        $this->assertSame([$editor->id, $editor->id], $audits->pluck('actor_id')->all());
        $this->assertEquals(['release_id' => $scheduled->id, 'outcome' => 'manual_rollback', 'publish_at' => '2026-10-01T13:00:00Z', 'publication_revision' => 3],
            $audits->last()->context);
    }

    public function test_publication_rejects_a_schedule_the_operator_did_not_review_and_failures_keep_it_pending(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $release = $this->draft($actor, 'LIVE');
        $scheduled = $this->draft($actor, 'LATER');
        $pending = $site->schedule($scheduled->id, $this->at('2026-10-01 13:00:00'), 0, $actor);
        foreach ([null, $pending->id + 1] as $unreviewed) {
            $message = $this->rejected(fn () => $site->publish($release->id, 0, $actor, $unreviewed), 'publication')->errors()['publication'][0];
            $this->assertStringContainsString('scheduled or cancelled after you opened', $message);
        }
        // A stale revision fails before the reviewed schedule could be resolved.
        $this->rejected(fn () => $site->publish($release->id, 7, $actor, $pending->id), 'publication');
        $this->assertSame('pending', $pending->fresh()->state);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $site->cancelSchedule($pending->id, $actor);
        $this->rejected(fn () => $site->publish($release->id, 0, $actor, $pending->id), 'publication');
        $this->assertSame(1, $site->publish($release->id, 0, $actor)->revision);
        $this->assertSame(0, AuditEvent::where('action', 'site.schedule.superseded')->count());
    }

    public function test_resolved_schedules_are_immutable_through_the_model_and_cannot_be_deleted(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $schedule = $site->schedule($this->draft($actor, 'MODEL')->id, $this->at('2026-10-01 12:10:00'), 0, $actor);
        foreach ([fn () => $schedule->delete(), function () use ($site, $schedule, $actor): void {
            $site->cancelSchedule($schedule->id, $actor);
            $resolved = $schedule->fresh();
            $resolved->outcome = 'published';
            $resolved->save();
        }] as $write) {
            try {
                $write();
                $this->fail('A retained schedule changed through the model.');
            } catch (LogicException) {
            }
        }
        $this->assertSame('cancelled', $schedule->fresh()->outcome);
    }

    public function test_database_guards_reject_forged_schedule_rows_and_transitions_on_both_engines(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $release = $this->draft($actor, 'GUARDED');
        $other = $this->draft($actor, 'OTHER GUARDED');
        $row = fn (array $overrides = []): array => $overrides + [
            'release_id' => $release->id, 'release_content_hash' => $release->content_hash, 'publish_at' => '2026-10-01 12:10:00',
            'expected_revision' => 0, 'created_by' => $actor->id, 'created_at' => '2026-10-01 12:00:30', 'state' => 'pending', 'pending_slot' => 1,
        ];
        foreach ([
            ['state' => 'published', 'pending_slot' => null], ['pending_slot' => 2], ['release_content_hash' => str_repeat('b', 64)],
            ['expected_revision' => 1], ['publish_at' => '2026-10-01 12:10:30'], ['publish_at' => '2026-10-01 12:00:00'],
            ['outcome' => 'cancelled'], ['resolved_by' => $actor->id], ['resolved_at' => '2026-10-01 12:05:00'], ['publication_revision' => 1],
        ] as $overrides) {
            $this->forged(fn () => DB::table('site_publication_schedules')->insert($row($overrides)));
        }
        $pending = $site->schedule($release->id, $this->at('2026-10-01 12:10:00'), 0, $actor);
        $this->forged(fn () => DB::table('site_publication_schedules')->insert($row(['release_id' => $other->id, 'release_content_hash' => $other->content_hash])));
        $resolve = fn (array $values) => fn () => DB::table('site_publication_schedules')->where('id', $pending->id)->update($values + ['pending_slot' => null]);
        foreach ([
            ['state' => 'published', 'outcome' => 'published', 'resolved_at' => '2026-10-01 12:11:00', 'publication_revision' => 1],
            ['state' => 'superseded', 'outcome' => 'manual_publish', 'resolved_at' => '2026-10-01 12:01:00', 'resolved_by' => $actor->id, 'publication_revision' => 1],
            ['state' => 'expired', 'outcome' => 'grace_expired', 'resolved_at' => '2026-10-01 13:10:00'],
            ['state' => 'failed', 'outcome' => 'integrity', 'resolved_at' => '2026-10-01 12:09:00'],
            ['state' => 'failed', 'outcome' => 'unknown', 'resolved_at' => '2026-10-01 12:11:00'],
            ['state' => 'cancelled', 'outcome' => 'cancelled', 'resolved_at' => '2026-10-01 12:01:00'],
            ['state' => 'cancelled', 'outcome' => 'cancelled', 'resolved_at' => '2026-10-01 12:01:00', 'resolved_by' => $actor->id, 'publish_at' => '2026-10-01 12:30:00'],
            ['state' => 'pending', 'outcome' => 'cancelled', 'resolved_at' => '2026-10-01 12:01:00', 'resolved_by' => $actor->id],
        ] as $values) {
            $this->forged($resolve($values));
        }
        $this->forged(fn () => DB::table('site_publication_schedules')->where('id', $pending->id)->update(['publish_at' => '2026-10-01 12:30:00']));
        $this->forged(fn () => DB::table('site_publication_schedules')->where('id', $pending->id)->delete());
        $site->cancelSchedule($pending->id, $actor);
        foreach ([['state' => 'pending', 'pending_slot' => 1, 'outcome' => null, 'resolved_at' => null, 'resolved_by' => null], ['outcome' => 'published']] as $values) {
            $this->forged(fn () => DB::table('site_publication_schedules')->where('id', $pending->id)->update($values));
        }
        $this->assertSame('cancelled', $pending->fresh()->state);
        $this->assertDatabaseCount('site_publication_schedules', 1);
    }

    public function test_populated_schedule_migration_rollback_is_refused_and_the_guards_remain(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $site->schedule($this->draft($actor, 'RETAINED')->id, $this->at('2026-10-01 12:10:00'), 0, $actor);
        $migration = require database_path('migrations/2026_09_29_000026_site_publication_schedules.php');
        try {
            $migration->down();
            $this->fail('Populated schedule history was dropped.');
        } catch (LogicException) {
        }
        $this->forged(fn () => DB::table('site_publication_schedules')->delete());
        $this->assertDatabaseCount('site_publication_schedules', 1);
    }

    public function test_doctor_warns_only_when_a_pending_schedule_is_overdue(): void
    {
        $site = app(SiteContent::class);
        $actor = LicenseFixtures::admin();
        $schedule = $site->schedule($this->draft($actor, 'DOCTOR')->id, $this->at('2026-10-01 12:10:00'), 0, $actor);
        $status = fn (): string => array_column(app(InstallationReport::class)->collect()['checks'], 'status', 'id')['scheduled_publication'];
        $this->assertSame('pass', $status());
        $this->travelTo($this->at('2026-10-01 12:11:59'));
        $this->assertSame('pass', $status());
        $this->travelTo($this->at('2026-10-01 12:12:01'));
        $this->assertSame('warn', $status());
        $site->cancelSchedule($schedule->id, $actor);
        $this->assertSame('pass', $status());
        $this->assertSame('cancelled', $schedule->fresh()->state);
    }
}
