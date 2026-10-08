<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the real scripts/ops/run-test-commerce-pipeline.sh against a scripted stand-in for
 * `php artisan`, so cursor following, page bounds, cadence, failure handling, locking and log
 * redaction are exercised without a database or any provider.
 */
class TestCommercePipelineRunnerTest extends TestCase
{
    private const U1 = '11111111-1111-4111-8111-111111111111';

    private const U2 = '22222222-2222-4222-8222-222222222222';

    private const U3 = '33333333-3333-4333-8333-333333333333';

    private const U4 = '44444444-4444-4444-8444-444444444444';

    private const U5 = '55555555-5555-4555-8555-555555555555';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/va-pipeline-runner-'.bin2hex(random_bytes(8));
        foreach (['', '/app', '/scenario', '/state'] as $sub) {
            mkdir($this->dir.$sub, 0700);
        }
        touch($this->dir.'/app/artisan');
        // Stand-in PHP binary: records each call and replays scripted pages.
        file_put_contents($this->dir.'/fake-php', <<<'SH'
            #!/usr/bin/env bash
            scenario="$FAKE_SCENARIO"; command="$2"; key=start
            for argument in "$@"; do case "$argument" in --after=*) key="${argument#--after=}" ;; esac; done
            printf '%s\n' "${*:2}" >> "$scenario/calls.log"
            directory="$scenario/$command"; mkdir -p "$directory"
            if [[ "$command" == vasey:process-stripe-receipts ]]; then
              count=$(( $(cat "$directory/count" 2>/dev/null || echo 0) + 1 )); echo "$count" > "$directory/count"; key="$count"
            fi
            if [[ -f "$directory/$key.out" ]]; then cat "$directory/$key.out"; elif [[ -f "$directory/default.out" ]]; then cat "$directory/default.out"; fi
            echo 'diagnostic noise on stderr' >&2
            exit "$(cat "$directory/exit" 2>/dev/null || echo 0)"
            SH);
        chmod($this->dir.'/fake-php', 0700);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->dir]))->run();
        parent::tearDown();
    }

    private function page(string $command, string $key, array $lines, int $exit = 0): void
    {
        @mkdir($this->dir.'/scenario/'.$command, 0700);
        file_put_contents($this->dir.'/scenario/'.$command.'/'.$key.'.out', implode("\n", $lines).($lines === [] ? '' : "\n"));
        if ($exit !== 0) {
            file_put_contents($this->dir.'/scenario/'.$command.'/exit', (string) $exit);
        }
    }

    private function sweep(array $env = [], array $arguments = []): Process
    {
        $process = new Process([dirname(__DIR__, 2).'/scripts/ops/run-test-commerce-pipeline.sh', ...$arguments], null, array_replace([
            'APP_ROOT' => $this->dir.'/app', 'PHP_BIN' => $this->dir.'/fake-php', 'STATE_DIR' => $this->dir.'/state',
            'FAKE_SCENARIO' => $this->dir.'/scenario', 'PAGE_LIMIT' => '2', 'CONTRACT_PAGE_LIMIT' => '2', 'MAX_PAGES' => '20',
            'RECONCILE_INTERVAL_SECONDS' => '0', 'LOOP_SLEEP_SECONDS' => '1',
        ], $env));
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    /** @return list<string> */
    private function calls(): array
    {
        $file = $this->dir.'/scenario/calls.log';

        return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    }

    public function test_cursor_stages_follow_next_after_until_a_short_page(): void
    {
        $this->page('vasey:finalize-test-payments', 'start', [self::U1.' paid', self::U2.' paid', 'NEXT_AFTER='.self::U2]);
        $this->page('vasey:finalize-test-payments', self::U2, [self::U3.' paid', self::U4.' paid_exception', 'NEXT_AFTER='.self::U4]);
        $this->page('vasey:finalize-test-payments', self::U4, [self::U5.' retry', 'NEXT_AFTER='.self::U5]);
        $process = $this->sweep();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput());
        $finalize = array_values(array_filter($this->calls(), fn ($call) => str_starts_with($call, 'vasey:finalize-test-payments')));
        $this->assertSame([
            'vasey:finalize-test-payments --limit=2 --no-ansi --no-interaction',
            'vasey:finalize-test-payments --limit=2 --after='.self::U2.' --no-ansi --no-interaction',
            'vasey:finalize-test-payments --limit=2 --after='.self::U4.' --no-ansi --no-interaction',
        ], $finalize);
        $output = $process->getOutput();
        foreach ([self::U1.' paid', self::U4.' paid_exception', self::U5.' retry', 'done items=5 pages=3'] as $expected) {
            $this->assertStringContainsString('finalize '.$expected, $output);
        }
        $this->assertStringNotContainsString('diagnostic noise', $output);
    }

    public function test_every_stage_runs_in_order_and_only_the_five_read_or_local_commands_are_called(): void
    {
        $process = $this->sweep();

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame([
            'vasey:process-stripe-receipts --limit=2 --no-ansi --no-interaction',
            'vasey:reconcile-test-payments --limit=2 --no-ansi --no-interaction',
            'vasey:finalize-test-payments --limit=2 --no-ansi --no-interaction',
            'vasey:issue-test-contracts --limit=2 --no-ansi --no-interaction',
            'vasey:activate-test-fulfillment --limit=2 --no-ansi --no-interaction',
        ], $this->calls());
        $this->assertStringNotContainsString('control-test-delivery', implode("\n", $this->calls()));
        $this->assertStringNotContainsString('reconcile-test-checkout', implode("\n", $this->calls()));
        $this->assertStringContainsString('sweep end status=0', $process->getOutput());
    }

    public function test_receipts_repeat_while_pages_are_full(): void
    {
        $this->page('vasey:process-stripe-receipts', '1', ['11 awaiting_finalization', '12 unsupported']);
        $this->page('vasey:process-stripe-receipts', '2', ['13 retry', '14 expired']);
        $this->page('vasey:process-stripe-receipts', '3', ['15 failed']);
        $process = $this->sweep();

        $this->assertCount(3, array_filter($this->calls(), fn ($call) => str_starts_with($call, 'vasey:process-stripe-receipts')));
        $this->assertStringContainsString('receipts 11 awaiting_finalization', $process->getOutput());
        $this->assertStringContainsString('receipts done items=5 pages=3', $process->getOutput());
    }

    public function test_page_bound_stops_a_sweep_and_reconcile_resumes_from_the_saved_cursor(): void
    {
        $this->page('vasey:reconcile-test-payments', 'default', [self::U1.' expired', self::U2.' expired', 'NEXT_AFTER='.self::U2]);
        $process = $this->sweep(['MAX_PAGES' => '2']);

        $reconcile = fn () => array_values(array_filter($this->calls(), fn ($call) => str_starts_with($call, 'vasey:reconcile-test-payments')));
        $this->assertCount(2, $reconcile());
        $this->assertStringContainsString('reconcile page bound reached items=4 pages=2', $process->getOutput());
        $this->assertSame(self::U2, file_get_contents($this->dir.'/state/reconcile.cursor'));

        // The next sweep starts after the saved cursor rather than re-reading the first page.
        $this->page('vasey:reconcile-test-payments', self::U2, [self::U3.' open', 'NEXT_AFTER='.self::U3]);
        $this->sweep(['MAX_PAGES' => '2']);
        $this->assertSame('vasey:reconcile-test-payments --limit=2 --after='.self::U2.' --no-ansi --no-interaction', $reconcile()[2]);
        $this->assertFileDoesNotExist($this->dir.'/state/reconcile.cursor');
    }

    public function test_reconcile_runs_at_most_once_per_interval(): void
    {
        $this->sweep(['RECONCILE_INTERVAL_SECONDS' => '900']);
        $second = $this->sweep(['RECONCILE_INTERVAL_SECONDS' => '900']);

        $this->assertCount(1, array_filter($this->calls(), fn ($call) => str_starts_with($call, 'vasey:reconcile-test-payments')));
        $this->assertCount(2, array_filter($this->calls(), fn ($call) => str_starts_with($call, 'vasey:finalize-test-payments')));
        $this->assertStringContainsString('reconcile not due (interval 900s)', $second->getOutput());
    }

    public function test_a_failing_stage_fails_the_sweep_without_skipping_later_stages(): void
    {
        $this->page('vasey:finalize-test-payments', 'start', [], 1);
        $this->page('vasey:activate-test-fulfillment', 'start', [self::U1.' pending_contracts', 'NEXT_AFTER='.self::U1]);
        $process = $this->sweep();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('finalize FAILED', $process->getOutput());
        $this->assertStringContainsString('activate '.self::U1.' pending_contracts', $process->getOutput());
        $this->assertStringContainsString('sweep end status=1', $process->getOutput());
    }

    public function test_unrecognised_command_output_is_counted_but_never_logged(): void
    {
        $this->page('vasey:issue-test-contracts', 'start', [
            self::U1.' ready', 'sk_test_SECRETVALUE leaked', '/var/www/storage/app/private/contracts/x.pdf',
            self::U2.' Not A Bounded Outcome', 'NEXT_AFTER=not-a-uuid',
        ]);
        $process = $this->sweep();

        $output = $process->getOutput();
        $this->assertStringContainsString('contracts '.self::U1.' ready', $output);
        $this->assertStringContainsString('contracts suppressed 4 unrecognised output line(s)', $output);
        foreach (['sk_test_SECRETVALUE', '/var/www', 'Not A Bounded', 'not-a-uuid'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
    }

    public function test_a_concurrent_sweep_is_skipped_by_the_lock(): void
    {
        touch($this->dir.'/state/pipeline.lock');
        $holder = new Process(['flock', $this->dir.'/state/pipeline.lock', 'sleep', '10']);
        $holder->start();
        try {
            $deadline = microtime(true) + 5;
            do {
                usleep(50000);
                $probe = new Process(['flock', '-n', $this->dir.'/state/pipeline.lock', 'true']);
                $probe->run();
            } while ($probe->getExitCode() === 0 && microtime(true) < $deadline);
            $process = $this->sweep();
        } finally {
            $holder->stop(0);
        }

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('another sweep holds the lock; skipped', $process->getOutput());
        $this->assertSame([], $this->calls());
    }

    public function test_loop_mode_is_bounded_by_its_iteration_count(): void
    {
        $process = $this->sweep(['LOOP_ITERATIONS' => '2'], ['--loop']);

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame(2, substr_count($process->getOutput(), 'sweep start'));
        $this->assertCount(2, array_filter($this->calls(), fn ($call) => str_starts_with($call, 'vasey:activate-test-fulfillment')));
    }

    public function test_invalid_settings_and_arguments_are_refused_before_any_command(): void
    {
        foreach ([[['PAGE_LIMIT' => '0'], []], [['PAGE_LIMIT' => '101'], []], [['MAX_PAGES' => 'x'], []], [[], ['--forever']]] as [$env, $arguments]) {
            $this->assertSame(2, $this->sweep($env, $arguments)->getExitCode());
        }
        $this->assertSame(2, $this->sweep(['APP_ROOT' => $this->dir.'/scenario'])->getExitCode());
        $this->assertSame([], $this->calls());
    }
}
