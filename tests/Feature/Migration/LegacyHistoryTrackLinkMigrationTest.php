<?php

namespace Tests\Feature\Migration;

use App\Support\MigrationDefinitions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * SQL guards of the additive legacy track link table, on SQLite and natively on MySQL: rows are append-only
 * (UPDATE, DELETE and REPLACE refused), one link per line version, the method vocabulary is byte-exact, a staff
 * resolution carries a reference hash and an exact identity link does not, and a link must name the exact archived
 * line version and the exact track of its catalog import mapping. Forward-only.
 */
class LegacyHistoryTrackLinkMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_10_280500_legacy_history_track_links.php';

    private const TABLE = 'legacy_history_track_links';

    public function test_the_schema_exists_with_a_real_unique_line_index_and_rollback_or_reinstallation_refuses(): void
    {
        $migration = MigrationDefinitions::load(self::MIGRATION);
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $unique = [];
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if ($index['unique'] && ! $index['primary']) {
                $unique[] = $index['columns'];
            }
        }
        $this->assertSame([['line_id']], $unique);
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
            $this->assertSame('Retained legacy track link schema requires explicit recovery review.', $error->getMessage());
        }
    }

    public function test_a_link_is_append_only_and_one_line_version_has_one_link(): void
    {
        [$row, $other] = $this->rows();
        $id = DB::table(self::TABLE)->insertGetId($row);
        $this->refused(fn () => DB::table(self::TABLE)->where('id', $id)->update(['track_id' => $other['track_id'], 'mapping_id' => $other['mapping_id']]), 'update');
        $this->refused(fn () => DB::table(self::TABLE)->where('id', $id)->update(['created_at' => now()->addDay()]), 'touch');
        $this->refused(fn () => DB::table(self::TABLE)->where('id', $id)->delete(), 'delete');
        $replace = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE INTO' : 'REPLACE INTO';
        foreach ([['id' => $id] + $row, $other + $row] as $case => $values) {
            $this->refused(fn () => DB::insert($replace.' '.self::TABLE.' ('.implode(', ', array_keys($values)).') VALUES ('
                .implode(', ', array_fill(0, count($values), '?')).')', array_values($values)), 'replace '.$case);
        }
        // A second link for the same line version, even to another candidate, is refused: the mapping only grows.
        $this->refused(fn () => DB::table(self::TABLE)->insert($other + $row), 'second link');
        $stored = DB::table(self::TABLE)->sole();
        $this->assertSame([$row['mapping_id'], $row['track_id'], 'exact_source_identity'], [(int) $stored->mapping_id, (int) $stored->track_id, $stored->method]);
    }

    public function test_method_reference_and_evidence_guards_refuse_every_malformed_or_mismatched_link(): void
    {
        [$row, $other] = $this->rows();
        $hash = str_repeat('b', 64);
        $cases = [
            'unknown method' => ['method' => 'guessed'],
            'method case' => ['method' => 'Exact_source_identity'],
            'method padding' => ['method' => 'exact_source_identity '],
            'staff resolution without a reference' => ['method' => 'staff_resolution'],
            'staff resolution with a malformed reference' => ['method' => 'staff_resolution', 'reference_hmac' => str_repeat('G', 64)],
            'exact identity carrying a reference' => ['reference_hmac' => $hash],
            'another line key' => ['line_record_key' => $hash],
            'another line version' => ['line_record_sha256' => $hash],
            'uppercase line key' => ['line_record_key' => strtoupper($row['line_record_key'])],
            'track that is not the mapping\'s' => ['track_id' => $other['track_id']],
            'mapping that is not the track\'s' => ['mapping_id' => $other['mapping_id']],
            'malformed candidates hash' => ['candidates_sha256' => 'short'],
        ];
        foreach ($cases as $case => $change) {
            $this->refused(fn () => DB::table(self::TABLE)->insert($change + $row), $case);
        }
        $this->assertSame(0, DB::table(self::TABLE)->count());
        DB::table(self::TABLE)->insert(['method' => 'staff_resolution', 'reference_hmac' => $hash] + $other + $row);
        $this->assertSame('staff_resolution', DB::table(self::TABLE)->sole()->method);
    }

    /** @return array{0: array<string, mixed>, 1: array{mapping_id: int, track_id: int}} one valid link and a second mapping */
    private function rows(): array
    {
        $user = LicenseFixtures::admin();
        $hash = str_repeat('a', 64);
        $batch = DB::table('legacy_history_batches')->insertGetId(['snapshot_sha256' => $hash, 'snapshot_id' => 'synthetic', 'source_system' => 'synthetic',
            'acquisition_method' => 'synthetic_fixture', 'acquired_at' => '2026-09-01T01:00:00Z', 'source_as_of' => '2026-09-01T00:00:00Z',
            'transform_version' => 'v1', 'expected_records' => 1, 'members' => '{}', 'members_sha256' => $hash, 'actor_id' => $user->id, 'created_at' => now()]);
        $line = DB::table('legacy_history_lines')->insertGetId(['batch_id' => $batch, 'record_key' => $hash, 'record_sha256' => str_repeat('c', 64),
            'order_record_key' => $hash, 'source_order_id' => 'synthetic-ord', 'source_line_id' => '1', 'track_source_id' => 'synthetic-track',
            'license_label' => 'basic', 'exclusive' => false, 'amount_minor' => 100, 'refunded_minor' => 0, 'currency' => 'USD',
            'classification' => 'archive_only', 'record_ciphertext' => 'synthetic', 'actor_id' => $user->id, 'created_at' => now()]);
        $mapping = LegacyHistoryFixtures::catalogMapping('synthetic', 'synthetic-track', $user);

        return [['line_id' => $line, 'line_record_key' => $hash, 'line_record_sha256' => str_repeat('c', 64), 'mapping_id' => $mapping['mapping_id'],
            'track_id' => $mapping['track_id'], 'method' => 'exact_source_identity', 'candidates_sha256' => $hash, 'reference_hmac' => null,
            'actor_id' => $user->id, 'created_at' => now()], LegacyHistoryFixtures::catalogMapping('synthetic/other', 'synthetic-track', $user)];
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
