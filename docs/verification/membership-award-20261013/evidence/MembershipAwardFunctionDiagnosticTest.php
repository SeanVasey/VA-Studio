<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MembershipAwards;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;
use Throwable;

/** One disposable diagnostic. It never changes statement consumers, resets global counters, or changes the operation budget. */
class MembershipAwardFunctionDiagnosticTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_public_award_reports_current_thread_statement_cost_and_preserves_its_real_outcome(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This diagnostic requires the isolated native MySQL database.');
        }
        $this->awardSetup();
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $pdo = DB::connection()->getPdo();
        $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $statement = $pdo->prepare('SELECT THREAD_ID, INSTRUMENTED, HISTORY FROM performance_schema.threads WHERE PROCESSLIST_ID = ?');
        $statement->execute([$connectionId]);
        $thread = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($thread);
        $threadId = (int) $thread['THREAD_ID'];
        $sources = $this->sources();
        $metadata = [
            'mysql_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
            'connection_id' => $connectionId,
            'thread' => $thread,
            'consumers' => $pdo->query("SELECT NAME, ENABLED FROM performance_schema.setup_consumers WHERE NAME LIKE 'events_statements%' OR NAME IN ('global_instrumentation', 'thread_instrumentation') ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC),
            'history_long_capacity' => (int) $pdo->query('SELECT @@performance_schema_events_statements_history_long_size')->fetchColumn(),
            'statement_instruments' => $pdo->query("SELECT NAME, ENABLED, TIMED FROM performance_schema.setup_instruments WHERE NAME LIKE 'statement/%' ORDER BY NAME")->fetchAll(PDO::FETCH_ASSOC),
        ];
        $statement = $pdo->prepare('SELECT COALESCE(MAX(EVENT_ID), 0) FROM performance_schema.events_statements_history_long WHERE THREAD_ID = ?');
        $statement->execute([$threadId]);
        $historyStart = (int) $statement->fetchColumn();
        $before = $this->summary($pdo, $threadId);
        $GLOBALS['va_membership_schema_profile'] = [];
        $GLOBALS['va_membership_schema_profile_enabled'] = true;
        $started = hrtime(true);
        $result = null;
        $error = null;
        try {
            // This is the ordinary public entry point, with its original 10-second budget untouched.
            $result = (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $elapsedNs = hrtime(true) - $started;
        $GLOBALS['va_membership_schema_profile_enabled'] = false;
        $functionCosts = $GLOBALS['va_membership_schema_profile'];
        $after = $this->summary($pdo, $threadId);
        $deltas = [];
        foreach ($after as $event => $values) {
            $count = $values['count'] - ($before[$event]['count'] ?? 0);
            $timer = $values['timer_ps'] - ($before[$event]['timer_ps'] ?? 0);
            if ($count !== 0 || $timer !== 0) {
                $deltas[] = ['event_name' => $event, 'count' => $count, 'timer_ps' => $timer, 'server_seconds' => $timer / 1_000_000_000_000];
            }
        }
        usort($deltas, fn (array $one, array $two): int => $two['timer_ps'] <=> $one['timer_ps']);
        $statement = $pdo->prepare('SELECT EVENT_ID, EVENT_NAME, DIGEST, DIGEST_TEXT, TIMER_WAIT, MYSQL_ERRNO FROM performance_schema.events_statements_history_long WHERE THREAD_ID = ? AND EVENT_ID > ? ORDER BY EVENT_ID');
        $statement->execute([$threadId, $historyStart]);
        $history = $statement->fetchAll(PDO::FETCH_ASSOC);
        $digests = [];
        foreach ($history as $entry) {
            $key = $entry['EVENT_NAME'].':'.($entry['DIGEST'] ?? 'NULL');
            $digests[$key] ??= ['event_name' => $entry['EVENT_NAME'], 'digest' => $entry['DIGEST'], 'digest_text' => $entry['DIGEST_TEXT'],
                'count' => 0, 'timer_ps' => 0, 'errors' => 0];
            $digests[$key]['count']++;
            $digests[$key]['timer_ps'] += (int) $entry['TIMER_WAIT'];
            $digests[$key]['errors'] += (int) $entry['MYSQL_ERRNO'] !== 0 ? 1 : 0;
        }
        $digests = array_values($digests);
        usort($digests, fn (array $one, array $two): int => $two['timer_ps'] <=> $one['timer_ps']);
        $report = [
            'purpose' => 'single synthetic native owned-award diagnostic; no host or provider verification',
            'metadata' => $metadata,
            'wall_seconds' => $elapsedNs / 1_000_000_000,
            'outcome' => ['returned' => $error === null, 'error_class' => $error === null ? null : get_class($error),
                'reason' => $error !== null && property_exists($error, 'reason') ? $error->reason : null,
                'durable_periods' => DB::table('production_membership_paid_periods')->count(),
                'durable_award_events' => DB::table('production_membership_credit_events')->where('kind', 'award')->count(),
                'transaction_level' => DB::transactionLevel(), 'pdo_transaction' => $pdo->inTransaction()],
            'source_sha256_before' => $sources,
            'source_sha256_after' => $this->sources(),
            'function_costs' => $functionCosts,
            'event_name_deltas' => $deltas,
            'sql_event_count' => array_sum(array_column(array_filter($deltas, fn (array $row): bool => str_starts_with($row['event_name'], 'statement/sql/')), 'count')),
            'history' => ['start_event_id' => $historyStart, 'observed_rows' => count($history), 'digests' => $digests],
            'measurement_limits' => [
                'Per-thread before/after deltas include completion of the baseline summary SELECT; the timed PHP interval excludes both summary reads.',
                'Protocol and nested SQL event names can overlap; event-name totals must not be described as distinct queries without examining the instrumentation.',
                'Digest history is optional and may be disabled or truncated by the global history ring; it is not a complete-query claim.',
                'No native consumers or timing instruments were enabled and no shared counters were reset by this diagnostic.',
                'Function timers are inclusive and nested; their durations must not be summed as independent costs. They are reset immediately before the public award.',
                'Real operation failures are rethrown after evidence is written; a diagnostic failure is never reported as an award pass.',
            ],
        ];
        $path = getenv('VA_MEMBERSHIP_AWARD_DIAGNOSTIC_REPORT');
        $this->assertIsString($path);
        $this->assertNotSame('', $path);
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        if ($error !== null) {
            throw $error;
        }
        $this->assertIsArray($result);
        $this->assertSame(1, $report['outcome']['durable_periods']);
        $this->assertSame(1, $report['outcome']['durable_award_events']);
        $this->assertSame($report['source_sha256_before'], $report['source_sha256_after'], 'Application source changed during diagnostic measurement.');
    }

    private function summary(PDO $pdo, int $thread): array
    {
        $statement = $pdo->prepare('SELECT EVENT_NAME, COUNT_STAR, SUM_TIMER_WAIT FROM performance_schema.events_statements_summary_by_thread_by_event_name WHERE THREAD_ID = ?');
        $statement->execute([$thread]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['EVENT_NAME']] = ['count' => (int) $row['COUNT_STAR'], 'timer_ps' => (int) $row['SUM_TIMER_WAIT']];
        }

        return $result;
    }

    private function sources(): array
    {
        $files = [];
        foreach (['app/Domain/Memberships', 'app/Domain/Customers', 'app/Domain/Commerce/ProductionPolicy', 'tests/Support'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relative = substr($file->getPathname(), strlen(base_path()) + 1);
                    $files[$relative] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($files);

        return $files;
    }
}
