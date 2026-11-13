<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MysqlSchemaSnapshot;
use Tests\TestCase;

/**
 * A reused MySQL schema must start every test exactly where a fresh migration would: the migrations' own rows present,
 * every other row gone, AUTO_INCREMENT counters back where migration left them, and any schema change refused.
 */
class MysqlSchemaSnapshotTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The schema snapshot exists only for MySQL, where a fresh migration takes about 85 s.');
        }
    }

    public function test_a_reset_restores_the_migrated_rows_and_counters_and_removes_everything_else(): void
    {
        // Start from a capture of exactly the migrated state, whichever path this test's setup took.
        DB::statement('SET SESSION information_schema_stats_expiry = 0');
        $migrations = DB::table('migrations')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $jobsIncrement = $this->nextIncrement('jobs');
        $this->assertNotSame([], $migrations);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('cache')->count());

        DB::table('cache')->insert(['key' => 'snapshot-probe', 'value' => 'x', 'expiration' => 1]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);
        DB::table('migrations')->where('id', $migrations[0]['id'])->update(['batch' => 999]);
        $this->assertGreaterThan($jobsIncrement, $this->nextIncrement('jobs'));

        $this->assertTrue(MysqlSchemaSnapshot::restore(DB::connection()));

        $this->assertSame(0, DB::table('cache')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame($jobsIncrement, $this->nextIncrement('jobs'));
        $this->assertSame($migrations, DB::table('migrations')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
    }

    public function test_a_changed_schema_is_never_reused(): void
    {
        Schema::create('zz_snapshot_probe', fn ($table) => $table->id());

        $this->assertFalse(MysqlSchemaSnapshot::restore(DB::connection()));
        // The baseline is forgotten, so the next test's setup migrates fresh (which also drops this table).
        $this->assertFalse(MysqlSchemaSnapshot::restore(DB::connection()));
    }

    public function test_the_opt_out_disables_reuse(): void
    {
        putenv('VA_TEST_MIGRATE_EACH_TEST=1');
        try {
            $this->assertFalse(MysqlSchemaSnapshot::enabled(DB::connection()));
            $this->assertFalse(MysqlSchemaSnapshot::restore(DB::connection()));
        } finally {
            putenv('VA_TEST_MIGRATE_EACH_TEST');
        }
        $this->assertTrue(MysqlSchemaSnapshot::enabled(DB::connection()));
    }

    private function nextIncrement(string $table): int
    {
        return (int) DB::scalar('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }
}
