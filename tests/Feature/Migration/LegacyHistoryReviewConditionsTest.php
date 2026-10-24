<?php

namespace Tests\Feature\Migration;

use App\Domain\Migration\History\ExclusivityAuthority;
use App\Domain\Migration\History\HistoryDelta;
use App\Domain\Migration\History\LegacyArchiveRefusal;
use App\Domain\Migration\History\LegacyGrantReview;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * Regression tests for the independent review of d456ee3b (conditions C1 to C4): separation of duties over every
 * inserting actor, `verified_grant` re-derived against the newest export, no personal data in any command output,
 * and attestation plus staff MFA enforced inside the domain write services.
 */
class LegacyHistoryReviewConditionsTest extends TestCase
{
    use RefreshDatabase;

    private const PII = ['@', 'SYNTHETIC Buyer', 'synthetic-pay-', 'Jane', 'buyer.one', 'shared.buyer'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    protected function tearDown(): void
    {
        LegacyHistoryFixtures::cleanup();
        parent::tearDown();
    }

    public function test_c1_an_operator_who_inserted_any_evidence_row_cannot_review_it_even_in_another_operators_batch(): void
    {
        [$a, $b, $c] = [LicenseFixtures::admin(), LicenseFixtures::admin(), LicenseFixtures::admin()];
        $baseline = $this->load('baseline');
        LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $a, 1);
        LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $b, 1000);
        $this->assertSame([$a->id], DB::table('legacy_history_batches')->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$a->id, $b->id], DB::table('legacy_history_customers')->orderBy('id')->distinct()->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $line = $this->line($baseline['snapshot'], 'synthetic-ord-1002');
        $review = new LegacyGrantReview;
        foreach ([$a, $b] as $importer) {
            $this->assertContains('reviewer_imported_evidence', LegacyHistoryFixtures::review($review, $line, $importer, 'SYNTHETIC', false)['blockers']);
            $this->refused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $importer, 'SYNTHETIC', true));
        }
        $this->assertSame('recorded', LegacyHistoryFixtures::review($review, $line, $c, 'SYNTHETIC', true)['outcome']);
    }

    public function test_c3_a_later_export_that_drops_the_evidence_or_changes_the_buyer_withdraws_a_review(): void
    {
        [$importer, $reviewer] = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $baseline = $this->load('baseline');
        LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $importer, 1000);
        $review = new LegacyGrantReview;
        $exclusive = $this->line($baseline['snapshot'], 'synthetic-ord-1002');
        $basic = $this->line($baseline['snapshot'], 'synthetic-ord-1001', '1');
        LegacyHistoryFixtures::review($review, $exclusive, $reviewer, 'SYNTHETIC', true);
        LegacyHistoryFixtures::review($review, $basic, $reviewer, 'SYNTHETIC', true);
        $this->assertSame(['verified_grant', 'verified_grant'], [$review->classification($exclusive), $review->classification($basic)]);

        // A later export that is only partly archived cannot prove anything yet.
        // A later export of the same source (baseline content, newer watermark) without order 1002 and with buyer one's new address.
        $later = $this->load('baseline', function (array $parts): array {
            $parts['mapping'] = str_replace(['2026-09-01T01:00:00Z', '2026-09-01T00:00:00Z'], ['2026-10-01T01:00:00Z', '2026-10-01T00:00:00Z'], $parts['mapping']);
            foreach (['orders' => 'synthetic-ord-1002', 'contracts' => 'synthetic-contract-1002-1'] as $sheet => $prefix) {
                $parts[$sheet] = preg_replace('/^'.$prefix.',.*\n/m', '', $parts[$sheet]);
            }
            unset($parts['files']['synthetic-contract-1002-1.txt']);
            $parts['customers'] = str_replace('buyer.one@example.invalid', 'renamed.buyer@example.invalid', $parts['customers']);

            return $parts;
        });
        LegacyHistoryFixtures::import($later['snapshot'], $later['sha'], $later['files'], $importer, 1);
        $this->assertSame(['latest_export_not_archived'], $review->inspect($exclusive)['blockers']);
        $this->assertSame('archive_only', $review->classification($exclusive));
        LegacyHistoryFixtures::import($later['snapshot'], $later['sha'], $later['files'], $importer, 1000);

        // The exclusive's order, line and contract are gone from the newest export; the old rows stay archived.
        $this->assertSame(['evidence_removed'], $review->inspect($exclusive)['blockers']);
        $this->assertSame('archive_only', $review->classification($exclusive));
        // Buyer one's address changed: the reviewed evidence no longer hashes to what was approved.
        $this->assertContains('reviewed_evidence_changed', $review->inspect($basic)['blockers']);
        $this->assertSame('archive_only', $review->classification($basic));
        $this->assertSame(2, DB::table('legacy_history_grant_reviews')->count());
        // The delta itself also refuses to capture an address change silently.
        $delta = (new HistoryDelta)->compare($baseline['snapshot'], $later['snapshot']);
        $this->assertContains('customer:customer_identity_changed', array_map(fn (array $item) => $item['cohort'].':'.$item['category'],
            array_filter($delta['items'], fn (array $item) => $item['blocking'])));
    }

    public function test_c2_no_command_prints_personal_data_and_file_names_are_hashed(): void
    {
        $operator = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $named = 'Exclusive - Jane Q Buyer - jane.buyer@example.invalid.pdf';
        $stray = LegacyHistoryFixtures::privateCopy('baseline', function (array $parts) use ($named): array {
            $parts['files'][$named] = "SYNTHETIC stray original\n";

            return $parts;
        });
        [$exit, $output] = $this->capture('vasey:legacy-history', ['action' => 'dry-run', '--source' => $stray]);
        $this->assertSame(3, $exit);
        $this->assertStringContainsString('"column":"file_sha256:'.hash('sha256', $named).'"', $output);
        $outputs = [$output];

        $baseline = LegacyHistoryFixtures::privateCopy('baseline');
        $later = LegacyHistoryFixtures::privateCopy('later', function (array $parts): array {
            $parts['customers'] = preg_replace('/^synthetic-cust-002,.*\n/m', '', $parts['customers']);
            $parts['orders'] = preg_replace('/^synthetic-ord-1002,.*\n/m', '', $parts['orders']);
            $parts['contracts'] = preg_replace('/^synthetic-contract-1002-1,.*\n/m', '', $parts['contracts']);
            unset($parts['files']['synthetic-contract-1002-1.txt']);

            return $parts;
        });
        [, $dry] = $this->capture('vasey:legacy-history', ['action' => 'dry-run', '--source' => $baseline]);
        $sha = json_decode(trim($dry), true)['snapshot_sha256'];
        $outputs[] = $dry;
        foreach ([$baseline, $later] as $source) {
            $outputs[] = $this->capture('vasey:legacy-history', ['action' => 'import', '--source' => $source])[1];
            $outputs[] = $this->capture('vasey:legacy-history', ['action' => 'reconcile', '--source' => $source])[1];
        }
        $this->artisan('vasey:legacy-history', ['action' => 'import', '--source' => $baseline, '--write' => true, '--attest' => true,
            '--actor-id' => (string) $operator->id, '--snapshot' => $sha])->expectsQuestion('Staff password (hidden)', 'password')
            ->doesntExpectOutputToContain('@')->doesntExpectOutputToContain('SYNTHETIC Buyer')->assertExitCode(0);
        $outputs[] = $this->capture('vasey:legacy-history', ['action' => 'reconcile', '--source' => $baseline])[1];
        [, $delta] = $this->capture('vasey:legacy-history', ['action' => 'delta', '--source' => $baseline, '--later' => $later]);
        $report = json_decode(trim($delta), true)['report'];
        $items = [];
        foreach ($report['items'] as $item) {
            if ($item['blocking']) {
                $items[$item['cohort'].':'.$item['record_key']] = ['disposition' => 'excluded_with_owner_approval', 'reason' => 'SYNTHETIC Jane Q Buyer withdrew'];
            }
        }
        $file = LegacyHistoryFixtures::privateFile(json_encode(['schema_version' => 1, 'purpose' => HistoryDelta::DISPOSITIONS_PURPOSE,
            'baseline_snapshot_sha256' => $report['baseline']['snapshot_sha256'], 'later_snapshot_sha256' => $report['later']['snapshot_sha256'], 'items' => $items]));
        [$exit, $explained] = $this->capture('vasey:legacy-history', ['action' => 'delta', '--source' => $baseline, '--later' => $later, '--dispositions' => $file]);
        $this->assertSame(0, $exit, $explained);
        $outputs = [...$outputs, $delta, $explained];
        $line = null;
        foreach ((new LegacyHistoryArchive)->load($baseline)['result']['snapshot']['lines'] as $record) {
            $line = $record['source_order_id'] === 'synthetic-ord-1001' ? $record['record_key'] : $line;
        }
        $outputs[] = $this->capture('vasey:legacy-history', ['action' => 'review-grant', '--line' => $line])[1];
        $this->artisan('vasey:legacy-history', ['action' => 'review-grant', '--line' => $line, '--write' => true, '--attest' => true,
            '--actor-id' => (string) $reviewer->id, '--reference' => 'SYNTHETIC Jane Q Buyer review'])->expectsQuestion('Staff password (hidden)', 'password')
            ->doesntExpectOutputToContain('Jane')->doesntExpectOutputToContain('@')->assertExitCode(0);
        foreach ([['action' => 'status'], ['action' => 'declare', '--actor-id' => (string) $operator->id]] as $arguments) {
            $outputs[] = $this->capture('vasey:exclusivity-authority', $arguments)[1];
        }
        $this->artisan('vasey:exclusivity-authority', ['action' => 'declare', '--actor-id' => (string) $operator->id, '--reference' => 'SYNTHETIC Jane Q Buyer',
            '--write' => true, '--attest' => true])->expectsQuestion('Staff password (hidden)', 'password')
            ->doesntExpectOutputToContain('Jane')->assertExitCode(0);
        $outputs[] = $this->capture('vasey:exclusivity-authority', ['action' => 'handover', '--actor-id' => (string) $operator->id,
            '--baseline' => $baseline, '--later' => $later, '--dispositions' => $file])[1];
        foreach ($outputs as $index => $text) {
            $this->assertNotSame('', trim($text), 'output '.$index);
            foreach ([...self::PII, $named, $baseline] as $private) {
                $this->assertStringNotContainsString($private, $text, 'output '.$index);
            }
        }
    }

    public function test_c4_the_domain_services_bind_spend_and_time_limit_the_attestation(): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $baseline = $this->load('baseline');
        $archive = new LegacyHistoryArchive;
        $bound = fn (User $who, string $sha) => LegacyHistoryFixtures::attestation($who, LegacyHistoryArchive::ATTESTATION_ACTION, LegacyHistoryArchive::attestationScope($sha), $sha);
        $import = fn ($attestation) => $archive->import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $actor, $attestation, 1);
        $this->refused('staff_authority_refused', fn () => $import($bound($actor, str_repeat('c', 64))));
        $this->refused('staff_authority_refused', fn () => $import($bound($other, $baseline['sha'])));
        $this->refused('staff_authority_refused', fn () => $import(LegacyHistoryFixtures::attestation($actor, LegacyGrantReview::ATTESTATION_ACTION,
            LegacyHistoryArchive::attestationScope($baseline['sha']), $baseline['sha'])));
        $stale = $bound($actor, $baseline['sha']);
        $this->travel(901)->seconds();
        $this->refused('staff_authority_refused', fn () => $import($stale));
        $this->assertSame(0, DB::table('legacy_history_batches')->count());
        $once = $bound($actor, $baseline['sha']);
        $this->assertSame(1, $import($once)['processed']);
        $this->refused('staff_authority_refused', fn () => $import($once));
        $this->assertSame(1, DB::table('legacy_history_customers')->count());

        $authority = new ExclusivityAuthority;
        $this->refused('attestation_required', fn () => $authority->transition('declare', $actor, 'SYNTHETIC', null, true));
        $this->refused('staff_authority_refused', fn () => $authority->transition('declare', $actor, 'SYNTHETIC',
            LegacyHistoryFixtures::attestation($actor, ExclusivityAuthority::attestationAction('freeze'), ExclusivityAuthority::ATTESTATION_SCOPE, 'SYNTHETIC'), true));
        $this->refused('staff_authority_refused', fn () => $authority->transition('declare', $actor, 'SYNTHETIC',
            LegacyHistoryFixtures::attestation($actor, ExclusivityAuthority::attestationAction('declare'), ExclusivityAuthority::ATTESTATION_SCOPE, 'other reference'), true));
        $this->assertSame(0, DB::table(ExclusivityAuthority::TABLE)->count());
        $this->refused('attestation_required', fn () => (new LegacyGrantReview)->review(str_repeat('d', 64), $other, 'SYNTHETIC', null, true));
    }

    public function test_c4_staff_mfa_is_required_where_the_panel_requires_it(): void
    {
        $unenrolled = LicenseFixtures::admin();
        $enrolled = LicenseFixtures::admin();
        $enrolled->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $baseline = $this->load('baseline');
        config(['app.env' => 'staging', 'legacy-archive.non_local_environments' => ['staging']]);
        $this->app->detectEnvironment(fn () => 'staging');
        try {
            $this->refused('staff_authority_refused', fn () => LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $unenrolled, 1000));
            $this->refused('staff_authority_refused', fn () => LegacyHistoryFixtures::transition(new ExclusivityAuthority, 'declare', $unenrolled, 'SYNTHETIC', true));
            $this->assertSame(0, DB::table('legacy_history_batches')->count() + DB::table(ExclusivityAuthority::TABLE)->count());
            $this->assertTrue(LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $enrolled, 1000)['complete']);
            $this->assertSame('recorded', LegacyHistoryFixtures::transition(new ExclusivityAuthority, 'declare', $enrolled, 'SYNTHETIC', true)['outcome']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
            config(['app.env' => 'testing']);
        }
    }

    private function refused(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Admitted; expected '.$reason);
        } catch (LegacyArchiveRefusal $refusal) {
            $this->assertSame($reason, $refusal->reason);
        }
    }

    /** @return array{0: int, 1: string} */
    private function capture(string $command, array $arguments): array
    {
        $exit = Artisan::call($command, $arguments);

        return [$exit, Artisan::output()];
    }

    private function load(string $package, ?callable $change = null): array
    {
        $loaded = (new LegacyHistoryArchive)->load(LegacyHistoryFixtures::privateCopy($package, $change));
        $this->assertSame([], $loaded['result']['findings']);

        return ['snapshot' => $loaded['result']['snapshot'], 'sha' => $loaded['result']['snapshot_sha256'], 'files' => $loaded['source']['contracts']];
    }

    private function line(array $snapshot, string $order, string $line = '1'): string
    {
        foreach ($snapshot['lines'] as $record) {
            if ($record['source_order_id'] === $order && $record['source_line_id'] === $line) {
                return $record['record_key'];
            }
        }
        $this->fail('Line not found.');
    }
}
