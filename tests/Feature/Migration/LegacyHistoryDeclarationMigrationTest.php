<?php

namespace Tests\Feature\Migration;

use App\Domain\Migration\History\HistoryNormalizer;
use App\Domain\Migration\History\LegacyArchiveRefusal;
use App\Domain\Migration\History\LegacyGrantReview;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Support\CanonicalJson;
use App\Support\MigrationDefinitions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Real additive migration and declaration lifecycle on both SQLite and disposable native MySQL. */
class LegacyHistoryDeclarationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const TABLE = 'legacy_history_contract_declarations';

    private const MIGRATION = '2026_11_01_264010_legacy_history_contract_declarations.php';

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

    public function test_populated_v1_upgrade_and_resume_preserve_originals_actors_reviews_and_guards(): void
    {
        Schema::drop(self::TABLE); // Empty disposable sidecar only; exercise the actual additive upgrade below.
        $loaded = $this->load();
        $v1 = json_decode(file_get_contents(base_path('tests/Fixtures/migration/history-synthetic/snapshots/baseline-v1.json')), true, 32, JSON_THROW_ON_ERROR);
        $actor = LicenseFixtures::admin();
        LegacyHistoryFixtures::import($v1, CanonicalJson::hash($v1), $loaded['files'], $actor, 6);
        $first = $this->retained();
        MigrationDefinitions::load(self::MIGRATION)->up();
        $this->assertSame($first, $this->retained());
        $this->assertTrue(LegacyHistoryFixtures::import($v1, CanonicalJson::hash($v1), $loaded['files'], $actor, 1000)['complete']);
        $line = DB::table('legacy_history_lines')->where('record_key', $this->line($v1, 'synthetic-ord-1002'))->sole();
        DB::table('legacy_history_grant_reviews')->insert(['line_id' => $line->id, 'line_record_key' => $line->record_key,
            'classification' => 'verified_grant', 'evidence_sha256' => str_repeat('a', 64), 'reference_hmac' => str_repeat('b', 64),
            'reviewer_id' => LicenseFixtures::admin()->id, 'created_at' => now()]);
        $before = $this->retained();
        LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000);
        $this->assertSame($before, $this->retained());
        $this->assertSame('reconciled', (new LegacyHistoryArchive)->reconcile($v1, CanonicalJson::hash($v1))['result']);
        $this->assertSame(['declaration_history_coverage_unproven'], (new LegacyGrantReview)->inspect($line->record_key)['blockers']);
        $this->assertSame('archive_only', (new LegacyGrantReview)->classification($line->record_key));
        $this->queryRefused(fn () => DB::table('legacy_history_grant_reviews')->where('line_id', $line->id)->delete());
        $this->queryRefused(fn () => DB::table('legacy_history_contracts')->update(['original_bytes' => 999]));
        foreach (['up', 'down'] as $operation) {
            try {
                MigrationDefinitions::load(self::MIGRATION)->{$operation}();
                $this->fail('Retained schema adopted or dropped.');
            } catch (LogicException) {
                $this->assertSame($before, $this->retained());
            }
        }
    }

    public function test_partial_or_foreign_declaration_schema_is_never_adopted(): void
    {
        Schema::drop(self::TABLE);
        Schema::create(self::TABLE, fn ($table) => $table->string('foreign_evidence'));
        DB::table(self::TABLE)->insert(['foreign_evidence' => 'SYNTHETIC retained marker']);
        try {
            MigrationDefinitions::load(self::MIGRATION)->up();
            $this->fail('Foreign sidecar adopted.');
        } catch (LogicException) {
            $this->assertSame('SYNTHETIC retained marker', DB::table(self::TABLE)->sole()->foreign_evidence);
            $this->assertFalse(Schema::hasColumn(self::TABLE, 'record_key'));
        }
    }

    public function test_sidecar_guards_enforce_identity_version_original_shape_foreign_keys_and_immutability(): void
    {
        $loaded = $this->load();
        $actor = LicenseFixtures::admin();
        LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000);
        $row = (array) DB::table(self::TABLE)->whereNotNull('original_id')->first();
        $id = $row['id'];
        $this->queryRefused(fn () => DB::table(self::TABLE)->where('id', $id)->update(['created_at' => now()->addDay()]));
        $this->queryRefused(fn () => DB::table(self::TABLE)->where('id', $id)->delete());
        foreach ([['id' => $id], []] as $identity) {
            $replacement = $identity + array_diff_key($row, ['id' => true]);
            $prefix = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE INTO' : 'REPLACE INTO';
            $this->queryRefused(fn () => DB::insert($prefix.' '.self::TABLE.' ('.implode(', ', array_keys($replacement)).') VALUES ('.implode(', ', array_fill(0, count($replacement), '?')).')', array_values($replacement)));
        }
        $this->queryRefused(fn () => DB::table(self::TABLE)->upsert([$row], ['record_key', 'record_sha256'], ['record_ciphertext']));
        $this->assertSame($row, (array) DB::table(self::TABLE)->where('id', $id)->sole());
        $base = array_diff_key($row, ['id' => true]);
        foreach ([['record_key' => strtoupper($row['record_key'])], ['record_sha256' => str_repeat('z', 64)],
            ['source_contract_id' => 'bad source identity'], ['record_ciphertext' => ''], ['batch_id' => 99999999], ['actor_id' => 99999999],
            ['original_id' => null], ['supplied_original_bytes' => null], ['supplied_original_bytes' => -1],
            ['supplied_original_sha256' => str_repeat('b', 64)], ['original_id' => 99999999]] as $bad) {
            $candidate = $bad + ['record_sha256' => hash('sha256', json_encode($bad))] + $base;
            $this->queryRefused(fn () => DB::table(self::TABLE)->insert($candidate));
        }
        $foreign = Schema::getForeignKeys(self::TABLE);
        $this->assertEqualsCanonicalizing(['batch_id', 'actor_id', 'original_id'], array_merge(...array_column($foreign, 'columns')));
        $uniques = array_values(array_filter(Schema::getIndexes(self::TABLE), fn ($index) => $index['unique'] && ! $index['primary']));
        $this->assertSame([['record_key', 'record_sha256']], array_column($uniques, 'columns'));
    }

    public function test_second_missing_declaration_is_retained_through_omission_without_creating_an_original(): void
    {
        $actor = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $loaded = $this->load(function (array $parts): array {
            $parts['contracts'] .= "synthetic-extra,synthetic-ord-1001,1,synthetic-extra.txt,\n";

            return $parts;
        });
        $before = LegacyHistoryFixtures::nonArchiveState();
        LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000);
        $line = $this->line($loaded['snapshot']);
        $review = new LegacyGrantReview;
        $this->assertContains('multiple_contracts', $review->inspect($line)['blockers']);
        $this->assertSame(4, DB::table('legacy_history_contracts')->count());
        $missing = DB::table(self::TABLE)->where('source_contract_id', 'synthetic-extra')->sole();
        $this->assertNull($missing->original_id);
        $this->assertNull($missing->supplied_original_sha256);
        $this->assertCount(4, Storage::disk('local')->allFiles(LegacyHistoryArchive::CONTRACT_PREFIX));
        $omitting = $this->load(fn ($parts) => $this->newer($parts, 2));
        LegacyHistoryFixtures::import($omitting['snapshot'], $omitting['sha'], $omitting['files'], $actor, 1000);
        $this->assertContains('multiple_contracts', $review->inspect($line)['blockers']);
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC declaration review', true));
        $this->assertSame($before, LegacyHistoryFixtures::nonArchiveState());
    }

    public function test_single_missing_original_later_requires_its_genuine_exact_original_and_rehash(): void
    {
        $actor = LicenseFixtures::admin();
        $missing = $this->load(function (array $parts): array {
            unset($parts['files']['synthetic-contract-1001-1.txt']);

            return $parts;
        });
        LegacyHistoryFixtures::import($missing['snapshot'], $missing['sha'], $missing['files'], $actor, 1000);
        $line = $this->line($missing['snapshot']);
        $review = new LegacyGrantReview;
        $this->assertContains('contract_original_missing', $review->inspect($line)['blockers']);
        $this->assertSame(3, DB::table('legacy_history_contracts')->count());
        $supplied = $this->load(fn ($parts) => $this->newer($parts, 2));
        LegacyHistoryFixtures::import($supplied['snapshot'], $supplied['sha'], $supplied['files'], $actor, 1000);
        $this->assertSame([], $review->inspect($line)['blockers']);
        $reviewer = LicenseFixtures::admin();
        LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC exact original', true);
        $oldReview = (array) DB::table('legacy_history_grant_reviews')->sole();
        $original = DB::table('legacy_history_contracts')->where('line_record_key', $line)->sole();
        Storage::disk('local')->put($original->storage_path, 'SYNTHETIC substituted bytes');
        $this->assertSame('archive_only', $review->classification($line));
        $this->assertContains('contract_original_unverified', $review->inspect($line)['blockers']);
        $this->assertSame($oldReview, (array) DB::table('legacy_history_grant_reviews')->sole());
    }

    public function test_declaration_segment_interruption_stays_incomplete_and_genuine_resume_completes(): void
    {
        $loaded = $this->load();
        $actor = LicenseFixtures::admin();
        $nonDeclarationCount = count($loaded['snapshot']['customers']) + count($loaded['snapshot']['orders'])
            + count($loaded['snapshot']['lines']) + count($loaded['snapshot']['contracts']);
        $partial = LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, $nonDeclarationCount);
        $this->assertFalse($partial['complete']);
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertSame(['latest_export_not_archived'], (new LegacyGrantReview)->inspect($this->line($loaded['snapshot']))['blockers']);
        try {
            LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000, function (int $count): void {
                if ($count === 2) {
                    throw new RuntimeException('SYNTHETIC interrupted declaration segment');
                }
            });
            $this->fail('Interruption did not roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('SYNTHETIC interrupted declaration segment', $error->getMessage());
        }
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertTrue(LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000)['complete']);
        $this->assertSame('reconciled', (new LegacyHistoryArchive)->reconcile($loaded['snapshot'], $loaded['sha'])['result']);
    }

    public function test_any_retained_v1_batch_even_incomplete_late_arriving_blocks_inspection_write_and_classification(): void
    {
        $loaded = $this->load();
        $actor = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000);
        $line = $this->line($loaded['snapshot']);
        $review = new LegacyGrantReview;
        LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC v2 review', true);
        $oldReview = (array) DB::table('legacy_history_grant_reviews')->sole();
        $v1 = json_decode(file_get_contents(base_path('tests/Fixtures/migration/history-synthetic/snapshots/baseline-v1.json')), true, 32, JSON_THROW_ON_ERROR);
        $v1['source_as_of'] = '2026-08-31T00:00:00Z';
        // Force a genuine pending cohort; this is an older package arriving after a reviewed complete v2.
        $v1['customers'][0]['display_name'] = 'SYNTHETIC older customer detail';
        $v1['customers'][0]['record_sha256'] = HistoryNormalizer::recordHash($v1['customers'][0]);
        $v1['customers'][1]['display_name'] = 'SYNTHETIC older customer detail two';
        $v1['customers'][1]['record_sha256'] = HistoryNormalizer::recordHash($v1['customers'][1]);
        $this->assertFalse(LegacyHistoryFixtures::import($v1, CanonicalJson::hash($v1), $loaded['files'], $actor, 1)['complete']);
        $this->assertSame(['declaration_history_coverage_unproven'], $review->inspect($line)['blockers']);
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC refused mixed history', true));
        $this->assertSame('archive_only', $review->classification($line));
        $this->assertSame($oldReview, (array) DB::table('legacy_history_grant_reviews')->sole());
    }

    public function test_historical_relink_and_move_back_remain_blockers_after_newer_copy_or_omission(): void
    {
        $actor = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $make = function (array $parts, int $stage): array {
            if ($stage > 0) {
                $parts = $this->newer($parts, $stage + 1);
            }
            if ($stage < 3) {
                $parts['contracts'] .= 'synthetic-extra,synthetic-ord-1001,'.($stage === 1 ? '2' : '1').",synthetic-extra.txt,\n";
            }

            return $parts;
        };
        $review = new LegacyGrantReview;
        for ($stage = 0; $stage < 4; $stage++) {
            $loaded = $this->load(fn ($parts) => $make($parts, $stage));
            LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000);
            $line = $this->line($loaded['snapshot']);
            $this->assertContains('multiple_contracts', $review->inspect($line)['blockers']);
            if ($stage > 0) {
                $this->assertContains('contract_declaration_relinked', $review->inspect($line)['blockers']);
                $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC relink refusal', true));
            }
        }
        $this->assertSame(0, DB::table('legacy_history_grant_reviews')->count());
    }

    public function test_genuine_zero_byte_original_has_an_exact_present_link_and_a_verified_artifact(): void
    {
        $loaded = $this->load(function (array $parts): array {
            $parts['files']['synthetic-contract-1001-1.txt'] = '';
            $parts['contracts'] = preg_replace('/(synthetic-contract-1001-1\.txt,)[a-f0-9]{64}/', '$1'.hash('sha256', ''), $parts['contracts']);

            return $parts;
        });
        LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], LicenseFixtures::admin(), 1000);
        $declaration = DB::table(self::TABLE)->where('line_record_key', $this->line($loaded['snapshot']))->sole();
        $this->assertNotNull($declaration->original_id);
        $this->assertSame(0, (int) $declaration->supplied_original_bytes);
        $this->assertSame(hash('sha256', ''), $declaration->supplied_original_sha256);
        $this->assertTrue(LegacyHistoryArchive::declarationIntact($declaration, LegacyHistoryArchive::decrypt($declaration)));
        $this->assertSame([], (new LegacyGrantReview)->inspect($this->line($loaded['snapshot']))['blockers']);
    }

    public function test_unknown_batch_transform_and_missing_declaration_cohort_never_supply_completeness(): void
    {
        $loaded = $this->load();
        $actor = LicenseFixtures::admin();
        $missing = $loaded['snapshot'];
        unset($missing['contract_declarations']);
        $this->domainRefused('snapshot_cohorts', fn () => (new LegacyHistoryArchive)->plan($missing, CanonicalJson::hash($missing)));
        LegacyHistoryFixtures::import($loaded['snapshot'], $loaded['sha'], $loaded['files'], $actor, 1000);
        $batch = (array) DB::table('legacy_history_batches')->sole();
        unset($batch['id']);
        $batch['transform_version'] = 'unknown-transform';
        $batch['snapshot_sha256'] = str_repeat('f', 64);
        DB::table('legacy_history_batches')->insert($batch);
        $line = $this->line($loaded['snapshot']);
        $this->domainRefused('snapshot_version', fn () => (new LegacyGrantReview)->inspect($line));
        $this->domainRefused('snapshot_version', fn () => LegacyHistoryFixtures::review(new LegacyGrantReview, $line, LicenseFixtures::admin(), 'SYNTHETIC unknown transform', true));
    }

    public function test_v1_silently_dropped_missing_declaration_cannot_be_recovered_from_newest_v2_originals(): void
    {
        $loaded = $this->load();
        $v1 = json_decode(file_get_contents(base_path('tests/Fixtures/migration/history-synthetic/snapshots/missing-extra-v1.json')), true, 32, JSON_THROW_ON_ERROR);
        $this->assertCount(4, $v1['contracts']);
        $this->assertArrayNotHasKey('contract_declarations', $v1);
        $actor = LicenseFixtures::admin();
        LegacyHistoryFixtures::import($v1, CanonicalJson::hash($v1), $loaded['files'], $actor, 1000);
        $v2 = $this->load(fn ($parts) => $this->newer($parts, 2));
        LegacyHistoryFixtures::import($v2['snapshot'], $v2['sha'], $v2['files'], $actor, 1000);
        $review = new LegacyGrantReview;
        $line = $this->line($v2['snapshot']);
        $this->assertSame(['declaration_history_coverage_unproven'], $review->inspect($line)['blockers']);
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, LicenseFixtures::admin(), 'SYNTHETIC hidden legacy declaration', true));
        $this->assertSame(4, DB::table('legacy_history_contracts')->count());
        $this->assertSame(0, DB::table('legacy_history_grant_reviews')->count());
    }

    public function test_declaration_and_original_origin_batch_row_and_segment_actors_remain_excluded_after_copy(): void
    {
        [$a, $b, $c, $d, $e, $f] = array_map(fn () => LicenseFixtures::admin(), range(1, 6));
        $missing = $this->load(function (array $parts): array {
            unset($parts['files']['synthetic-contract-1001-1.txt']);

            return $parts;
        });
        LegacyHistoryFixtures::import($missing['snapshot'], $missing['sha'], $missing['files'], $a, 1000);
        $supply = function (array $parts, int $day): array {
            $parts = $this->newer($parts, $day);
            $parts['customers'] = str_replace('SYNTHETIC Buyer Two', 'SYNTHETIC Buyer Two restated', $parts['customers']);

            return $parts;
        };
        $clearing = $this->load(fn ($parts) => $supply($parts, 2));
        // B creates the batch on an unrelated customer row; C inserts original/declaration; D runs a zero-row segment.
        $this->assertFalse(LegacyHistoryFixtures::import($clearing['snapshot'], $clearing['sha'], $clearing['files'], $b, 1)['complete']);
        $this->assertTrue(LegacyHistoryFixtures::import($clearing['snapshot'], $clearing['sha'], $clearing['files'], $c, 1000)['complete']);
        $this->assertSame(0, LegacyHistoryFixtures::import($clearing['snapshot'], $clearing['sha'], $clearing['files'], $d, 1000)['processed']);
        $copy = $this->load(fn ($parts) => $supply($parts, 3));
        LegacyHistoryFixtures::import($copy['snapshot'], $copy['sha'], $copy['files'], $e, 1000);
        $review = new LegacyGrantReview;
        $line = $this->line($copy['snapshot']);
        $this->assertSame([], $review->inspect($line)['blockers']);
        foreach ([$b, $c, $d] as $shaper) {
            $this->assertContains('reviewer_imported_evidence', LegacyHistoryFixtures::review($review, $line, $shaper, 'SYNTHETIC declaration origin', false)['blockers']);
            $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $shaper, 'SYNTHETIC declaration origin', true));
        }
        $this->assertSame('recorded', LegacyHistoryFixtures::review($review, $line, $f, 'SYNTHETIC independent declaration review', true)['outcome']);
    }

    public function test_uninserted_new_declaration_in_retained_incomplete_export_blocks_until_genuine_resume_then_ambiguity_remains(): void
    {
        $actor = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $baseline = $this->load();
        LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $actor, 1000);
        $line = $this->line($baseline['snapshot']);
        $review = new LegacyGrantReview;
        LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC prior independent review', true);
        $retainedReview = (array) DB::table('legacy_history_grant_reviews')->sole();
        $this->assertSame('verified_grant', $review->classification($line));
        $partial = $this->load(function (array $parts): array {
            $parts = $this->newer($parts, 2);
            $parts['customers'] = str_replace('SYNTHETIC Buyer Two', 'SYNTHETIC Buyer Two interrupted export', $parts['customers']);
            $parts['contracts'] .= "synthetic-extra,synthetic-ord-1001,1,synthetic-extra.txt,\n";

            return $parts;
        });
        $this->assertFalse(LegacyHistoryFixtures::import($partial['snapshot'], $partial['sha'], $partial['files'], $actor, 1)['complete']);
        $this->assertFalse(DB::table(self::TABLE)->where('source_contract_id', 'synthetic-extra')->exists());
        $omitting = $this->load(fn ($parts) => $this->newer($parts, 3));
        $this->assertTrue(LegacyHistoryFixtures::import($omitting['snapshot'], $omitting['sha'], $omitting['files'], $actor, 1000)['complete']);
        $this->assertSame(['retained_export_not_archived'], $review->inspect($line)['blockers']);
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC unseen declaration', true));
        $this->assertSame('archive_only', $review->classification($line));
        $this->assertSame($retainedReview, (array) DB::table('legacy_history_grant_reviews')->sole());
        $this->assertTrue(LegacyHistoryFixtures::import($partial['snapshot'], $partial['sha'], $partial['files'], $actor, 1000)['complete']);
        $this->assertContains('multiple_contracts', $review->inspect($line)['blockers']);
        $this->assertSame(4, DB::table('legacy_history_contracts')->count());
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC resumed ambiguity', true));
        $this->assertSame($retainedReview, (array) DB::table('legacy_history_grant_reviews')->sole());
    }

    public function test_superseded_uninserted_relink_refuses_until_resume_then_historical_relink_still_refuses(): void
    {
        $actor = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $baseline = $this->load();
        LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $actor, 1000);
        $partial = $this->load(function (array $parts): array {
            $parts = $this->newer($parts, 2);
            $parts['customers'] = str_replace('SYNTHETIC Buyer Two', 'SYNTHETIC Buyer Two interrupted relink', $parts['customers']);
            $parts['contracts'] = str_replace('synthetic-contract-1001-1,synthetic-ord-1001,1,',
                'synthetic-contract-1001-1,synthetic-ord-1001,2,', $parts['contracts']);

            return $parts;
        });
        $this->assertFalse(LegacyHistoryFixtures::import($partial['snapshot'], $partial['sha'], $partial['files'], $actor, 1)['complete']);
        $this->assertSame(1, DB::table(self::TABLE)->where('source_contract_id', 'synthetic-contract-1001-1')->count());
        $returned = $this->load(fn ($parts) => $this->newer($parts, 3));
        $this->assertTrue(LegacyHistoryFixtures::import($returned['snapshot'], $returned['sha'], $returned['files'], $actor, 1000)['complete']);
        $line = $this->line($baseline['snapshot']);
        $review = new LegacyGrantReview;
        $this->assertSame(['retained_export_not_archived'], $review->inspect($line)['blockers']);
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC superseded uninserted relink', true));
        $this->assertTrue(LegacyHistoryFixtures::import($partial['snapshot'], $partial['sha'], $partial['files'], $actor, 1000)['complete']);
        $this->assertContains('contract_declaration_relinked', $review->inspect($line)['blockers']);
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC resumed historical relink', true));
        $this->assertSame(0, DB::table('legacy_history_grant_reviews')->count());
    }

    public static function interruptedMembershipReviewStates(): array
    {
        return ['with prior review' => [true], 'without prior review' => [false]];
    }

    #[DataProvider('interruptedMembershipReviewStates')]
    public function test_uninserted_competitor_and_customer_in_older_partial_export_refuse_until_genuine_resume(bool $priorReview): void
    {
        $actor = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $baseline = $this->load();
        LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $actor, 1000);
        $line = $this->line($baseline['snapshot'], 'synthetic-ord-1002');
        $review = new LegacyGrantReview;
        if ($priorReview) {
            LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC independent exclusive review', true);
        }
        $retainedReviews = DB::table('legacy_history_grant_reviews')->orderBy('id')->get()->toJson();
        $this->assertTrue($review->inspect($line)['eligible']);
        $this->assertSame($priorReview ? 'verified_grant' : 'archive_only', $review->classification($line));
        $partial = $this->load(function (array $parts): array {
            $parts = $this->newer($parts, 2);
            $parts['customers'] = str_replace('SYNTHETIC Buyer One', 'SYNTHETIC Buyer One unrelated restatement', $parts['customers']);
            $parts['customers'] .= "synthetic-cust-009,buyer.two@example.invalid,SYNTHETIC Buyer Nine,\n";
            $parts['orders'] .= "synthetic-ord-1009,1,synthetic-cust-009,2026-08-09T10:00:00Z,Completed,synthetic-track-03,Exclusive Rights,500,0,synthetic-pay-1009,USD\n";

            return $parts;
        });
        $baselineHashes = array_column($baseline['snapshot']['customers'], 'record_sha256', 'record_key');
        $pendingCustomers = array_values(array_filter($partial['snapshot']['customers'],
            fn (array $record) => ($baselineHashes[$record['record_key']] ?? null) !== $record['record_sha256']));
        $this->assertSame(['synthetic-cust-001', 'synthetic-cust-009'], array_column($pendingCustomers, 'source_customer_id'));
        $this->assertLessThan(0, strcmp($pendingCustomers[0]['record_key'], $pendingCustomers[1]['record_key']));
        $this->assertFalse(LegacyHistoryFixtures::import($partial['snapshot'], $partial['sha'], $partial['files'], $actor, 1)['complete']);
        $this->assertTrue(DB::table('legacy_history_customers')->where('record_key', $pendingCustomers[0]['record_key'])
            ->where('record_sha256', $pendingCustomers[0]['record_sha256'])->exists());
        $this->assertFalse(DB::table('legacy_history_customers')->where('source_customer_id', 'synthetic-cust-009')->exists());
        $this->assertFalse(DB::table('legacy_history_orders')->where('source_order_id', 'synthetic-ord-1009')->exists());
        $this->assertSame(2, DB::table('legacy_history_customers')->where('source_customer_id', 'synthetic-cust-001')->count());
        $this->assertSame(5, DB::table('legacy_history_customers')->count()); // Four identities; one genuine restatement version.
        $later = $this->load(fn ($parts) => $this->newer($parts, 3));
        $this->assertTrue(LegacyHistoryFixtures::import($later['snapshot'], $later['sha'], $later['files'], $actor, 1000)['complete']);
        $inspection = $review->inspect($line);
        $this->assertFalse($inspection['eligible']);
        $this->assertSame(['retained_export_not_archived'], $inspection['blockers']);
        $this->domainRefused('not_eligible', fn () => LegacyHistoryFixtures::review($review, $line, $reviewer, 'SYNTHETIC unseen competitor', true));
        $this->assertSame('archive_only', $review->classification($line));
        $this->assertSame($retainedReviews, DB::table('legacy_history_grant_reviews')->orderBy('id')->get()->toJson());
        $this->assertTrue(LegacyHistoryFixtures::import($partial['snapshot'], $partial['sha'], $partial['files'], $actor, 1000)['complete']);
        $this->assertContains('duplicate_exclusive_sale', $review->inspect($line)['blockers']);
        $this->assertContains('ambiguous_customer_identity', $review->inspect($line)['blockers']);
        $this->assertSame($retainedReviews, DB::table('legacy_history_grant_reviews')->orderBy('id')->get()->toJson());
        $this->assertSame(4, DB::table('legacy_history_contracts')->count());
    }

    public function test_unrelated_source_incomplete_membership_does_not_contaminate_complete_source_admission(): void
    {
        $actor = LicenseFixtures::admin();
        $baseline = $this->load();
        LegacyHistoryFixtures::import($baseline['snapshot'], $baseline['sha'], $baseline['files'], $actor, 1000);
        $unrelated = $this->load(function (array $parts): array {
            $parts['mapping'] = str_replace('synthetic/beatstars-history', 'synthetic/unrelated-history', $parts['mapping']);

            return $parts;
        });
        $this->assertFalse(LegacyHistoryFixtures::import($unrelated['snapshot'], $unrelated['sha'], $unrelated['files'], $actor, 1)['complete']);
        $line = $this->line($baseline['snapshot']);
        $review = new LegacyGrantReview;
        $this->assertSame([], $review->inspect($line)['blockers']);
        $this->assertSame('recorded', LegacyHistoryFixtures::review($review, $line, LicenseFixtures::admin(), 'SYNTHETIC independent source review', true)['outcome']);
        $this->assertSame('verified_grant', $review->classification($line));
        $this->assertSame(1, DB::table('legacy_history_grant_reviews')->count());
        $this->assertSame(4, DB::table('legacy_history_contracts')->count());
    }

    private function load(?callable $change = null): array
    {
        $loaded = (new LegacyHistoryArchive)->load(LegacyHistoryFixtures::privateCopy('baseline', $change));
        $this->assertSame([], $loaded['result']['findings']);

        return ['snapshot' => $loaded['result']['snapshot'], 'sha' => $loaded['result']['snapshot_sha256'], 'files' => $loaded['source']['contracts']];
    }

    private function newer(array $parts, int $day): array
    {
        $parts['mapping'] = str_replace(['2026-09-01T01:00:00Z', '2026-09-01T00:00:00Z'],
            ['2026-10-0'.$day.'T01:00:00Z', '2026-10-0'.$day.'T00:00:00Z'], $parts['mapping']);

        return $parts;
    }

    private function line(array $snapshot, string $order = 'synthetic-ord-1001'): string
    {
        foreach ($snapshot['lines'] as $line) {
            if ($line['source_order_id'] === $order && $line['source_line_id'] === '1') {
                return $line['record_key'];
            }
        }
        $this->fail('Synthetic line absent.');
    }

    private function retained(): array
    {
        $state = [];
        foreach (['customers', 'orders', 'lines', 'contracts', 'grant_reviews'] as $cohort) {
            $state[$cohort] = DB::table('legacy_history_'.$cohort)->orderBy('id')->get()->toJson();
        }
        $state['files'] = [];
        foreach (Storage::disk('local')->allFiles(LegacyHistoryArchive::CONTRACT_PREFIX) as $path) {
            $state['files'][$path] = Storage::disk('local')->get($path);
        }
        ksort($state['files']);

        return $state;
    }

    private function queryRefused(callable $write): void
    {
        try {
            $write();
            $this->fail('Invalid declaration SQL accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function domainRefused(string $reason, callable $write): void
    {
        try {
            $write();
            $this->fail('Invalid declaration domain action accepted.');
        } catch (LegacyArchiveRefusal $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
