<?php

namespace Tests\Feature\Migration;

use App\Support\MigrationDefinitions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * SQL guards of the additive legacy history archive migration, on SQLite and natively on MySQL: every row is
 * immutable (UPDATE, DELETE and REPLACE refused), consent can only be `unknown`, an archived line can only be
 * `archive_only`, a review can only be `verified_grant`, the authority ledger admits only its own vocabulary and
 * one event per sequence number, and the migration is forward-only.
 */
class LegacyHistoryArchiveMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_24_264000_legacy_history_archive.php';

    public function test_the_schema_exists_and_rollback_or_reinstallation_refuses_before_any_query(): void
    {
        $migration = MigrationDefinitions::load(self::MIGRATION);
        foreach ($migration::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        try {
            $migration->down();
            $this->fail('Rollback accepted.');
        } catch (LogicException) {
            $this->assertSame(0, $queries);
        }
        try {
            $migration->up();
            $this->fail('A second installation adopted the retained schema.');
        } catch (LogicException $error) {
            $this->assertSame('Retained legacy history archive schema needs explicit recovery review.', $error->getMessage());
        }
    }

    public function test_identity_columns_carry_real_unique_indexes_on_both_engines(): void
    {
        $expected = ['legacy_history_batches' => [['snapshot_sha256']], 'legacy_history_customers' => [['record_key', 'record_sha256']],
            'legacy_history_orders' => [['record_key', 'record_sha256']], 'legacy_history_lines' => [['record_key', 'record_sha256']],
            'legacy_history_contracts' => [['record_key', 'record_sha256']], 'legacy_history_grant_reviews' => [['line_id']],
            'legacy_exclusivity_authority_events' => [['sequence']]];
        foreach ($expected as $table => $uniques) {
            $actual = [];
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['unique'] && ! $index['primary']) {
                    $actual[] = $index['columns'];
                }
            }
            $this->assertSame($uniques, $actual, $table);
        }
    }

    public function test_every_archive_row_is_immutable_against_update_delete_and_replace(): void
    {
        foreach (array_keys($this->rows()) as $table) {
            $row = $this->rows()[$table];
            $id = DB::table($table)->insertGetId($row);
            $this->refused(fn () => DB::table($table)->where('id', $id)->update(['created_at' => now()->addDay()]), $table.' update');
            $this->refused(fn () => DB::table($table)->where('id', $id)->delete(), $table.' delete');
            $replace = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE INTO' : 'REPLACE INTO';
            $columns = array_keys(['id' => $id] + $row);
            $this->refused(fn () => DB::insert($replace.' '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
                array_values(['id' => $id] + $row)), $table.' replace');
            $this->assertSame(1, DB::table($table)->count(), $table);
            if ($table === 'legacy_history_batches') {
                $this->batch = $id;
            }
            if ($table === 'legacy_history_lines') {
                $this->line = $id;
            }
        }
    }

    public function test_consent_classification_and_authority_vocabularies_are_enforced_byte_exactly(): void
    {
        $rows = $this->rows();
        $this->batch = DB::table('legacy_history_batches')->insertGetId($rows['legacy_history_batches']);
        $rows = $this->rows();
        foreach (['granted', 'UNKNOWN', 'unknown ', ' unknown', 'opted_in'] as $consent) {
            $this->refused(fn () => DB::table('legacy_history_customers')->insert(['marketing_consent' => $consent] + $rows['legacy_history_customers']), 'consent '.$consent);
        }
        foreach (['verified_grant', 'Archive_only', 'archive_only '] as $classification) {
            $this->refused(fn () => DB::table('legacy_history_lines')->insert(['classification' => $classification] + $rows['legacy_history_lines']), 'line '.$classification);
        }
        $this->line = DB::table('legacy_history_lines')->insertGetId($rows['legacy_history_lines']);
        $rows = $this->rows();
        foreach (['archive_only', 'VERIFIED_GRANT'] as $classification) {
            $this->refused(fn () => DB::table('legacy_history_grant_reviews')->insert(['classification' => $classification] + $rows['legacy_history_grant_reviews']), 'review '.$classification);
        }
        foreach ([['authority' => 'both'], ['authority' => 'Vasey_store'], ['transition' => 'seize'], ['authority' => 'beatstars ']] as $bad) {
            $this->refused(fn () => DB::table('legacy_exclusivity_authority_events')->insert($bad + $rows['legacy_exclusivity_authority_events']), json_encode($bad));
        }
        DB::table('legacy_exclusivity_authority_events')->insert($rows['legacy_exclusivity_authority_events']);
        $this->refused(fn () => DB::table('legacy_exclusivity_authority_events')->insert(['authority' => 'frozen', 'transition' => 'freeze'] + $rows['legacy_exclusivity_authority_events']), 'duplicate sequence');
        DB::table('legacy_history_customers')->insert($rows['legacy_history_customers']);
        $this->assertSame(['unknown'], DB::table('legacy_history_customers')->pluck('marketing_consent')->all());
    }

    private int $batch = 0;

    private int $line = 0;

    private ?int $user = null;

    /** One valid row per table, in dependency order. */
    private function rows(): array
    {
        $this->user ??= LicenseFixtures::admin()->id;
        $hash = str_repeat('a', 64);
        $common = ['record_key' => $hash, 'record_sha256' => $hash, 'record_ciphertext' => 'synthetic', 'created_at' => now()];

        return [
            'legacy_history_batches' => ['snapshot_sha256' => $hash, 'snapshot_id' => 'synthetic', 'source_system' => 'synthetic', 'acquisition_method' => 'synthetic_fixture',
                'acquired_at' => '2026-09-01T01:00:00Z', 'source_as_of' => '2026-09-01T00:00:00Z', 'transform_version' => 'v1', 'expected_records' => 1,
                'actor_id' => $this->user, 'created_at' => now()],
            'legacy_history_customers' => ['batch_id' => $this->batch ?: null, 'source_customer_id' => 'synthetic-cust', 'email_hmac' => $hash, 'marketing_consent' => 'unknown'] + $common,
            'legacy_history_orders' => ['batch_id' => $this->batch ?: null, 'source_order_id' => 'synthetic-ord', 'customer_record_key' => $hash, 'status' => 'paid',
                'currency' => 'USD', 'total_minor' => 100, 'refunded_minor' => 0, 'ordered_at' => '2026-08-01T00:00:00Z'] + $common,
            'legacy_history_lines' => ['batch_id' => $this->batch ?: null, 'order_record_key' => $hash, 'source_order_id' => 'synthetic-ord', 'source_line_id' => '1',
                'track_source_id' => 'synthetic-track', 'license_label' => 'basic', 'exclusive' => false, 'amount_minor' => 100, 'refunded_minor' => 0,
                'currency' => 'USD', 'classification' => 'archive_only'] + $common,
            'legacy_history_contracts' => ['batch_id' => $this->batch ?: null, 'line_record_key' => $hash, 'source_contract_id' => 'synthetic-contract',
                'original_sha256' => $hash, 'original_bytes' => 1, 'media_type' => 'text/plain', 'storage_path' => 'legacy-history/contracts/aa/'.$hash] + $common,
            'legacy_history_grant_reviews' => ['line_id' => $this->line ?: null, 'line_record_key' => $hash, 'classification' => 'verified_grant', 'evidence_sha256' => $hash,
                'reference_hmac' => $hash, 'reviewer_id' => $this->user, 'created_at' => now()],
            'legacy_exclusivity_authority_events' => ['sequence' => 1, 'authority' => 'beatstars', 'transition' => 'declare', 'reference_hmac' => $hash,
                'actor_id' => $this->user, 'created_at' => now()],
        ];
    }

    private function refused(callable $write, string $case): void
    {
        try {
            $write();
            $this->fail('Accepted: '.$case);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
