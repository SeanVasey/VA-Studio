<?php

namespace Tests\Feature\Migration;

use App\Domain\Migration\History\HistoryDelta;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** The operator command: dry run by default, gated writes, exit codes and output without personal data. */
class LegacyHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->operator = LicenseFixtures::admin();
    }

    protected function tearDown(): void
    {
        LegacyHistoryFixtures::cleanup();
        parent::tearDown();
    }

    public function test_dry_run_reads_the_private_package_and_issues_no_write_statement(): void
    {
        $source = LegacyHistoryFixtures::privateCopy('baseline');
        $statements = $this->recordStatements();
        [$exit, $summary, $output] = $this->invoke('dry-run', ['--source' => $source]);
        $this->assertSame(0, $exit, $output);
        $this->assertSame('dry_run_clean', $summary['code']);
        $this->assertSame(0, $summary['database_writes']);
        $this->assertEquals(['customers' => 4, 'orders' => 4, 'lines' => 5, 'contracts' => 4, 'contract_files' => 4, 'findings' => 0, 'exceptions' => 3], $summary['counts']);
        $this->assertSame(17, $summary['plan']['pending']);
        $this->assertSame(['ambiguous_customer_identity' => 2, 'contract_original_missing' => 1], $summary['exceptions']);
        $this->assertSame(3, $summary['eligible_for_review']);
        $this->assertSame('archive_only', $summary['classification']);
        $this->assertSame([], array_filter($statements(), static fn (string $sql): bool => preg_match('/\A\s*select\b/i', $sql) !== 1));
        foreach (['@', 'SYNTHETIC Buyer', 'synthetic-pay-', $source] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertSame(0, DB::table('legacy_history_batches')->count());

        // The repository fixture itself is admitted only because its mapping declares a synthetic fixture.
        [$exit, $summary] = $this->invoke('dry-run', ['--source' => base_path('tests/Fixtures/migration/history-synthetic/baseline')]);
        $this->assertSame([0, 'dry_run_clean'], [$exit, $summary['code']]);
    }

    public function test_findings_and_unsafe_sources_are_refused_before_any_write(): void
    {
        $findings = LegacyHistoryFixtures::privateCopy('baseline', function (array $parts): array {
            $parts['files']['synthetic-contract-1002-1.txt'] .= 'SYNTHETIC tamper';

            return $parts;
        });
        [$exit, $summary] = $this->invoke('dry-run', ['--source' => $findings]);
        $this->assertSame([3, 'dry_run_findings', null], [$exit, $summary['code'], $summary['snapshot_sha256']]);
        $this->assertSame(['contract_hash_mismatch'], array_column($summary['findings'], 'code'));
        [$exit, $summary] = $this->invoke('import', ['--source' => $findings, '--write' => true, '--attest' => true, '--actor-id' => (string) $this->operator->id,
            '--snapshot' => str_repeat('a', 64)]);
        $this->assertSame([3, 'import_refused_findings'], [$exit, $summary['code']]);

        $exposed = LegacyHistoryFixtures::privateCopy('baseline');
        chmod($exposed.'/orders.csv', 0644);
        [$exit, $summary] = $this->invoke('dry-run', ['--source' => $exposed]);
        $this->assertSame([1, 'refused', 'source_not_private'], [$exit, $summary['code'], $summary['reason']]);
        [$exit, $summary] = $this->invoke('dry-run', ['--source' => base_path('app')]);
        $this->assertSame([1, 'source_inside_repository'], [$exit, $summary['reason']]);
        [$exit, $summary] = $this->invoke('dry-run', ['--source' => 'relative/path']);
        $this->assertSame([1, 'source_path'], [$exit, $summary['reason']]);
        $this->assertSame(0, DB::table('legacy_history_batches')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
    }

    public function test_writes_need_the_reviewed_snapshot_an_interactive_attested_staff_console_and_resume_in_segments(): void
    {
        $source = LegacyHistoryFixtures::privateCopy('baseline');
        [, $dry] = $this->invoke('dry-run', ['--source' => $source]);
        $sha = $dry['snapshot_sha256'];
        [$exit, $summary] = $this->invoke('import', ['--source' => $source]);
        $this->assertSame([0, 'import_dry_run', 0], [$exit, $summary['code'], $summary['database_writes']]);
        $write = ['--source' => $source, '--write' => true, '--actor-id' => (string) $this->operator->id, '--snapshot' => $sha];
        $this->assertSame('attestation_required', $this->invoke('import', $write)[1]['reason']);
        $this->assertSame('snapshot_mismatch', $this->invoke('import', ['--snapshot' => str_repeat('b', 64), '--attest' => true] + $write)[1]['reason']);
        $this->assertSame('usage', $this->invoke('import', array_diff_key($write, ['--snapshot' => true]) + ['--attest' => true])[1]['reason']);
        $this->assertSame('interactive_console_required', $this->invoke('import', ['--attest' => true, '--no-interaction' => true] + $write)[1]['reason']);
        $this->artisan('vasey:legacy-history', ['action' => 'import', '--attest' => true] + $write)
            ->expectsQuestion('Staff password (hidden)', 'wrong-password')->expectsOutputToContain('"reason":"actor_refused"')->assertExitCode(1);
        $this->assertSame(0, DB::table('legacy_history_batches')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        $this->artisan('vasey:legacy-history', ['action' => 'reconcile', '--source' => $source])->expectsOutputToContain('"code":"unreconciled"')->assertExitCode(3);

        $this->artisan('vasey:legacy-history', ['action' => 'import', '--attest' => true, '--limit' => '10'] + $write)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"code":"import_partial"')->assertExitCode(2);
        $this->artisan('vasey:legacy-history', ['action' => 'import', '--attest' => true] + $write)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"entitlements_created":0')->assertExitCode(0);
        $this->assertSame(2, DB::table('audit_events')->where('action', StaffWriteAuthority::ATTESTATION_ACTION)->count());
        $this->assertSame(17, DB::table('legacy_history_customers')->count() + DB::table('legacy_history_orders')->count()
            + DB::table('legacy_history_lines')->count() + DB::table('legacy_history_contracts')->count());
        $this->artisan('vasey:legacy-history', ['action' => 'reconcile', '--source' => $source])->expectsOutputToContain('"code":"reconciled"')->assertExitCode(0);
    }

    public function test_delta_and_grant_review_through_the_command(): void
    {
        $baseline = LegacyHistoryFixtures::privateCopy('baseline');
        $later = LegacyHistoryFixtures::privateCopy('later');
        [$exit, $summary] = $this->invoke('delta', ['--source' => $baseline, '--later' => $later]);
        $this->assertSame([0, 'reconciled', 0], [$exit, $summary['code'], $summary['database_writes']]);
        $this->assertSame(HistoryDelta::PURPOSE, $summary['report']['purpose']);

        $removed = LegacyHistoryFixtures::privateCopy('later', function (array $parts): array {
            $parts['customers'] = preg_replace('/^synthetic-cust-005,.*\n/m', '', $parts['customers']);
            $parts['orders'] = preg_replace('/^synthetic-ord-1005,.*\n/m', '', $parts['orders']);
            $parts['contracts'] = preg_replace('/^synthetic-contract-1005-1,.*\n/m', '', $parts['contracts']);
            unset($parts['files']['synthetic-contract-1005-1.txt']);
            $parts['customers'] = preg_replace('/^synthetic-cust-002,.*\n/m', '', $parts['customers']);
            $parts['orders'] = preg_replace('/^synthetic-ord-1002,.*\n/m', '', $parts['orders']);
            $parts['contracts'] = preg_replace('/^synthetic-contract-1002-1,.*\n/m', '', $parts['contracts']);
            unset($parts['files']['synthetic-contract-1002-1.txt']);

            return $parts;
        });
        [$exit, $summary] = $this->invoke('delta', ['--source' => $baseline, '--later' => $removed]);
        $this->assertSame([3, 'unreconciled', 4], [$exit, $summary['code'], $summary['report']['unexplained']]);
        $items = [];
        foreach ($summary['report']['items'] as $item) {
            if ($item['blocking']) {
                $items[$item['cohort'].':'.$item['record_key']] = ['disposition' => 'excluded_with_owner_approval', 'reason' => 'SYNTHETIC exclusive buyer record withdrawn'];
            }
        }
        $file = LegacyHistoryFixtures::privateFile(json_encode(['schema_version' => 1, 'purpose' => HistoryDelta::DISPOSITIONS_PURPOSE,
            'baseline_snapshot_sha256' => $summary['report']['baseline']['snapshot_sha256'], 'later_snapshot_sha256' => $summary['report']['later']['snapshot_sha256'], 'items' => $items]));
        [$exit, $summary] = $this->invoke('delta', ['--source' => $baseline, '--later' => $removed, '--dispositions' => $file]);
        $this->assertSame([0, 'reconciled'], [$exit, $summary['code']]);

        $loaded = (new LegacyHistoryArchive)->load($baseline);
        (new LegacyHistoryArchive)->import($loaded['result']['snapshot'], $loaded['result']['snapshot_sha256'], $loaded['source']['contracts'], $this->operator, null, 1000);
        $line = null;
        foreach ($loaded['result']['snapshot']['lines'] as $record) {
            if ($record['source_order_id'] === 'synthetic-ord-1002') {
                $line = $record['record_key'];
            }
        }
        [$exit, $summary] = $this->invoke('review-grant', ['--line' => $line]);
        $this->assertSame([0, 'review_dry_run', 'archive_only', true], [$exit, $summary['code'], $summary['classification'], $summary['eligible']]);
        $reviewer = LicenseFixtures::admin();
        $this->artisan('vasey:legacy-history', ['action' => 'review-grant', '--line' => $line, '--write' => true, '--attest' => true,
            '--actor-id' => (string) $this->operator->id, '--reference' => 'SYNTHETIC review'])
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"reason":"not_eligible"')->assertExitCode(1);
        $this->artisan('vasey:legacy-history', ['action' => 'review-grant', '--line' => $line, '--write' => true, '--attest' => true,
            '--actor-id' => (string) $reviewer->id, '--reference' => 'SYNTHETIC review'])
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"code":"review_recorded"')->assertExitCode(0);
        $this->assertSame(1, DB::table('legacy_history_grant_reviews')->count());
    }

    public function test_hosted_environments_and_synthetic_production_imports_are_refused(): void
    {
        $source = LegacyHistoryFixtures::privateCopy('baseline');
        foreach (['staging', 'production', 'local-eu'] as $environment) {
            $this->app['env'] = $environment;
            config(['app.env' => $environment]);
            $statements = $this->recordStatements();
            [$exit, $summary] = $this->invoke('dry-run', ['--source' => $source]);
            $this->assertSame([1, 'environment_refused'], [$exit, $summary['reason']], $environment);
            $this->assertSame([], $statements(), $environment);
        }
        config(['app.env' => 'production', 'legacy-archive.non_local_environments' => ['production']]);
        $this->app['env'] = 'production';
        [$exit, $summary] = $this->invoke('dry-run', ['--source' => $source]);
        $this->assertSame([0, 'dry_run_clean'], [$exit, $summary['code']]);
        [$exit, $summary] = $this->invoke('import', ['--source' => $source]);
        $this->assertSame([1, 'synthetic_source_in_production'], [$exit, $summary['reason']]);
        config(['app.env' => 'testing']);
        [$exit, $summary] = $this->invoke('dry-run', ['--source' => $source]);
        $this->assertSame([1, 'environment_ambiguous'], [$exit, $summary['reason']]);
    }

    /** @return array{0: int, 1: array, 2: string} */
    private function invoke(string $action, array $options): array
    {
        $exit = Artisan::call('vasey:legacy-history', ['action' => $action] + $options);
        $output = Artisan::output();
        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => str_starts_with($line, '{')));

        return [$exit, json_decode($lines[0] ?? 'null', true), $output];
    }

    /** @return callable(): list<string> */
    private function recordStatements(): callable
    {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        return function () use (&$statements): array {
            return $statements;
        };
    }
}
