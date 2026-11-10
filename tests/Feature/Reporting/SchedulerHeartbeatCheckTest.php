<?php

namespace Tests\Feature\Reporting;

use App\Domain\Operations\OperationalSignals;
use App\Domain\Operations\SchedulerWatch;
use App\Domain\Operations\SchedulerWatchMail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalMailFixtures as M;
use Tests\TestCase;

/**
 * FP-028 scheduler dead-man's switch: `vasey:scheduler-heartbeat-check` run from host cron. Stale versus fresh, never recorded,
 * the threshold boundary, one alert per stale episode, the recovery note, and disabled-by-default sending nothing. Mail is the
 * framework fake; the transactional-mail policy still decides whether sending is admitted.
 */
class SchedulerHeartbeatCheckTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const RECIPIENT = 'alerts@example.com';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->directory = storage_path('framework/testing/scheduler-watch-'.bin2hex(random_bytes(6)));
        config(['reporting.scheduler_alert.state_path' => $this->directory.'/state.json']);
        $this->beforeApplicationDestroyed(function (): void {
            (new Filesystem)->deleteDirectory($this->directory);
            foreach (glob(storage_path('framework/testing/mail-preflight-*'), GLOB_ONLYDIR) ?: [] as $dir) {
                (new Filesystem)->deleteDirectory($dir);
            }
        });
        Mail::fake();
    }

    private function enable(): void
    {
        M::configure();
        config(['reporting.scheduler_alert.recipient' => self::RECIPIENT]);
    }

    private function heartbeatAgo(?int $seconds): void
    {
        DB::table('scheduler_heartbeats')->where('task', OperationalSignals::HEARTBEAT_TASK)->delete();
        if ($seconds !== null) {
            $at = now()->utc()->subSeconds($seconds)->format('Y-m-d H:i:s');
            DB::table('scheduler_heartbeats')->insert(['task' => OperationalSignals::HEARTBEAT_TASK, 'last_outcome' => 'success',
                'last_success_at' => $at, 'last_failure_at' => null, 'updated_at' => $at]);
        }
    }

    /** @return array{0: int, 1: string} */
    private function check(string ...$flags): array
    {
        $code = Artisan::call('vasey:scheduler-heartbeat-check', array_fill_keys($flags, true) + ['--max-age' => '900']);

        return [$code, Artisan::output()];
    }

    private function sent(string $kind): int
    {
        return Mail::mailer(M::MAILER)->sent(SchedulerWatchMail::class, fn (SchedulerWatchMail $mail) => $mail->kind === $kind)->count();
    }

    public function test_a_fresh_heartbeat_exits_zero_and_a_stale_one_exits_nonzero(): void
    {
        $this->heartbeatAgo(30);
        [$code, $output] = $this->check();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('OK scheduler heartbeat 30s old', $output);

        $this->heartbeatAgo(2000);
        [$code, $output] = $this->check();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('STALE', $output);
        $this->assertStringContainsString('2000s old', $output);
    }

    public function test_no_heartbeat_at_all_is_stale(): void
    {
        $this->heartbeatAgo(null);
        [$code, $output] = $this->check();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('none recorded', $output);
    }

    public function test_the_threshold_boundary_is_inclusive_like_the_dashboard(): void
    {
        $this->heartbeatAgo(900);
        $this->assertSame(0, $this->check()[0], 'exactly the limit is still fresh');
        $this->heartbeatAgo(901);
        $this->assertSame(1, $this->check()[0], 'one second past the limit is stale');
    }

    public function test_a_future_dated_heartbeat_is_not_proof_the_scheduler_runs(): void
    {
        $this->heartbeatAgo(-30);
        $this->assertSame(0, $this->check()[0], 'small skew is tolerated');
        $this->heartbeatAgo(-61);
        [$code, $output] = $this->check();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('future dated', $output);
    }

    public function test_a_bad_max_age_is_refused_and_an_unreadable_table_is_unknown(): void
    {
        foreach (['59', '86401', 'abc', '9.5'] as $value) {
            $this->assertSame(3, Artisan::call('vasey:scheduler-heartbeat-check', ['--max-age' => $value]), $value);
        }
        Schema::drop('scheduler_heartbeats');
        $this->enable();
        $this->assertSame(3, Artisan::call('vasey:scheduler-heartbeat-check', ['--notify' => true]));
        $this->assertStringContainsString('UNKNOWN', Artisan::output());
        Mail::assertNothingSent();
    }

    public function test_disabled_by_default_sends_nothing_even_with_notify(): void
    {
        $this->assertNull(config('reporting.scheduler_alert.recipient'));
        $this->heartbeatAgo(5000);
        [$code, $output] = $this->check('--notify');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('alert: disabled', $output);
        Mail::assertNothingSent();
        $this->assertFileDoesNotExist($this->directory.'/state.json');

        // A recipient without an admitted mail configuration sends nothing either.
        config(['reporting.scheduler_alert.recipient' => self::RECIPIENT]);
        $this->check('--notify');
        Mail::assertNothingSent();
        // An invalid recipient is the same as none.
        $this->enable();
        config(['reporting.scheduler_alert.recipient' => 'Not An Address']);
        $this->assertSame('disabled', app(SchedulerWatch::class)->run(900, true)['action']);
        Mail::assertNothingSent();
    }

    public function test_without_notify_a_stale_scheduler_sends_nothing_even_when_configured(): void
    {
        $this->enable();
        $this->heartbeatAgo(5000);
        $this->check();
        Mail::assertNothingSent();
    }

    public function test_one_alert_per_stale_episode_then_one_recovery_note(): void
    {
        $this->enable();
        $this->heartbeatAgo(2000);
        $this->assertStringContainsString('alert: alerted', $this->check('--notify')[1]);
        $this->assertSame(1, $this->sent('stale'));

        foreach ([1, 2, 3] as $minutes) {
            $this->travel($minutes * 5)->minutes();
            $this->assertSame(1, $this->check('--notify')[0]);
        }
        $this->assertSame(1, $this->sent('stale'), 'repeat runs inside the same episode send nothing');

        // The scheduler resumes: one recovery note, once.
        $this->heartbeatAgo(10);
        $this->assertStringContainsString('alert: recovered', $this->check('--notify')[1]);
        $this->assertSame(1, $this->sent('recovered'));
        $this->check('--notify');
        $this->assertSame(1, $this->sent('recovered'));
        $this->assertSame(1, $this->sent('stale'));

        // A second stale episode alerts again.
        $this->travel(2)->hours();
        $this->check('--notify');
        $this->assertSame(2, $this->sent('stale'));
    }

    public function test_the_message_goes_to_the_configured_recipient_from_the_configured_sender_without_leaking(): void
    {
        $this->enable();
        $this->heartbeatAgo(2000);
        $this->check('--notify');
        $mails = Mail::mailer(M::MAILER)->sent(SchedulerWatchMail::class);
        $this->assertCount(1, $mails);
        $mail = $mails->first();
        $this->assertTrue($mail->hasTo(self::RECIPIENT));
        $this->assertTrue($mail->hasFrom(M::SENDER));
        $this->assertStringContainsString('has stopped', $mail->render());
        $this->assertStringNotContainsString('http', $mail->render());
    }

    public function test_a_stale_episode_that_was_never_announced_gets_no_recovery_note(): void
    {
        $this->heartbeatAgo(2000);
        $this->check('--notify');  // disabled: no state, nothing sent
        $this->enable();
        $this->heartbeatAgo(5);
        $this->check('--notify');
        Mail::assertNothingSent();
    }

    public function test_an_episode_whose_alert_never_went_out_gets_no_recovery_note(): void
    {
        config(['reporting.scheduler_alert.recipient' => self::RECIPIENT]);  // mail policy not admitted: the alert fails
        $this->heartbeatAgo(2000);
        $this->assertSame('alert_failed', app(SchedulerWatch::class)->run(900, true)['action']);
        $this->enable();
        $this->heartbeatAgo(5);
        $this->assertSame('none', app(SchedulerWatch::class)->run(900, true)['action']);
        Mail::assertNothingSent();
    }

    public function test_a_failed_handoff_is_retried_a_bounded_number_of_times(): void
    {
        config(['reporting.scheduler_alert.recipient' => self::RECIPIENT]);  // mail policy not admitted: every attempt fails
        $this->heartbeatAgo(2000);
        $actions = [];
        for ($i = 0; $i < 5; $i++) {
            $actions[] = app(SchedulerWatch::class)->run(900, true)['action'];
        }
        $this->assertSame(['alert_failed', 'alert_failed', 'alert_failed', 'alert_given_up', 'alert_given_up'], $actions);
        Mail::assertNothingSent();
    }

    public function test_an_unwritable_episode_state_sends_nothing(): void
    {
        $this->enable();
        config(['reporting.scheduler_alert.state_path' => '/proc/vasey-no-such-dir/state.json']);
        $this->heartbeatAgo(2000);
        $this->assertSame(3, $this->check('--notify')[0]);
        Mail::assertNothingSent();
    }

    public function test_the_check_is_not_a_scheduled_command(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');
        $this->assertStringNotContainsString('scheduler-heartbeat-check', $scheduled, 'a dead-man\'s switch that depends on the scheduler cannot fire when it stops');
    }
}
