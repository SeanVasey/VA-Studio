<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SitePublicationSchedule;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Filament\Resources\SiteReleaseResource\Pages\ListSiteReleases;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class SiteScheduleHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:30', 'UTC'));
    }

    private function release(string $marker, User $actor): SiteRelease
    {
        $content = SiteContentSchema::defaults();
        $content['hero']['title'] = $marker.' HERO';

        return app(SiteContent::class)->create($content, $marker.' LABEL', $actor);
    }

    private function scheduleFor(SiteRelease $release, User $actor, string $time = '2026-10-01 12:05:00'): SitePublicationSchedule
    {
        $revision = SitePublication::findOrFail(1)->revision;

        return app(SiteContent::class)->schedule($release->id, CarbonImmutable::parse($time, 'UTC'), $revision, $actor);
    }

    /** Read before assertNotified(), which pulls notifications from the session. Livewire requests move them to the claimed key. */
    private function notificationBody(string $title): string
    {
        $notification = collect(session('filament.claimed_notifications', session('filament.notifications', [])))->firstWhere('title', $title);
        $this->assertNotNull($notification, "The {$title} notification was not sent.");

        return (string) $notification['body'];
    }

    public function test_editor_schedules_a_saved_release_and_shows_it_in_the_heading_badge_and_history(): void
    {
        $actor = LicenseFixtures::admin();
        $release = $this->release('SCHEDULED UI', $actor);
        $this->actingAs($actor);
        Livewire::test(ListSiteReleases::class)
            ->assertTableActionVisible('schedulePublication', $release)
            ->assertActionHidden('cancelScheduledPublication')
            ->mountTableAction('schedulePublication', $release)
            ->assertMountedActionModalSee(['Publish at (UTC)', 'Current time: 2026-10-01 12:00 UTC.', 'expires unpublished'])
            ->setTableActionData(['publish_at' => '2026-10-01 12:05'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Publication scheduled')
            ->assertSet('expectedPublicationRevision', null)->assertSet('schedulingReleaseId', null);
        $schedule = SitePublicationSchedule::sole();
        $this->assertSame([$release->id, 'pending', 0, $actor->id], [$schedule->release_id, $schedule->state, $schedule->expected_revision, $schedule->created_by]);
        $this->assertSame('2026-10-01 12:05:00', $schedule->publish_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertSame(1, AuditEvent::where('action', 'site.schedule.created')->where('actor_id', $actor->id)->count());
        $page = Livewire::test(ListSiteReleases::class)
            ->assertSee('Scheduled: “SCHEDULED UI LABEL” (release #'.$release->id.') publishes at 2026-10-01 12:05 UTC.')
            ->assertDontSee('Overdue')
            ->assertSee('Scheduled 2026-10-01 12:05 UTC')
            ->assertTableActionHidden('schedulePublication', $release)
            ->assertActionVisible('cancelScheduledPublication');
        $page->mountAction('scheduleHistory')
            ->assertMountedActionModalSee(['#'.$schedule->id, 'SCHEDULED UI LABEL (#'.$release->id.')', 'Pending', 'Waiting for its time', $actor->name]);
    }

    public function test_schedule_form_reports_invalid_and_out_of_range_times_on_the_field_and_keeps_the_reviewed_revision(): void
    {
        $actor = LicenseFixtures::admin();
        $release = $this->release('BOUNDED UI', $actor);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('schedulePublication', $release)
            ->assertSet('expectedPublicationRevision', 0);
        foreach (['', 'not a time', '2026-02-30 10:00', '2026-10-01 12:01', '2026-09-30 12:05', '2027-10-01 12:01'] as $invalid) {
            $component->setTableActionData(['publish_at' => $invalid])->callMountedTableAction()
                ->assertHasTableActionErrors(['publish_at'])
                ->assertSet('expectedPublicationRevision', 0);
        }
        $this->assertDatabaseCount('site_publication_schedules', 0);
        // Earliest and latest whole minutes are accepted once the operator corrects the field.
        $component->setTableActionData(['publish_at' => '2026-10-01 12:02'])->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('2026-10-01 12:02:00', SitePublicationSchedule::sole()->publish_at->format('Y-m-d H:i:s'));
        app(SiteContent::class)->cancelSchedule(SitePublicationSchedule::sole()->id, $actor);
        Livewire::test(ListSiteReleases::class)->callTableAction('schedulePublication', $release, data: ['publish_at' => '2027-10-01 12:00'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('2027-10-01 12:00:00', app(SiteContent::class)->pendingSchedule()->publish_at->format('Y-m-d H:i:s'));
    }

    public static function concurrentChanges(): array
    {
        return ['another release was published' => ['publish', 'The published site changed.'],
            'this release was published' => ['publish-target', 'The published site changed.'],
            'another release was scheduled' => ['schedule', 'Another release is already scheduled.']];
    }

    #[DataProvider('concurrentChanges')]
    public function test_open_schedule_confirmation_reports_a_publication_or_schedule_made_meanwhile(string $change, string $message): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $target = $this->release('STALE SCHEDULE TARGET', $actor);
        $winner = $this->release('CONCURRENT SCHEDULE WINNER', $other);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('schedulePublication', $target)
            ->assertSet('expectedPublicationRevision', 0)->assertSet('schedulingReleaseId', $target->id);
        match ($change) {
            'publish' => app(SiteContent::class)->publish($winner->id, 0, $other),
            'publish-target' => app(SiteContent::class)->publish($target->id, 0, $other),
            'schedule' => $this->scheduleFor($winner, $other),
        };
        $schedules = SitePublicationSchedule::count();
        // The open confirmation still reaches the domain, which explains the refusal instead of silently dropping it.
        $component->setTableActionData(['publish_at' => '2026-10-01 12:05'])->callMountedTableAction()
            ->assertSet('expectedPublicationRevision', null)->assertSet('schedulingReleaseId', null);
        $this->assertStringContainsString($message, $this->notificationBody('Scheduling blocked'));
        $this->assertSame($schedules, SitePublicationSchedule::count());
        $this->assertSame(0, SitePublicationSchedule::where('release_id', $target->id)->count());
        $this->assertSame(0, AuditEvent::where('action', 'site.schedule.created')->where('actor_id', $actor->id)->count());
    }

    public function test_cancel_confirmation_names_the_reviewed_schedule_and_cancels_only_that_schedule(): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $first = $this->release('FIRST CANCEL', $actor);
        $second = $this->release('SECOND CANCEL', $other);
        $reviewed = $this->scheduleFor($first, $actor);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountAction('cancelScheduledPublication')
            ->assertMountedActionModalSee('Cancel the scheduled publication of “FIRST CANCEL LABEL” at 2026-10-01 12:05 UTC? The live site does not change.')
            ->assertSet('expectedScheduleId', $reviewed->id);
        // Meanwhile the reviewed schedule is cancelled elsewhere and a different release is scheduled.
        app(SiteContent::class)->cancelSchedule($reviewed->id, $other);
        $replacement = $this->scheduleFor($second, $other, '2026-10-01 12:10:00');
        // A re-render still describes the reviewed schedule, never the replacement the confirmation would not cancel.
        $component->call('$refresh')
            ->assertMountedActionModalSee('The scheduled publication of “FIRST CANCEL LABEL” at 2026-10-01 12:05 UTC was already resolved.')
            ->assertMountedActionModalDontSee('SECOND CANCEL LABEL');
        $component->callMountedAction()->assertNotified('Cancellation blocked')->assertSet('expectedScheduleId', null);
        $this->assertSame('pending', $replacement->fresh()->state);
        $this->assertSame($other->id, $reviewed->fresh()->resolved_by);
        Livewire::test(ListSiteReleases::class)->callAction('cancelScheduledPublication')->assertNotified('Scheduled publication cancelled');
        $this->assertSame(['cancelled', 'cancelled', $actor->id], [$replacement->fresh()->state, $replacement->fresh()->outcome, $replacement->fresh()->resolved_by]);
        $this->assertNull(app(SiteContent::class)->pendingSchedule());
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        Livewire::test(ListSiteReleases::class)->assertDontSee('Scheduled:')->assertActionHidden('cancelScheduledPublication')
            ->mountAction('scheduleHistory')
            ->assertMountedActionModalSee(['Cancelled by staff', 'by '.$actor->name, 'by '.$other->name, 'SECOND CANCEL LABEL (#'.$second->id.')']);
    }

    public static function resolutionsWithNothingPending(): array
    {
        return ['cancelled by another administrator' => ['cancel'], 'replaced by a staff publication' => ['publish']];
    }

    #[DataProvider('resolutionsWithNothingPending')]
    public function test_open_cancel_confirmation_reports_a_schedule_resolved_meanwhile_with_nothing_else_pending(string $resolution): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $reviewed = $this->scheduleFor($this->release('RESOLVED CANCEL', $actor), $actor);
        $live = $this->release('RESOLVING PUBLICATION', $other);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountAction('cancelScheduledPublication')
            ->assertSet('expectedScheduleId', $reviewed->id);
        $resolution === 'cancel' ? app(SiteContent::class)->cancelSchedule($reviewed->id, $other)
            : app(SiteContent::class)->publish($live->id, 0, $other, $reviewed->id);
        $this->assertNull(app(SiteContent::class)->pendingSchedule());
        // Nothing is pending, yet the open confirmation still reaches the domain instead of doing nothing.
        $component->callMountedAction()->assertSet('expectedScheduleId', null);
        $this->assertStringContainsString('already resolved', $this->notificationBody('Cancellation blocked'));
        $this->assertSame($other->id, $reviewed->fresh()->resolved_by);
        $this->assertSame($resolution === 'cancel' ? 'cancelled' : 'superseded', $reviewed->fresh()->state);
        Livewire::test(ListSiteReleases::class)->assertActionHidden('cancelScheduledPublication');
    }

    public function test_a_dismissed_schedule_confirmation_leaves_the_action_hidden_once_another_schedule_is_pending(): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $dismissed = $this->release('DISMISSED SCHEDULE', $actor);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('schedulePublication', $dismissed)
            ->assertSet('schedulingReleaseId', $dismissed->id)
            ->unmountTableAction();
        $this->scheduleFor($this->release('OTHER SCHEDULE', $other), $other);
        $component->call('$refresh')->assertTableActionHidden('schedulePublication', $dismissed);
        $this->assertSame(1, SitePublicationSchedule::count());
    }

    public static function manualOperations(): array
    {
        return ['publish' => ['publish', 'manual_publish', 'Replaced when staff published a release'],
            'restore' => ['rollback', 'manual_rollback', 'Replaced when staff restored a previous release']];
    }

    #[DataProvider('manualOperations')]
    public function test_manual_publication_warns_about_and_supersedes_the_pending_schedule(string $operation, string $outcome, string $reason): void
    {
        $actor = LicenseFixtures::admin();
        $site = app(SiteContent::class);
        $target = $this->release('MANUAL TARGET', $actor);
        if ($operation === 'rollback') {
            $site->publish($target->id, 0, $actor);
            $site->publish($this->release('MANUAL CURRENT', $actor)->id, 1, $actor);
        }
        $scheduled = $this->release('SUPERSEDED SCHEDULE', $actor);
        $schedule = $this->scheduleFor($scheduled, $actor);
        $revision = SitePublication::findOrFail(1)->revision;
        $this->actingAs($actor);
        Livewire::test(ListSiteReleases::class)->mountTableAction($operation, $target)
            ->assertSet('expectedScheduleId', $schedule->id)
            ->assertMountedActionModalSee('This also cancels the scheduled publication of “SUPERSEDED SCHEDULE LABEL” at 2026-10-01 12:05 UTC.')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertSet('expectedScheduleId', null);
        $this->assertSame([$revision + 1, $target->id], [SitePublication::findOrFail(1)->revision, SitePublication::findOrFail(1)->active_release_id]);
        $schedule->refresh();
        $this->assertSame(['superseded', $outcome, $actor->id, $revision + 1], [$schedule->state, $schedule->outcome, $schedule->resolved_by, $schedule->publication_revision]);
        Livewire::test(ListSiteReleases::class)->mountAction('scheduleHistory')
            ->assertMountedActionModalSee(['Superseded', $reason, '(publication revision '.($revision + 1).')']);
    }

    public function test_publication_confirmation_without_a_warning_is_rejected_once_a_schedule_appears(): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $target = $this->release('UNWARNED TARGET', $actor);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('publish', $target)
            ->assertSet('expectedScheduleId', null)
            ->assertMountedActionModalDontSee('This also cancels');
        $schedule = $this->scheduleFor($this->release('LATE SCHEDULE', $other), $other);
        $component->callMountedTableAction()->assertNotified('Publication blocked')
            ->assertSet('expectedPublicationRevision', null)->assertSet('expectedScheduleId', null);
        $this->assertSame(0, SitePublication::findOrFail(1)->revision);
        $this->assertSame('pending', $schedule->fresh()->state);
        $this->assertDatabaseCount('site_publication_revisions', 0);
    }

    public function test_actions_follow_the_live_state(): void
    {
        $actor = LicenseFixtures::admin();
        $active = $this->release('ACTIVE STATE', $actor);
        $draft = $this->release('DRAFT STATE', $actor);
        app(SiteContent::class)->publish($active->id, 0, $actor);
        $this->actingAs($actor);
        Livewire::test(ListSiteReleases::class)
            ->assertTableActionHidden('schedulePublication', $active)
            ->assertTableActionVisible('schedulePublication', $draft)
            ->assertActionVisible('scheduleHistory')
            ->mountAction('scheduleHistory')
            ->assertMountedActionModalSee('No publication has been scheduled.');
    }

    public function test_heading_warns_when_the_scheduler_is_overdue(): void
    {
        $actor = LicenseFixtures::admin();
        $this->scheduleFor($this->release('OVERDUE', $actor), $actor);
        $this->actingAs($actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:06:59', 'UTC'));
        Livewire::test(ListSiteReleases::class)->assertSee('publishes at 2026-10-01 12:05 UTC.')->assertDontSee('Overdue');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:07:01', 'UTC'));
        Livewire::test(ListSiteReleases::class)
            ->assertSee('Overdue: not yet published. Check that the scheduler runs every minute and review the application log.'
                .' It expires unpublished after 2026-10-01 13:05 UTC.');
    }

    public static function revokedPrivileges(): array
    {
        return ['staff role' => ['is_admin', false], 'verified email' => ['email_verified_at', null]];
    }

    #[DataProvider('revokedPrivileges')]
    public function test_mounted_schedule_and_cancel_actions_recheck_fresh_privileges_before_writing(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->release('MOUNTED SCHEDULE', $actor);
        $this->actingAs($actor);
        $schedule = Livewire::test(ListSiteReleases::class)->mountTableAction('schedulePublication', $draft)
            ->setTableActionData(['publish_at' => '2026-10-01 12:05']);
        $pending = $this->scheduleFor($this->release('MOUNTED CANCEL', $actor), $actor);
        $cancel = Livewire::test(ListSiteReleases::class)->mountAction('cancelScheduledPublication');
        User::whereKey($actor->id)->update([$field => $value]);
        $schedule->callMountedTableAction()->assertForbidden();
        $cancel->callMountedAction()->assertForbidden();
        $this->assertSame([$pending->id], SitePublicationSchedule::pluck('id')->all());
        $this->assertSame('pending', $pending->fresh()->state);
    }

    public function test_reactive_schedule_actions_recheck_mfa_when_policy_becomes_required(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = $this->release('MFA SCHEDULE', $actor);
        $this->actingAs($actor);
        $component = Livewire::test(ListSiteReleases::class)->mountTableAction('schedulePublication', $draft)
            ->setTableActionData(['publish_at' => '2026-10-01 12:05']);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $component->callMountedTableAction()->assertForbidden();
        $this->assertDatabaseCount('site_publication_schedules', 0);
    }
}
