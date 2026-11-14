<?php

namespace Tests\Feature\Reporting;

use App\Domain\Operations\OperationalSignals;
use App\Domain\Reporting\OperationalHealth;
use App\Filament\Widgets\UnrecordedEvidenceWidget;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * FP-028 scheduler and backup signals: the heartbeat command, tracked scheduled-command outcomes and append-only backup
 * evidence. Never recorded is Unavailable, stale or failed is Needs attention, and only staff with the panel's authority see it.
 */
class OperationalSignalsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
    }

    /** @return array<string, array<string, mixed>> */
    private function evidence(): array
    {
        return collect(app(OperationalHealth::class)->section('evidence', $this->admin)['metrics'])->keyBy('key')->all();
    }

    private function assertMetric(string $key, int|string|null $value, string $state): void
    {
        $metric = $this->evidence()[$key];
        $this->assertSame([$value, $state], [$metric['value'], $metric['state']], $key.': '.$metric['detail']);
        Livewire::test(UnrecordedEvidenceWidget::class)->assertSeeHtml('data-metric="'.$key.'" data-state="'.$state.'"');
    }

    /** @return list<Event> */
    private function scheduled(string $command): array
    {
        $this->app->make(Kernel::class)->bootstrap();

        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, $command))->values()->all();
    }

    public function test_the_heartbeat_is_unavailable_until_recorded_ok_up_to_the_threshold_and_needs_attention_after_it(): void
    {
        $this->assertMetric('scheduler_last_run', null, 'unavailable');
        $this->assertStringContainsString('has ever been recorded', $this->evidence()['scheduler_last_run']['detail']);
        // A tracked command's row is not a heartbeat.
        app(OperationalSignals::class)->recordTaskOutcome('vasey:cache-prune-expired', true);
        $this->assertMetric('scheduler_last_run', null, 'unavailable');

        $this->artisan('vasey:scheduler-heartbeat')->assertExitCode(0);
        $this->assertMetric('scheduler_last_run', 0, 'ok');
        $this->travel(300)->seconds();
        $this->assertMetric('scheduler_last_run', 300, 'ok');
        $this->travel(1)->seconds();
        $this->assertMetric('scheduler_last_run', 301, 'attention');
        // The threshold is read from the configuration.
        config(['reporting.health.scheduler_heartbeat_stale_seconds' => 600]);
        $this->assertMetric('scheduler_last_run', 301, 'ok');
        config(['reporting.health.scheduler_heartbeat_stale_seconds' => 0]);
        $this->assertMetric('scheduler_last_run', null, 'unavailable');
        config(['reporting.health.scheduler_heartbeat_stale_seconds' => 300]);
        // A new heartbeat refreshes the one row.
        $this->artisan('vasey:scheduler-heartbeat')->assertExitCode(0);
        $this->assertMetric('scheduler_last_run', 0, 'ok');
        $this->assertSame(2, DB::table('scheduler_heartbeats')->count());
    }

    public function test_a_heartbeat_more_than_a_minute_in_the_future_is_clock_skew_and_needs_attention(): void
    {
        $this->artisan('vasey:scheduler-heartbeat')->assertExitCode(0);
        $this->travel(-60)->seconds();
        $this->assertMetric('scheduler_last_run', 0, 'ok');
        $this->travel(-1)->seconds();
        $this->assertMetric('scheduler_last_run', -61, 'attention');
        $this->assertStringContainsString('61 seconds in the future', $this->evidence()['scheduler_last_run']['detail']);
        $this->travel(61)->seconds();
        $this->assertMetric('scheduler_last_run', 0, 'ok');
    }

    public function test_a_removed_or_renamed_command_row_is_not_counted_as_a_scheduled_failure(): void
    {
        $signals = app(OperationalSignals::class);
        $signals->recordTaskOutcome('vasey:retired-command', false);
        $this->assertMetric('scheduled_task_failures', null, 'unavailable');
        [$sweep] = $this->scheduled('vasey:contract-render-cache-sweep');
        $sweep->finish($this->app, 0);
        $this->assertMetric('scheduled_task_failures', 0, 'ok');
        $this->assertStringContainsString('each of the 1 commands', $this->evidence()['scheduled_task_failures']['detail']);
        $sweep->finish($this->app, 1);
        $this->assertMetric('scheduled_task_failures', 1, 'attention');
        $this->assertStringNotContainsString('vasey:retired-command', $this->evidence()['scheduled_task_failures']['detail']);
        $this->assertSame('failure', DB::table('scheduler_heartbeats')->where('task', 'vasey:retired-command')->value('last_outcome'), 'The stale row is kept, only not counted.');
    }

    public function test_a_command_gated_off_by_configuration_is_not_counted_until_enabled(): void
    {
        // vasey:upload-sessions-sweep is registered but off by default: a failure row left from before it was turned off is ignored.
        $this->assertCount(1, $this->scheduled('vasey:upload-sessions-sweep'));
        config(['media.upload_session_sweep.scheduled' => false]);
        app(OperationalSignals::class)->recordTaskOutcome('vasey:upload-sessions-sweep', false);
        $this->assertMetric('scheduled_task_failures', null, 'unavailable');
        config(['media.upload_session_sweep.scheduled' => true]);
        $this->assertMetric('scheduled_task_failures', 1, 'attention');
        $this->assertStringContainsString('vasey:upload-sessions-sweep', $this->evidence()['scheduled_task_failures']['detail']);
        config(['media.upload_session_sweep.scheduled' => false]);
        [$sweep] = $this->scheduled('vasey:contract-render-cache-sweep');
        $sweep->finish($this->app, 0);
        $this->assertMetric('scheduled_task_failures', 0, 'ok');
    }

    public function test_every_scheduled_command_is_registered_and_tracked_and_a_failed_latest_run_needs_attention(): void
    {
        $heartbeat = $this->scheduled('vasey:scheduler-heartbeat');
        $this->assertCount(1, $heartbeat);
        $this->assertSame('* * * * *', $heartbeat[0]->expression);
        $this->assertArrayHasKey('vasey:scheduler-heartbeat', Artisan::all());
        $this->assertArrayHasKey('vasey:backup-record', Artisan::all());

        // Expired customer data exports are purged every 15 minutes off minute zero, with a short overlap lock (CX review F4).
        $prune = $this->scheduled('vasey:customer-data-exports-prune');
        $this->assertCount(1, $prune);
        $this->assertSame(['7,22,37,52 * * * *', true, 10], [$prune[0]->expression, $prune[0]->withoutOverlapping, $prune[0]->expiresAt]);

        $this->assertMetric('scheduled_task_failures', null, 'unavailable');
        $tracked = ['vasey:publish-scheduled-site-release', 'vasey:cache-prune-expired', 'vasey:contract-render-cache-sweep', 'vasey:customer-data-exports-prune'];
        foreach ($tracked as $command) {
            [$event] = $this->scheduled($command);
            $event->finish($this->app, 0);
        }
        $this->assertMetric('scheduled_task_failures', 0, 'ok');
        $this->assertEqualsCanonicalizing($tracked, DB::table('scheduler_heartbeats')->pluck('task')->all());

        [$sweep] = $this->scheduled('vasey:contract-render-cache-sweep');
        $sweep->finish($this->app, 1);
        $this->assertMetric('scheduled_task_failures', 1, 'attention');
        $this->assertStringContainsString('vasey:contract-render-cache-sweep', $this->evidence()['scheduled_task_failures']['detail']);
        $row = DB::table('scheduler_heartbeats')->where('task', 'vasey:contract-render-cache-sweep')->sole();
        $this->assertNotNull($row->last_success_at, 'A failure keeps the last success time.');
        $this->assertNotNull($row->last_failure_at);
        // The next successful run clears the attention; the heartbeat row is never counted as a command.
        $this->artisan('vasey:scheduler-heartbeat')->assertExitCode(0);
        $sweep->finish($this->app, 0);
        $this->assertMetric('scheduled_task_failures', 0, 'ok');
        $this->assertStringContainsString('4 commands', $this->evidence()['scheduled_task_failures']['detail']);
    }

    public function test_backup_record_appends_valid_evidence_and_refuses_malformed_input_without_echoing_it(): void
    {
        $this->artisan('vasey:backup-record', ['--kind' => 'daily', '--status' => 'ok', '--bytes' => '123456', '--sha256' => self::SHA])->assertExitCode(0);
        $this->artisan('vasey:backup-record', ['--kind' => 'daily', '--status' => 'failed'])->assertExitCode(0);
        $this->artisan('vasey:backup-record', ['--kind' => 'restore-drill', '--status' => 'ok'])->assertExitCode(0);
        $this->assertSame([['daily', 'ok', 123456, self::SHA], ['daily', 'failed', null, null], ['restore-drill', 'ok', null, null]],
            DB::table('backup_evidence')->orderBy('id')->get()->map(fn ($r) => [$r->kind, $r->status, $r->bytes === null ? null : (int) $r->bytes, $r->sha256])->all());

        $ok = ['--kind' => 'daily', '--status' => 'ok', '--bytes' => '10', '--sha256' => self::SHA];
        foreach ([
            'unknown kind' => [...$ok, '--kind' => 'weekly'],
            'kind case' => [...$ok, '--kind' => 'Daily'],
            'missing kind' => array_diff_key($ok, ['--kind' => 1]),
            'unknown status' => [...$ok, '--status' => 'success'],
            'missing status' => array_diff_key($ok, ['--status' => 1]),
            'negative bytes' => [...$ok, '--bytes' => '-1'],
            'decimal bytes' => [...$ok, '--bytes' => '1.5'],
            'leading zero bytes' => [...$ok, '--bytes' => '010'],
            'bytes with unit' => [...$ok, '--bytes' => '10GB'],
            'oversized bytes' => [...$ok, '--bytes' => '99999999999999999'],
            'zero bytes for a successful daily backup' => [...$ok, '--bytes' => '0'],
            'short sha' => [...$ok, '--sha256' => substr(self::SHA, 1)],
            'uppercase sha' => [...$ok, '--sha256' => strtoupper(self::SHA)],
            'non-hex sha' => [...$ok, '--sha256' => str_repeat('g', 64)],
            'sha with a path' => [...$ok, '--sha256' => self::SHA.' /var/backups/x'],
            'successful daily without bytes' => array_diff_key($ok, ['--bytes' => 1]),
            'successful daily without sha' => array_diff_key($ok, ['--sha256' => 1]),
        ] as $case => $options) {
            $exit = Artisan::call('vasey:backup-record', $options);
            $this->assertSame(2, $exit, $case);
            $output = Artisan::output();
            $this->assertStringContainsString('--', $output, $case);
            // The refused value is never echoed (the valid fixture values are not refused values).
            foreach ($options as $value) {
                if (! in_array($value, $ok, true)) {
                    $this->assertStringNotContainsString($value, $output, $case);
                }
            }
        }
        $this->assertSame(3, DB::table('backup_evidence')->count());
    }

    public function test_backup_evidence_is_append_only_and_the_table_refuses_malformed_rows(): void
    {
        app(OperationalSignals::class)->recordBackup('daily', 'ok', '42', self::SHA);
        $row = (array) DB::table('backup_evidence')->sole();
        $insert = fn (array $values) => DB::table('backup_evidence')->insert([...['kind' => 'daily', 'status' => 'failed', 'bytes' => null,
            'sha256' => null, 'recorded_at' => '2026-11-09 00:00:00'], ...$values]);
        foreach ([
            'update' => fn () => DB::table('backup_evidence')->where('id', $row['id'])->update(['status' => 'failed']),
            'delete' => fn () => DB::table('backup_evidence')->where('id', $row['id'])->delete(),
            'unknown kind' => fn () => $insert(['kind' => 'weekly']),
            'unknown status' => fn () => $insert(['status' => 'success']),
            'upper-case kind' => fn () => $insert(['kind' => 'DAILY']),
            'upper-case status' => fn () => $insert(['status' => 'FAILED']),
            'text bytes' => fn () => $insert(['bytes' => 'lots']),
            'ok daily with zero bytes' => fn () => $insert(['status' => 'ok', 'bytes' => 0, 'sha256' => self::SHA]),
            'short sha' => fn () => $insert(['sha256' => 'abc']),
            'uppercase sha' => fn () => $insert(['sha256' => strtoupper(self::SHA)]),
            'non-hex sha' => fn () => $insert(['sha256' => str_repeat('z', 64)]),
            'ok daily without evidence' => fn () => $insert(['status' => 'ok']),
        ] as $case => $write) {
            try {
                $write();
                $this->fail('Backup evidence write admitted: '.$case);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($row, (array) DB::table('backup_evidence')->sole());
    }

    public function test_backup_and_restore_drill_metrics_cover_never_ok_stale_and_failed(): void
    {
        $this->assertMetric('backup_last_success', null, 'unavailable');
        $this->assertMetric('restore_drill_last_success', null, 'unavailable');
        $signals = app(OperationalSignals::class);

        $signals->recordBackup('daily', 'ok', '42', self::SHA);
        $this->assertMetric('backup_last_success', 0, 'ok');
        $this->assertMetric('restore_drill_last_success', null, 'unavailable');
        $this->travel(26)->hours();
        $this->assertMetric('backup_last_success', 26, 'ok');
        $this->travel(1)->seconds();
        $this->assertMetric('backup_last_success', 26, 'attention');
        config(['reporting.health.backup_stale_hours' => 48]);
        $this->assertMetric('backup_last_success', 26, 'ok');
        // A failed latest run needs attention even while the last success is recent.
        $signals->recordBackup('daily', 'failed', null, null);
        $this->assertMetric('backup_last_success', 26, 'attention');
        $this->assertStringContainsString('failed', $this->evidence()['backup_last_success']['detail']);
        $signals->recordBackup('daily', 'ok', '43', self::SHA);
        $this->assertMetric('backup_last_success', 0, 'ok');
        config(['reporting.health.backup_stale_hours' => 0]);
        $this->assertMetric('backup_last_success', null, 'unavailable');

        // Restore drills: a failure with no success ever recorded needs attention and shows "never", not zero.
        $signals->recordBackup('restore-drill', 'failed', null, null);
        $this->assertMetric('restore_drill_last_success', 'never', 'attention');
        $signals->recordBackup('restore-drill', 'ok', null, null);
        $this->assertMetric('restore_drill_last_success', 0, 'ok');
        $this->travel(35)->days();
        $this->assertMetric('restore_drill_last_success', 35, 'ok');
        $this->travel(1)->seconds();
        $this->assertMetric('restore_drill_last_success', 35, 'attention');
        // Host health stays outside the application.
        $this->assertMetric('host_resources', null, 'unavailable');
        $this->assertStringContainsString('Forge', $this->evidence()['host_resources']['detail']);
    }

    public function test_an_unreadable_signal_table_is_unavailable_and_only_staff_see_the_section(): void
    {
        $this->artisan('vasey:scheduler-heartbeat')->assertExitCode(0);
        app(OperationalSignals::class)->recordBackup('daily', 'ok', '1', self::SHA);
        Livewire::test(UnrecordedEvidenceWidget::class)->assertSee('Scheduler last run')->assertSee('Last successful backup')
            ->assertSeeHtml('data-metric="scheduler_last_run" data-state="ok"')->assertSeeHtml('data-metric="backup_last_success" data-state="ok"');

        foreach ([null, User::factory()->create()] as $actor) {
            $actor === null ? auth()->logout() : $this->actingAs($actor);
            Livewire::test(UnrecordedEvidenceWidget::class)->assertForbidden();
        }
        $this->actingAs($this->admin);

        DB::statement('DROP TABLE scheduler_heartbeats');
        $this->artisan('vasey:scheduler-heartbeat')->assertExitCode(1);
        foreach (['scheduler_last_run', 'scheduled_task_failures'] as $key) {
            $this->assertMetric($key, null, 'unavailable');
        }
        $this->assertMetric('backup_last_success', 0, 'ok');
    }
}
