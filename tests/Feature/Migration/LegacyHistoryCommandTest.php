<?php

namespace Tests\Feature\Migration;

use App\Domain\Catalog\Models\Track;
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
        $this->assertEquals(['customers' => 4, 'orders' => 4, 'lines' => 5, 'contracts' => 4, 'contract_declarations' => 5, 'contract_files' => 4, 'findings' => 0, 'exceptions' => 3], $summary['counts']);
        $this->assertSame(22, $summary['plan']['pending']);
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
        $blocking = array_values(array_filter($summary['report']['items'], fn ($item) => $item['blocking']));
        $this->assertEqualsCanonicalizing(['customer', 'order', 'line', 'contract', 'contract_declaration'], array_column($blocking, 'cohort'));
        // Removed buyer/order/line/original plus its v2 contract declaration.
        $this->assertSame([3, 'unreconciled', 5], [$exit, $summary['code'], $summary['report']['unexplained']]);
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
        LegacyHistoryFixtures::import($loaded['result']['snapshot'], $loaded['result']['snapshot_sha256'], $loaded['source']['contracts'], $this->operator, 1000);
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
    public function test_track_links_are_dry_run_by_default_reported_by_reconcile_and_resolved_only_by_an_attested_choice(): void
    {
        $source = LegacyHistoryFixtures::privateCopy('families');
        $exact = LegacyHistoryFixtures::catalogMapping('synthetic/beatstars-history', 'synthetic-track-01', $this->operator);
        $first = LegacyHistoryFixtures::catalogMapping('synthetic/beatstars-history', 'synthetic-track-05', $this->operator);
        $second = LegacyHistoryFixtures::catalogMapping('synthetic/other-catalog', 'synthetic-track-05', $this->operator);
        LegacyHistoryFixtures::catalogMapping('synthetic/beatstars-history', 'synthetic-track-02', $this->operator);
        $loaded = (new LegacyHistoryArchive)->load($source);
        $sha = $loaded['result']['snapshot_sha256'];
        LegacyHistoryFixtures::import($loaded['result']['snapshot'], $sha, $loaded['source']['contracts'], $this->operator, 1000);
        $ambiguous = array_column($loaded['result']['snapshot']['lines'], 'record_key', 'track_source_id')['synthetic-track-05'];

        $statements = $this->recordStatements();
        [$exit, $summary, $output] = $this->invoke('link-tracks', ['--source' => $source]);
        $this->assertSame([0, 'link_dry_run', 0], [$exit, $summary['code'], $summary['database_writes']]);
        $this->assertEquals(['linked' => 0, 'unlinked' => 4, 'ambiguous' => 1], $summary['track_links']['states']);
        $this->assertSame(2, $summary['track_links']['plan']['create']);
        [$exit, $summary] = $this->invoke('resolve-track-link', ['--line' => $ambiguous]);
        $this->assertSame([0, 'resolve_dry_run', 'ambiguous', 2], [$exit, $summary['code'], $summary['state'], count($summary['candidates'])]);
        $this->assertSame([], array_filter($statements(), static fn (string $sql): bool => preg_match('/\A\s*select\b/i', $sql) !== 1));
        foreach (['@', 'SYNTHETIC', 'synthetic-pay-', 'synthetic-track-', $source] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }

        $write = ['--source' => $source, '--write' => true, '--actor-id' => (string) $this->operator->id, '--snapshot' => $sha];
        $this->assertSame('attestation_required', $this->invoke('link-tracks', $write)[1]['reason']);
        $this->assertSame('snapshot_mismatch', $this->invoke('link-tracks', ['--snapshot' => str_repeat('b', 64), '--attest' => true] + $write)[1]['reason']);
        $this->assertSame('usage', $this->invoke('link-tracks', ['--mapping' => '1'] + $write)[1]['reason']);
        $this->assertSame('usage', $this->invoke('resolve-track-link', ['--line' => $ambiguous, '--write' => true, '--attest' => true,
            '--actor-id' => (string) $this->operator->id, '--reference' => 'SYNTHETIC pointer'])[1]['reason']);
        $this->assertSame(0, DB::table('legacy_history_track_links')->count());
        $this->artisan('vasey:legacy-history', ['action' => 'link-tracks', '--attest' => true, '--limit' => '1'] + $write)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"code":"link_partial"')->assertExitCode(2);
        $this->artisan('vasey:legacy-history', ['action' => 'link-tracks', '--attest' => true] + $write)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"code":"link_complete"')->assertExitCode(0);
        $this->artisan('vasey:legacy-history', ['action' => 'link-tracks', '--attest' => true] + $write)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"processed":0')->assertExitCode(0);
        $this->assertSame(2, DB::table('legacy_history_track_links')->count());

        [$exit, $summary] = $this->invoke('reconcile', ['--source' => $source]);
        $this->assertSame([0, 'reconciled'], [$exit, $summary['code']]);
        $this->assertEquals(['linked' => 2, 'unlinked' => 2, 'ambiguous' => 1], $summary['track_links']['states']);
        $this->assertSame('ambiguous', $summary['track_links']['line_states'][$ambiguous]);

        $resolve = ['action' => 'resolve-track-link', '--line' => $ambiguous, '--write' => true, '--attest' => true,
            '--actor-id' => (string) $this->operator->id, '--reference' => 'SYNTHETIC pointer'];
        $this->artisan('vasey:legacy-history', ['--mapping' => (string) $exact['mapping_id']] + $resolve)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"reason":"candidate_refused"')->assertExitCode(1);
        $this->artisan('vasey:legacy-history', ['--mapping' => (string) $second['mapping_id']] + $resolve)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"code":"resolve_recorded"')->assertExitCode(0);
        $this->artisan('vasey:legacy-history', ['--mapping' => (string) $first['mapping_id']] + $resolve)
            ->expectsQuestion('Staff password (hidden)', 'password')->expectsOutputToContain('"reason":"already_linked"')->assertExitCode(1);
        $this->assertSame($second['track_id'], (int) DB::table('legacy_history_track_links')->where('line_record_key', $ambiguous)->sole()->track_id);
        [, $summary] = $this->invoke('reconcile', ['--source' => $source]);
        $this->assertEquals(['linked' => 3, 'unlinked' => 2, 'ambiguous' => 0], $summary['track_links']['states']);
    }

    public function test_a_refused_track_link_report_never_changes_the_archive_reconciliation(): void
    {
        $source = LegacyHistoryFixtures::privateCopy('families');
        $genuine = LegacyHistoryFixtures::catalogMapping('synthetic/beatstars-history', 'synthetic-track-01', $this->operator);
        // A mapping row whose record key does not match its own evidence: the catalog side is corrupt, the archive is not.
        $hash = hash('sha256', 'forged');
        DB::table('catalog_import_mappings')->insert(['batch_id' => DB::table('catalog_import_batches')->value('id'), 'record_key' => $hash,
            'source_record_sha256' => $hash, 'track_id' => Track::create(['title' => 'SYNTHETIC forged', 'slug' => 'synthetic-forged', 'artist' => 'Test only'])->id, 'track_sha256' => $hash, 'actor_id' => $this->operator->id,
            'evidence_ciphertext' => DB::table('catalog_import_mappings')->where('id', $genuine['mapping_id'])->value('evidence_ciphertext'), 'created_at' => now()]);
        $loaded = (new LegacyHistoryArchive)->load($source);
        [$snapshot, $sha] = [$loaded['result']['snapshot'], $loaded['result']['snapshot_sha256']];

        // The reconcile result and exit code are exactly the archive's own, before and after the import.
        foreach ([[3, 'unreconciled'], [0, 'reconciled']] as $i => [$code, $result]) {
            if ($i === 1) {
                LegacyHistoryFixtures::import($snapshot, $sha, $loaded['source']['contracts'], $this->operator, 1000);
            }
            [$exit, $summary] = $this->invoke('reconcile', ['--source' => $source]);
            $this->assertSame([$code, $result, 0], [$exit, $summary['code'], $summary['database_writes']]);
            $this->assertEquals((new LegacyHistoryArchive)->reconcile($snapshot, $sha), $summary['report']);
            $this->assertSame(['refused' => 'catalog_mapping_integrity'], $summary['track_links']);
        }
        // The link actions themselves still fail closed on the same evidence.
        [$exit, $summary] = $this->invoke('link-tracks', ['--source' => $source]);
        $this->assertSame([1, 'catalog_mapping_integrity'], [$exit, $summary['reason']]);
        $this->assertSame(0, DB::table('legacy_history_track_links')->count());
    }

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
