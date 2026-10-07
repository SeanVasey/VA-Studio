<?php

namespace Tests\Review;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class NativeEpochCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('This independent canary requires genuine disposable MySQL.');
        }
    }

    public function test_actual_fk_cascade_closure_is_covered_by_all_seventeen_guarded_dependencies(): void
    {
        $this->assertCount(17, DiscoveryEpoch::DEPENDENCIES);
        $dependencies = array_fill_keys(DiscoveryEpoch::DEPENDENCIES, true);
        $rows = DB::select('SELECT k.TABLE_NAME AS child_table, k.REFERENCED_TABLE_NAME AS parent_table, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION');
        $examined = 0;
        foreach ($rows as $row) {
            if (! isset($dependencies[$row->child_table])) {
                continue;
            }
            $examined++;
            foreach ([$row->UPDATE_RULE, $row->DELETE_RULE] as $rule) {
                if (in_array($rule, ['CASCADE', 'SET NULL'], true)) {
                    $this->assertArrayHasKey($row->parent_table, $dependencies,
                        'An unguarded parent can cascade/null a discovery dependency: '.$row->child_table.' <- '.$row->parent_table);
                }
            }
        }
        $this->assertGreaterThan(20, $examined);
        (new DiscoveryEpoch)->assertInstalled(DB::connection()->getPdo(), 'mysql');
        $this->assertCount(54, DiscoveryEpoch::guards('mysql'));
    }

    public function test_epoch_owner_reset_delete_replace_and_jump_refuse_without_data_or_bookkeeping_changes(): void
    {
        DB::table('tracks')->insert(['title' => 'Synthetic epoch-owner canary', 'slug' => 'epoch-owner-canary']);
        $pdo = DB::connection()->getPdo();
        $before = $this->snapshot();
        foreach (['UPDATE catalog_discovery_epoch SET epoch = 0',
            'UPDATE catalog_discovery_epoch SET epoch = epoch + 2',
            'UPDATE catalog_discovery_epoch SET schema_version = 2',
            'UPDATE catalog_discovery_epoch SET id = 2',
            'DELETE FROM catalog_discovery_epoch',
            'REPLACE INTO catalog_discovery_epoch (id, epoch, schema_version) VALUES (1, 0, 1)',
            'INSERT INTO catalog_discovery_epoch (id, epoch, schema_version) VALUES (1, 0, 1) ON DUPLICATE KEY UPDATE epoch = 0'] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail('Forbidden epoch owner mutation committed: '.$sql);
            } catch (\PDOException $error) {
                $this->assertSame('45000', $error->errorInfo[0] ?? null, $error->getMessage());
                $this->assertSame($before, $this->snapshot());
            }
        }
        (new DiscoveryEpoch)->assertInstalled($pdo, 'mysql');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_parent_dml_and_epoch_rollback_restore_all_owned_objects_and_migration_rows(): void
    {
        $before = $this->snapshot();
        $epoch = (new DiscoveryEpoch)->current(DB::connection()->getPdo());
        DB::beginTransaction();
        try {
            $id = DB::table('tracks')->insertGetId(['title' => 'Synthetic transaction', 'slug' => 'transaction-canary']);
            DB::table('tracks')->where('id', $id)->update(['title' => 'Synthetic ABA']);
            DB::table('tracks')->where('id', $id)->update(['title' => 'Synthetic transaction']);
            $this->assertSame($epoch + 3, (new DiscoveryEpoch)->current(DB::connection()->getPdo()));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
        (new DiscoveryEpoch)->assertInstalled(DB::connection()->getPdo(), 'mysql');
    }

    public function test_last_increment_is_atomic_and_counter_exhaustion_refuses_the_next_parent_write(): void
    {
        $pdo = DB::connection()->getPdo();
        $guard = DiscoveryEpoch::guards('mysql')['cde_own_update'];
        $pdo->exec('DROP TRIGGER cde_own_update');
        $pdo->exec('UPDATE catalog_discovery_epoch SET epoch = '.(DiscoveryEpoch::MAX - 1));
        $pdo->exec($guard['sql']);
        DB::table('tracks')->insert(['title' => 'Last synthetic increment', 'slug' => 'last-increment']);
        $this->assertSame(DiscoveryEpoch::MAX, (int) DB::table(DiscoveryEpoch::TABLE)->value('epoch'));
        $before = $this->snapshot();
        try {
            DB::table('tracks')->insert(['title' => 'Refused synthetic increment', 'slug' => 'refused-increment']);
            $this->fail('Counter exhaustion allowed a parent write.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(1, DB::table('tracks')->count());
        }
        (new DiscoveryEpoch)->assertInstalled($pdo, 'mysql');
        $this->expectException(LogicException::class);
        app(CurrentEligibleTrackSnapshot::class)->capture();
    }

    private function snapshot(): array
    {
        return [
            'migrations' => DB::table('migrations')->orderBy('id')->get()->toJson(),
            'epoch' => DB::table(DiscoveryEpoch::TABLE)->get()->toJson(),
            'tracks' => DB::table('tracks')->orderBy('id')->get()->toJson(),
            'owned_guards' => json_encode(DB::select("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'cde\\_%' ORDER BY TRIGGER_NAME"), JSON_THROW_ON_ERROR),
            'owned_columns' => json_encode(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'catalog_discovery_epoch' ORDER BY ORDINAL_POSITION"), JSON_THROW_ON_ERROR),
        ];
    }
}
