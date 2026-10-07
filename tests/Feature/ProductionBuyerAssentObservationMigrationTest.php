<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations as Reports;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\TestCase;

class ProductionBuyerAssentObservationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_239000_production_buyer_assent_observations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    private function retain(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $service->retain($service->review($f['packet']->public_id, $f['actor']),
            ['buyer' => ['legal_name' => 'Synthetic Buyer', 'email' => 'buyer@example.test'],
                'reported_accepted' => true, 'observation_reference' => 'synthetic-migration-proof'],
            'migration-proof', $f['actor']);
    }

    public static function populations(): array
    {
        return ['empty' => [false], 'retained report' => [true]];
    }

    #[DataProvider('populations')]
    public function test_real_rollback_retains_schema_guards_data_and_bookkeeping(bool $populated): void
    {
        if ($populated) {
            $this->retain();
        }
        // Select this exact migration even when later children are added to the test database.
        $batch = (int) DB::table('migrations')->max('batch') + 1;
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->update(['batch' => $batch]));
        $this->assertSame(self::MIGRATION, app('migration.repository')->getLast()[0]->migration);
        $this->assertSame($populated ? 1 : 0, DB::table(Reports::TABLE)->count());
        $this->assertSame(3, $this->guards()->count());
        $before = $this->snapshot();
        $refused = false;
        try {
            Artisan::call('migrate:rollback', ['--path' => [database_path('migrations/'.self::MIGRATION.'.php')],
                '--realpath' => true, '--step' => 1, '--force' => true]);
        } catch (LogicException $exception) {
            $this->assertSame('Retain buyer observations and migration bookkeeping; no operational teardown is supported.', $exception->getMessage());
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function replacements(): array
    {
        return ['upsert' => ['upsert'], 'direct replace' => ['replace']];
    }

    #[DataProvider('replacements')]
    public function test_each_actual_sql_replacement_refuses_without_changing_retained_evidence(string $operation): void
    {
        $this->retain();
        $row = (array) DB::table(Reports::TABLE)->sole();
        $before = $this->snapshot();
        $row['payload_ciphertext'] = 'synthetic replacement bytes';
        $refused = false;
        try {
            if ($operation === 'upsert') {
                DB::table(Reports::TABLE)->upsert([$row], ['public_id'], ['payload_ciphertext']);
            } else {
                $grammar = DB::connection()->getQueryGrammar();
                $columns = implode(',', array_map($grammar->wrap(...), array_keys($row)));
                $prefix = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
                DB::insert($prefix.' INTO '.$grammar->wrapTable(Reports::TABLE).' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
            }
        } catch (QueryException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'This operation must execute and refuse independently of INSERT IGNORE.');
        $this->assertSame($before, $this->snapshot());
    }

    private function guards(): Collection
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', Reports::TABLE)->orderBy('name')->get()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', Reports::TABLE)->orderBy('TRIGGER_NAME')->get();
    }

    private function snapshot(): array
    {
        $tables = [Reports::TABLE, PacketEvidence::PACKETS, PacketEvidence::LINES];
        $definitions = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->whereIn('tbl_name', $tables)->orderBy('name')->get()->toJson()
            : array_map(fn ($table) => (array) DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table)), $tables);

        return ['migrations' => DB::table('migrations')->orderBy('id')->get()->toJson(),
            'definitions' => $definitions, 'guards' => $this->guards()->toJson(),
            'rows' => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
                [...$tables, 'users', 'audit_events'])];
    }
}
