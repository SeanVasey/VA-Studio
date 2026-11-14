<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\TrackPublicationSchedule;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\TrackPublicationSchedules;
use App\Filament\Resources\TrackResource;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** The Tracks table actions only invoke the domain services; they hold no publication rules of their own. */
class TrackSchedulingActionsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function draft(): array
    {
        $fixture = QuoteFixtures::selection();
        $fixture['track'] = app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $this->actingAs($fixture['actor']);

        return $fixture;
    }

    private function chicago(CarbonImmutable $utc): string
    {
        return $utc->setTimezone('America/Chicago')->format('Y-m-d\TH:i');
    }

    public function test_the_schedule_action_stores_the_utc_instant_and_the_cancel_action_returns_the_track_to_draft(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        $at = CarbonImmutable::now('UTC')->startOfMinute()->addHours(2);
        Livewire::test(ManageTracks::class)
            ->callTableAction('schedule', $track, ['target' => 'unlisted', 'local_time' => $this->chicago($at),
                'metadata_version' => $track->metadata_version, 'publication_version' => $track->publication_version])
            ->assertHasNoTableActionErrors();
        $schedule = TrackPublicationSchedule::sole();
        $this->assertSame([$at->format('Y-m-d H:i:s'), 'unlisted', 'pending'], [$schedule->publish_at->format('Y-m-d H:i:s'), $schedule->target_status, $schedule->state]);
        $scheduled = $track->fresh();
        $this->assertSame('scheduled', $scheduled->status);
        $this->assertStringStartsWith('Unlisted at '.TrackPublicationSchedules::describe($at), TrackResource::scheduleSummary($scheduled));
        $this->assertStringNotContainsString('NOT PUBLISHED', TrackResource::scheduleSummary($scheduled));

        Livewire::test(ManageTracks::class)
            ->callTableAction('cancel_schedule', $scheduled, ['metadata_version' => $scheduled->metadata_version, 'publication_version' => $scheduled->publication_version])
            ->assertHasNoTableActionErrors();
        $this->assertSame('draft', $track->fresh()->status);
        $this->assertNull(TrackResource::scheduleSummary($track->fresh()));
    }

    public function test_the_schedule_action_keeps_the_form_open_with_the_reason_when_the_time_cannot_be_used(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        foreach (['2027-03-14T02:30', '2026-11-01T01:30', '2020-01-01T00:00'] as $local) {
            Livewire::test(ManageTracks::class)
                ->callTableAction('schedule', $track, ['target' => 'published', 'local_time' => $local,
                    'metadata_version' => $track->metadata_version, 'publication_version' => $track->publication_version])
                ->assertHasTableActionErrors(['local_time']);
        }
        $this->assertSame(0, TrackPublicationSchedule::count());
        $this->assertSame('draft', $track->fresh()->status);
    }

    public function test_a_stale_schedule_form_is_refused_and_changes_nothing(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        Livewire::test(ManageTracks::class)
            ->callTableAction('schedule', $track, ['target' => 'published', 'local_time' => $this->chicago(CarbonImmutable::now('UTC')->addHours(3)),
                'metadata_version' => $track->metadata_version, 'publication_version' => $track->publication_version - 1])
            ->assertHasTableActionErrors(['local_time']);
        $this->assertSame('draft', $track->fresh()->status);
    }

    public function test_a_blocked_schedule_shows_why_it_has_not_gone_live_in_the_table(): void
    {
        $fixture = $this->draft();
        // The scheduler runs without a signed-in user.
        $this->app['auth']->guard()->logout();
        $track = $fixture['track'];
        $at = CarbonImmutable::now('UTC')->startOfMinute()->addMinutes(10);
        app(TrackPublicationSchedules::class)->schedule($track, $fixture['actor'], $this->chicago($at), 'published', $track->metadata_version, $track->publication_version);
        DB::table('tracks')->where('id', $track->id)->update(['genre' => null]);
        $this->travelTo($at->addMinute());
        $this->artisan('vasey:publish-scheduled-tracks')->assertExitCode(1);
        $summary = TrackResource::scheduleSummary($track->fresh());
        $this->assertStringContainsString('NOT PUBLISHED', $summary);
        $this->assertStringContainsString('Complete title, slug, artist, genre', $summary);
        $this->assertStringContainsString('(checked 1x)', $summary);
        $this->assertSame('scheduled', $track->fresh()->status);
    }

    public function test_publish_unlisted_and_change_visibility_actions_use_the_reviewed_domain_paths(): void
    {
        $fixture = $this->draft();
        $track = $fixture['track'];
        Livewire::test(ManageTracks::class)->mountTableAction('publish_unlisted', $track)->callMountedTableAction();
        $unlisted = $track->fresh();
        $this->assertSame('unlisted', $unlisted->status);
        $this->assertSame(1, AuditEvent::where('action', 'catalog.track.published_unlisted')->count());

        Livewire::test(ManageTracks::class)
            ->callTableAction('visibility', $unlisted, ['target' => 'private', 'metadata_version' => $unlisted->metadata_version, 'publication_version' => $unlisted->publication_version])
            ->assertHasNoTableActionErrors();
        $this->assertSame('private', $track->fresh()->status);
        // A private track offers neither publish action nor schedule.
        Livewire::test(ManageTracks::class)->assertTableActionHidden('publish', $track->fresh())
            ->assertTableActionHidden('publish_unlisted', $track->fresh())->assertTableActionHidden('schedule', $track->fresh());
    }
}
