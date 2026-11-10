<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\SiteBuilder\LegalPublicationGuard;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LegalPageFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * FP-005: the scheduler's own activation path refuses unresolved legal markers too. The runner refuses outer
 * transactions, so these fixtures are committed and the schema is rebuilt per test.
 */
class LegalScheduledPublicationGuardTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:30', 'UTC'));
    }

    /** A pending schedule as one made before the rule existed would be stored; the service itself now refuses to create it. */
    private function pendingBeforeTheRule(SiteRelease $release, User $actor): int
    {
        return DB::table('site_publication_schedules')->insertGetId([
            'release_id' => $release->id, 'release_content_hash' => $release->content_hash, 'publish_at' => '2026-11-10 12:05:00',
            'expected_revision' => 0, 'created_by' => $actor->id, 'created_at' => '2026-11-10 12:00:30', 'state' => 'pending', 'pending_slot' => 1,
        ]);
    }

    private function runScheduler(): array
    {
        $code = Artisan::call('vasey:publish-scheduled-site-release');

        return [$code, trim(Artisan::output())];
    }

    public function test_a_due_schedule_for_a_release_with_legal_markers_fails_closed_with_the_reason_code(): void
    {
        $actor = LicenseFixtures::admin();
        $content = LegalPageFixtures::content('SCHEDULED MARKERS', ['privacy', 'terms']);
        $content['legal']['privacy']['sections'][0]['paragraphs'][0] = 'Postal address: [registered postal address — inserted at publication]';
        $content['legal']['terms']['sections'][0]['heading'] = '15. Disclaimers [For legal review]';
        $content['legal']['terms']['sections'][1]['paragraphs'][0] = 'courts located in Iowa [county — Sean to confirm]';
        $release = app(SiteContent::class)->create($content, 'Scheduled before the rule', $actor);
        $scheduleId = $this->pendingBeforeTheRule($release, $actor);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:05:07', 'UTC'));
        $this->assertSame([1, 'FAILED integrity schedule='.$scheduleId.' reason=legal_unresolved_markers'], $this->runScheduler());
        $schedule = SitePublicationSchedule::findOrFail($scheduleId);
        $this->assertSame(['failed', 'integrity', null, null, null],
            [$schedule->state, $schedule->outcome, $schedule->pending_slot, $schedule->resolved_by, $schedule->publication_revision]);
        $this->assertSame([0, null], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
        $this->assertDatabaseCount('site_publication_revisions', 0);
        $this->assertSame(SiteContentSchema::defaults(), app(SiteContent::class)->current());
        foreach (['privacy', 'terms'] as $document) {
            $this->get('/legal/'.$document)->assertNotFound()->assertDontSee('SCHEDULED MARKERS', false);
        }
        $event = AuditEvent::where('action', 'site.schedule.failed')->sole();
        $this->assertNull($event->actor_id);
        $this->assertSame(LegalPublicationGuard::REASON, $event->context['reason']);
        $this->assertSame(['privacy.publication_value' => 1, 'terms.legal_review_marker' => 1, 'terms.owner_decision' => 1], $event->context['legal_markers']);
        $this->assertSame('integrity', $event->context['outcome']);
        // The audit trail carries counts, never the page text.
        $this->assertStringNotContainsString('postal address', json_encode($event->context, JSON_THROW_ON_ERROR));
        $this->assertSame(0, AuditEvent::whereIn('action', ['site.release.publish', 'site.schedule.published'])->count());
        // Resolved once: the next run finds nothing, and staff see the specific reason in the schedule history.
        $this->assertSame([0, 'NOTHING_DUE'], $this->runScheduler());
        $this->actingAs($actor);
        Livewire::test(ListSiteReleases::class)->mountAction('scheduleHistory')
            ->assertMountedActionModalSee(['#'.$scheduleId, 'Failed', 'Not published: the legal pages still hold unresolved placeholders or review markers (legal_unresolved_markers)'])
            ->assertMountedActionModalDontSee('failed its integrity check');
    }

    public function test_a_due_schedule_for_clean_legal_pages_publishes_and_other_integrity_failures_keep_their_wording(): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $release = $site->create(LegalPageFixtures::content('SCHEDULED CLEAN', ['privacy', 'terms', 'licenses']), 'Scheduled clean', $actor);
        $schedule = $site->schedule($release->id, CarbonImmutable::parse('2026-11-10 12:05:00', 'UTC'), 0, $actor);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:05:07', 'UTC'));
        $this->assertSame([0, 'PUBLISHED schedule='.$schedule->id.' revision=1'], $this->runScheduler());
        $this->get('/legal/terms')->assertOk()->assertSee('SCHEDULED CLEAN FIRST PARAGRAPH', false);
        $this->assertSame(0, AuditEvent::where('action', 'site.schedule.failed')->count());

        // A forged release whose stored hash does not match its content fails as before, without the legal reason.
        $corrupt = LegalPageFixtures::content('SCHEDULED CORRUPT', ['privacy']);
        $corruptId = DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic corrupt legal release', 'schema_version' => 5, 'content' => json_encode($corrupt),
            'content_hash' => str_repeat('c', 64), 'canonicalization_version' => CanonicalJson::VERSION, 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        $secondId = DB::table('site_publication_schedules')->insertGetId([
            'release_id' => $corruptId, 'release_content_hash' => str_repeat('c', 64), 'publish_at' => '2026-11-10 12:10:00',
            'expected_revision' => 1, 'created_by' => $actor->id, 'created_at' => '2026-11-10 12:05:07', 'state' => 'pending', 'pending_slot' => 1,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:10:03', 'UTC'));
        $this->assertSame([1, 'FAILED integrity schedule='.$secondId], $this->runScheduler());
        $this->assertArrayNotHasKey('reason', AuditEvent::where('action', 'site.schedule.failed')->sole()->context);
        $this->actingAs($actor);
        Livewire::test(ListSiteReleases::class)->mountAction('scheduleHistory')
            ->assertMountedActionModalSee('Not published: the release or publication failed its integrity check')
            ->assertMountedActionModalDontSee('legal_unresolved_markers');
    }
}
