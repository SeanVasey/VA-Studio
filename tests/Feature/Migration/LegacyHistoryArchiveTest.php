<?php

namespace Tests\Feature\Migration;

use App\Domain\Migration\History\LegacyArchiveRefusal;
use App\Domain\Migration\History\LegacyGrantReview;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Restartable, idempotent archive import, reconciliation and the separately reviewed verified_grant path. */
class LegacyHistoryArchiveTest extends TestCase
{
    use RefreshDatabase;

    private LegacyHistoryArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->archive = new LegacyHistoryArchive;
    }

    protected function tearDown(): void
    {
        LegacyHistoryFixtures::cleanup();
        parent::tearDown();
    }

    public function test_segmented_import_is_idempotent_reconciles_and_writes_nothing_outside_the_archive(): void
    {
        $importer = LicenseFixtures::admin();
        $loaded = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline'));
        $snapshot = $loaded['result']['snapshot'];
        $sha = $loaded['result']['snapshot_sha256'];
        $before = LegacyHistoryFixtures::nonArchiveState();
        $this->assertSame('unreconciled', $this->archive->reconcile($snapshot, $sha)['result']);
        $this->assertSame(['create' => 4, 'skip' => 0], $this->archive->plan($snapshot, $sha)['cohorts']['customers']);

        $segments = [];
        do {
            $segment = $this->archive->import($snapshot, $sha, $loaded['source']['contracts'], $importer, null, 5);
            $segments[] = [$segment['processed'], $segment['pending'], $segment['complete']];
        } while (! $segment['complete']);
        $this->assertSame([[5, 12, false], [5, 7, false], [5, 2, false], [2, 0, true]], $segments);
        $this->assertSame(17, $segment['total']);
        $this->assertSame([4, 4, 5, 4, 1], $this->tableCounts());

        $report = $this->archive->reconcile($snapshot, $sha);
        $this->assertSame('reconciled', $report['result'], json_encode($report));
        $this->assertSame(0, $report['unexplained']);
        $this->assertSame(4, $report['artifacts']['verified']);
        $this->assertSame(['ambiguous_customer_identity' => 2, 'contract_original_missing' => 1], $report['explained_exceptions']);

        // A re-run inserts nothing, creates no second batch and leaves every other table untouched.
        $again = $this->archive->import($snapshot, $sha, $loaded['source']['contracts'], $importer, null, 1000);
        $this->assertSame([0, 0, true], [$again['processed'], $again['pending'], $again['complete']]);
        $this->assertSame([4, 4, 5, 4, 1], $this->tableCounts());
        $this->assertSame($before, LegacyHistoryFixtures::nonArchiveState());
        $this->assertSame(['archive_only'], DB::table('legacy_history_lines')->distinct()->pluck('classification')->all());
        $this->assertSame(['unknown'], DB::table('legacy_history_customers')->distinct()->pluck('marketing_consent')->all());
        $this->assertSame(5, DB::table('audit_events')->where('action', LegacyHistoryArchive::SEGMENT_ACTION)->count());

        // Originals are byte-exact, private and addressed by hash; personal data is stored only encrypted.
        foreach (DB::table('legacy_history_contracts')->get() as $row) {
            $this->assertSame(LegacyHistoryArchive::contractPath($row->original_sha256), $row->storage_path);
            $this->assertSame($row->original_sha256, hash('sha256', Storage::disk('local')->get($row->storage_path)));
        }
        $stored = DB::table('legacy_history_customers')->get()->toJson().DB::table('legacy_history_orders')->get()->toJson();
        foreach (['@example.invalid', 'SYNTHETIC Buyer', 'synthetic-pay-'] as $private) {
            $this->assertStringNotContainsString($private, $stored);
        }
    }

    public function test_an_interrupted_segment_rolls_back_and_the_next_run_resumes_without_duplicates(): void
    {
        $importer = LicenseFixtures::admin();
        $loaded = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline'));
        [$snapshot, $sha, $files] = [$loaded['result']['snapshot'], $loaded['result']['snapshot_sha256'], $loaded['source']['contracts']];
        $this->archive->import($snapshot, $sha, $files, $importer, null, 6);
        $committed = $this->tableCounts();
        try {
            $this->archive->import($snapshot, $sha, $files, $importer, null, 1000, function (int $count): void {
                if ($count === 7) {
                    throw new RuntimeException('Synthetic interruption');
                }
            });
            $this->fail('The interruption was not raised.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic interruption', $error->getMessage());
        }
        $this->assertSame($committed, $this->tableCounts());
        $resumed = $this->archive->import($snapshot, $sha, $files, $importer, null, 1000);
        $this->assertSame([11, 0, true], [$resumed['processed'], $resumed['pending'], $resumed['complete']]);
        $this->assertSame([4, 4, 5, 4, 1], $this->tableCounts());
        $this->assertSame('reconciled', $this->archive->reconcile($snapshot, $sha)['result']);
    }

    public function test_a_tampered_or_missing_stored_original_and_a_changed_snapshot_are_unexplained(): void
    {
        $importer = LicenseFixtures::admin();
        $loaded = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline'));
        [$snapshot, $sha, $files] = [$loaded['result']['snapshot'], $loaded['result']['snapshot_sha256'], $loaded['source']['contracts']];
        $this->archive->import($snapshot, $sha, $files, $importer, null, 1000);
        $contracts = DB::table('legacy_history_contracts')->orderBy('id')->get();
        Storage::disk('local')->put($contracts[0]->storage_path, "SYNTHETIC substituted bytes\n");
        Storage::disk('local')->delete($contracts[1]->storage_path);
        $report = $this->archive->reconcile($snapshot, $sha);
        $this->assertSame('unreconciled', $report['result']);
        $this->assertSame([$contracts[0]->record_key], $report['artifacts']['hash_mismatch']);
        $this->assertSame([$contracts[1]->record_key], $report['artifacts']['missing']);
        $this->assertSame(2, $report['unexplained']);

        $this->expectExceptionObject(new LegacyArchiveRefusal('snapshot_mismatch'));
        $this->archive->import(['snapshot_id' => 'changed'] + $snapshot, $sha, $files, $importer, null, 1000);
    }

    public function test_contract_bytes_that_differ_from_the_snapshot_are_refused_at_write_time(): void
    {
        $importer = LicenseFixtures::admin();
        $loaded = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline'));
        $files = $loaded['source']['contracts'];
        $files['synthetic-contract-1001-1.txt'] .= 'SYNTHETIC drift';
        try {
            $this->archive->import($loaded['result']['snapshot'], $loaded['result']['snapshot_sha256'], $files, $importer, null, 1000);
            $this->fail('Changed contract bytes were archived.');
        } catch (LegacyArchiveRefusal $refusal) {
            $this->assertSame('contract_source_changed', $refusal->reason);
        }
        $this->assertSame([0, 0, 0, 0, 0], $this->tableCounts());
    }

    public function test_a_revoked_operator_cannot_continue_and_a_later_export_adds_only_new_versions(): void
    {
        $importer = LicenseFixtures::admin();
        $baseline = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline'))['result'];
        $later = $this->archive->load(LegacyHistoryFixtures::privateCopy('later'));
        $this->archive->import($baseline['snapshot'], $baseline['snapshot_sha256'], $later['source']['contracts'], $importer, null, 1000);

        $revoked = LicenseFixtures::admin();
        $revoked->is_admin = false;
        $revoked->save();
        try {
            $this->archive->import($later['result']['snapshot'], $later['result']['snapshot_sha256'], $later['source']['contracts'], $revoked, null, 1000);
            $this->fail('A non-staff account imported.');
        } catch (LegacyArchiveRefusal $refusal) {
            $this->assertSame('actor_refused', $refusal->reason);
        }

        $plan = $this->archive->plan($later['result']['snapshot'], $later['result']['snapshot_sha256']);
        $this->assertSame(['customers' => ['create' => 1, 'skip' => 4], 'orders' => ['create' => 2, 'skip' => 3],
            'lines' => ['create' => 3, 'skip' => 3], 'contracts' => ['create' => 1, 'skip' => 4]], $plan['cohorts']);
        $this->archive->import($later['result']['snapshot'], $later['result']['snapshot_sha256'], $later['source']['contracts'], $importer, null, 1000);
        $this->assertSame([5, 6, 8, 5, 2], $this->tableCounts());
        // The baseline's versions stay; both snapshots reconcile against the same immutable archive.
        $this->assertSame('reconciled', $this->archive->reconcile($baseline['snapshot'], $baseline['snapshot_sha256'])['result']);
        $this->assertSame('reconciled', $this->archive->reconcile($later['result']['snapshot'], $later['result']['snapshot_sha256'])['result']);
    }

    public function test_archive_only_lines_need_a_different_reviewer_and_verified_grant_creates_no_entitlement(): void
    {
        $importer = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $loaded = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline'));
        $snapshot = $loaded['result']['snapshot'];
        $this->archive->import($snapshot, $loaded['result']['snapshot_sha256'], $loaded['source']['contracts'], $importer, null, 1000);
        $lines = [];
        foreach ($snapshot['lines'] as $line) {
            $lines[$line['source_order_id'].'/'.$line['source_line_id']] = $line['record_key'];
        }
        $review = new LegacyGrantReview;
        $exclusive = $lines['synthetic-ord-1002/1'];
        $this->assertSame('archive_only', $review->classification($exclusive));
        $before = LegacyHistoryFixtures::nonArchiveState();

        $self = $review->review($exclusive, $importer, 'SYNTHETIC review reference', null, false);
        $this->assertSame(['reviewer_imported_evidence'], $self['blockers']);
        try {
            $review->review($exclusive, $importer, 'SYNTHETIC review reference', null, true);
            $this->fail('The importer reviewed its own evidence.');
        } catch (LegacyArchiveRefusal $refusal) {
            $this->assertSame('not_eligible', $refusal->reason);
        }

        $recorded = $review->review($exclusive, $reviewer, 'SYNTHETIC review reference', null, true);
        $this->assertSame('recorded', $recorded['outcome']);
        $this->assertSame('verified_grant', $review->classification($exclusive));
        $this->assertSame('unchanged', $review->review($exclusive, $reviewer, 'SYNTHETIC review reference', null, true)['outcome']);
        $row = DB::table('legacy_history_grant_reviews')->sole();
        $this->assertSame($recorded['evidence_sha256'], $row->evidence_sha256);
        $this->assertStringNotContainsString('SYNTHETIC review reference', json_encode($row));
        $this->assertSame($before, LegacyHistoryFixtures::nonArchiveState(), 'A verified_grant must not create a grant, entitlement or download.');
        $this->assertFalse(DB::table('audit_events')->where('action', LegacyGrantReview::REVIEW_ACTION)->sole()->context === null);

        foreach (['synthetic-ord-1003/1' => ['ambiguous_customer_identity', 'line_refunded', 'order_not_paid'],
            'synthetic-ord-1004/1' => ['ambiguous_customer_identity', 'contract_absent', 'payment_reference_missing']] as $line => $blockers) {
            $this->assertSame($blockers, $review->inspect($lines[$line])['blockers'], $line);
            try {
                $review->review($lines[$line], $reviewer, 'SYNTHETIC review reference', null, true);
                $this->fail($line.' was reviewed.');
            } catch (LegacyArchiveRefusal $refusal) {
                $this->assertSame('not_eligible', $refusal->reason);
            }
        }

        $basic = $lines['synthetic-ord-1001/1'];
        $contract = DB::table('legacy_history_contracts')->where('line_record_key', $basic)->sole();
        Storage::disk('local')->put($contract->storage_path, "SYNTHETIC substituted bytes\n");
        $this->assertSame(['contract_original_unverified'], $review->inspect($basic)['blockers']);
        $this->assertSame(1, DB::table('legacy_history_grant_reviews')->count());
    }

    public function test_a_second_unrefunded_exclusive_sale_blocks_review_of_both(): void
    {
        $importer = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $loaded = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline', function (array $parts): array {
            $parts['orders'] .= "synthetic-ord-1009,1,synthetic-cust-001,2026-08-09T10:00:00Z,Completed,synthetic-track-03,Exclusive Rights,500,0,synthetic-pay-1009,USD\n";
            $parts['contracts'] .= "synthetic-contract-1009-1,synthetic-ord-1009,1,synthetic-contract-1009-1.txt,\n";
            $parts['files']['synthetic-contract-1009-1.txt'] = "SYNTHETIC CONTRACT ORIGINAL 1009-1\n";

            return $parts;
        }));
        $this->assertSame([], $loaded['result']['findings']);
        $this->archive->import($loaded['result']['snapshot'], $loaded['result']['snapshot_sha256'], $loaded['source']['contracts'], $importer, null, 1000);
        $review = new LegacyGrantReview;
        foreach ($loaded['result']['snapshot']['lines'] as $line) {
            if ($line['track_source_id'] === 'synthetic-track-03') {
                $this->assertContains('duplicate_exclusive_sale', $review->review($line['record_key'], $reviewer, 'SYNTHETIC', null, false)['blockers']);
            }
        }
        $this->assertSame(0, DB::table('legacy_history_grant_reviews')->count());
        $this->assertSame(2, DB::table('legacy_history_lines')->where('track_source_id', 'synthetic-track-03')->count());
    }

    public function test_the_stored_snapshot_hash_binds_the_batch_identity(): void
    {
        $importer = LicenseFixtures::admin();
        $loaded = $this->archive->load(LegacyHistoryFixtures::privateCopy('baseline'));
        $this->archive->import($loaded['result']['snapshot'], $loaded['result']['snapshot_sha256'], $loaded['source']['contracts'], $importer, null, 1);
        $batch = DB::table('legacy_history_batches')->sole();
        $this->assertSame([$loaded['result']['snapshot_sha256'], 'synthetic-history-baseline', 'synthetic_fixture', '2026-09-01T00:00:00Z', 17],
            [$batch->snapshot_sha256, $batch->snapshot_id, $batch->acquisition_method, $batch->source_as_of, (int) $batch->expected_records]);
        $this->assertSame(CanonicalJson::hash($loaded['result']['snapshot']), $batch->snapshot_sha256);
        $this->assertInstanceOf(User::class, User::find($batch->actor_id));
    }

    /** @return list<int> customers, orders, lines, contracts, batches */
    private function tableCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(),
            ['legacy_history_customers', 'legacy_history_orders', 'legacy_history_lines', 'legacy_history_contracts', 'legacy_history_batches']);
    }
}
