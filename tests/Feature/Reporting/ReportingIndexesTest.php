<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Reporting\OperationalHealth;
use App\Domain\Reporting\SalesReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

class ReportingIndexesTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const INDEXES = [
        'quotes' => ['created_at', 'quotes_reporting_created_at_index'],
        'orders' => ['created_at', 'orders_reporting_created_at_index'],
        'order_finalizations' => ['finalized_at', 'order_finalizations_reporting_finalized_at_index'],
        'test_unpaid_releases' => ['released_at', 'test_unpaid_releases_reporting_released_at_index'],
        'production_checkout_orders' => ['created_at', 'pco_orders_reporting_created_at_index'],
        'production_checkout_payments' => ['observed_at', 'pco_payments_reporting_observed_at_index'],
        'refund_line_allocations' => ['order_id', 'refund_line_allocations_reporting_order_id_index'],
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_03_269100_reporting_period_indexes.php');
    }

    private function assertIndexes(bool $present): void
    {
        foreach (self::INDEXES as $table => [$column, $name]) {
            $indexes = array_values(array_filter(Schema::getIndexes($table), fn (array $index): bool => $index['name'] === $name));
            $this->assertCount($present ? 1 : 0, $indexes, $table);
            if ($present) {
                $this->assertSame([$column], $indexes[0]['columns'], $table);
                $this->assertFalse($indexes[0]['unique'], $table);
                $this->assertFalse($indexes[0]['primary'], $table);
            }
        }
    }

    /** Retained target rows, columns, constraints, other indexes and complete trigger definitions. */
    private function retained(): array
    {
        $result = [];
        foreach (self::INDEXES as $table => [, $name]) {
            $result[$table] = [
                'rows' => DB::table(DB::getDriverName() === 'sqlite' ? 'main.'.$table : $table)->orderBy('id')->get()->toJson(),
                'columns' => Schema::getColumns($table),
                'foreign_keys' => Schema::getForeignKeys($table),
                'other_indexes' => $this->otherIndexes($table, $name),
            ];
        }
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', array_keys(self::INDEXES))->orderBy('name')->get()
                ->map(fn (object $row): array => [$row->name, $row->tbl_name, $row->sql])->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->whereIn('EVENT_OBJECT_TABLE', array_keys(self::INDEXES))->orderBy('TRIGGER_NAME')->get()
                ->map(fn (object $row): array => [$row->TRIGGER_NAME, $row->EVENT_OBJECT_TABLE, $row->ACTION_TIMING, $row->EVENT_MANIPULATION, $row->ACTION_STATEMENT])->all();
        $this->assertNotEmpty($triggers);

        return [$result, $triggers];
    }

    private function otherIndexes(string $table, string $owned): array
    {
        if (DB::getDriverName() === 'sqlite') {
            $rows = [];
            foreach (DB::select('PRAGMA main.index_list("'.$table.'")') as $index) {
                if ($index->name !== $owned) {
                    $identity = (array) $index;
                    unset($identity['seq']); // Catalog enumeration position changes when another index is removed.
                    $rows[$index->name] = [$identity, array_map(static fn (object $key): array => (array) $key,
                        DB::select('PRAGMA main.index_xinfo("'.str_replace('"', '""', $index->name).'")'))];
                }
            }
            ksort($rows);

            return array_values($rows);
        }

        return array_map(static fn (object $row): array => (array) $row, DB::select('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE, SUB_PART, COLLATION, EXPRESSION, IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME <> ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [DB::getDatabaseName(), $table, $owned]));
    }

    private function ddlDuring(callable $operation): array
    {
        $statements = [];
        $record = true;
        DB::listen(function ($query) use (&$statements, &$record): void {
            if ($record && preg_match('/\A\s*(?:create|alter|drop|truncate|rename)\b/i', $query->sql)) {
                $statements[] = $query->sql;
            }
        });
        try {
            $operation();
        } finally {
            $record = false;
        }

        return $statements;
    }

    private function populated(): User
    {
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        R::configure();
        Queue::fake();
        [$payments, $financial] = R::bind($this->app);
        $fixture = DeliveryFixtures::ready($payments);
        $staff = LicenseFixtures::admin();
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($staff);
        // Provider IDs allow only an alphanumeric suffix; observation follows the genuine payment.
        $this->travelTo($fixture['payment']->confirmed_at->toImmutable()->addSecond());
        R::provider($financial, [['re_REPORTINGINDEX', $fixture['payment']->amount_minor, 'succeeded']]);
        $this->assertSame('full', R::observe($fixture['order']->public_id, $staff)['refundState']);
        if (DB::table('refund_line_allocations')->where('order_id', $fixture['order']->id)->count() === 0) {
            $refund = RefundDisputeObject::where('order_id', $fixture['order']->id)->where('kind', 'refund')->sole();
            $lines = [];
            foreach ($fixture['original']['lines'] as $position => $line) {
                $lines[$position] = $line['pricing']['total_minor'];
            }
            app(RefundDisputeActions::class)->allocate($fixture['order']->public_id, $staff, (string) Str::uuid(),
                R::sequence($fixture['order']->public_id, $staff), $refund->public_id, $lines);
        }
        foreach (['quotes', 'orders', 'order_finalizations', 'refund_line_allocations'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table.' has genuine retained evidence');
        }

        return $staff;
    }

    private function reporting(User $staff): array
    {
        $sales = app(SalesReport::class)->build($staff, '2026-10-01', '2026-10-01', 'day')['test'];
        $this->assertTrue($sales['available']);
        $this->assertSame(1, $sales['currencies']['USD']['orders']);
        $this->assertSame(0, $sales['currencies']['USD']['net_minor']);
        $health = app(OperationalHealth::class)->section('commerce', $staff);
        $this->assertSame(0, collect($health['metrics'])->firstWhere('key', 'refund_unallocated')['value']);

        return [$sales, $health];
    }

    public function test_fresh_installation_and_index_only_rollback_preserve_the_existing_schema(): void
    {
        $this->assertIndexes(true);
        $before = $this->retained();
        $inquiryIndexes = Schema::getIndexes('customer_inquiries');
        $this->assertNotEmpty(array_filter($inquiryIndexes, fn (array $index): bool => $index['columns'] === ['state', 'id']));
        $this->migration()->down();
        $this->assertIndexes(false);
        $this->assertSame($before, $this->retained());
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->down()));
        $this->migration()->up();
        $this->assertIndexes(true);
        $this->assertSame($before, $this->retained());
        $this->assertSame($inquiryIndexes, Schema::getIndexes('customer_inquiries'));
    }

    public function test_populated_upgrade_preserves_evidence_triggers_and_reporting_results(): void
    {
        $this->migration()->down();
        $staff = $this->populated();
        $before = [$this->retained(), R::originals(), $this->reporting($staff)];
        $this->migration()->up();
        $this->assertIndexes(true);
        $this->assertSame($before, [$this->retained(), R::originals(), $this->reporting($staff)]);
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->up()));
        foreach (['quotes', 'orders', 'order_finalizations', 'refund_line_allocations'] as $table) {
            try {
                DB::table($table)->update(['id' => DB::raw('id')]);
                $this->fail('Immutable evidence update was accepted: '.$table);
            } catch (QueryException $error) {
                $this->assertStringContainsString('immutable', strtolower($error->getMessage()), $table);
            }
        }
        $this->migration()->down();
        $this->assertIndexes(false);
        $this->assertSame($before, [$this->retained(), R::originals(), $this->reporting($staff)]);
    }

    public function test_partial_installation_resumes_only_missing_indexes_without_changing_retained_evidence(): void
    {
        $staff = $this->populated();
        foreach (['orders', 'refund_line_allocations'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex(self::INDEXES[$table][1]));
        }
        $before = [$this->retained(), $this->reporting($staff)];
        $ddl = $this->ddlDuring(fn () => $this->migration()->up());
        $this->assertCount(2, $ddl);
        $this->assertStringContainsString(self::INDEXES['orders'][1], $ddl[0]);
        $this->assertStringContainsString(self::INDEXES['refund_line_allocations'][1], $ddl[1]);
        $this->assertIndexes(true);
        $this->assertSame($before, [$this->retained(), $this->reporting($staff)]);
    }

    public function test_conflicting_index_definition_refuses_up_and_down_before_any_schema_change(): void
    {
        // The collision is last, proving an earlier missing/installed index is not changed first.
        Schema::table('quotes', fn (Blueprint $table) => $table->dropIndex(self::INDEXES['quotes'][1]));
        Schema::table('refund_line_allocations', function (Blueprint $table): void {
            $table->dropIndex(self::INDEXES['refund_line_allocations'][1]);
            $table->index('amount_minor', self::INDEXES['refund_line_allocations'][1]);
        });
        $before = [$this->retained(), array_map(fn (string $table): array => Schema::getIndexes($table), array_keys(self::INDEXES))];
        foreach (['up', 'down'] as $direction) {
            $this->assertSame([], $this->ddlDuring(function () use ($direction): void {
                try {
                    $this->migration()->{$direction}();
                    $this->fail('A conflicting reporting index definition was accepted.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('Reporting index definition differs', $error->getMessage());
                }
            }));
            $this->assertSame($before, [$this->retained(), array_map(fn (string $table): array => Schema::getIndexes($table), array_keys(self::INDEXES))]);
        }
    }

    public function test_native_index_shapes_are_not_adopted_or_removed_in_either_direction(): void
    {
        $name = self::INDEXES['quotes'][1];
        $quoted = '`'.$name.'`';
        $shapes = DB::getDriverName() === 'sqlite' ? [
            'unique' => 'CREATE UNIQUE INDEX '.$quoted.' ON quotes (created_at)',
            'partial' => 'CREATE INDEX '.$quoted.' ON quotes (created_at) WHERE created_at IS NOT NULL',
            'descending' => 'CREATE INDEX '.$quoted.' ON quotes (created_at DESC)',
            'collation' => 'CREATE INDEX '.$quoted.' ON quotes (created_at COLLATE NOCASE)',
            'expression' => 'CREATE INDEX '.$quoted.' ON quotes (date(created_at))',
        ] : [
            'unique' => 'CREATE UNIQUE INDEX '.$quoted.' ON quotes (created_at)',
            'case_alias' => 'CREATE INDEX `'.strtoupper($name).'` ON quotes (created_at)',
            'descending' => 'CREATE INDEX '.$quoted.' ON quotes (created_at DESC)',
            'invisible' => 'CREATE INDEX '.$quoted.' ON quotes (created_at) INVISIBLE',
            'expression' => 'CREATE INDEX '.$quoted.' ON quotes ((DATE(created_at)))',
        ];
        foreach ($shapes as $kind => $statement) {
            Schema::table('quotes', fn (Blueprint $table) => $table->dropIndex($name));
            DB::unprepared($statement);
            try {
                $before = [$this->retained(), $this->indexCatalog()];
                foreach (['up', 'down'] as $direction) {
                    $this->assertReportingRefusal($direction, $kind);
                    $this->assertSame($before, [$this->retained(), $this->indexCatalog()], $kind.' '.$direction);
                }
            } finally {
                Schema::table('quotes', fn (Blueprint $table) => $table->dropIndex($name));
                $this->migration()->up();
            }
        }
        $this->assertIndexes(true);
    }

    public function test_reserved_names_are_preflighted_in_the_actual_engine_namespace_before_up_or_down(): void
    {
        $last = self::INDEXES['refund_line_allocations'][1];
        if (DB::getDriverName() === 'mysql') {
            // Unlike SQLite, the same index name on a foreign MySQL table is not a collision.
            DB::unprepared('CREATE TABLE rep_foreign_holder (marker INT NOT NULL) ENGINE=InnoDB');
            DB::table('rep_foreign_holder')->insert(['marker' => 9123]);
            DB::unprepared('CREATE INDEX `'.$last.'` ON rep_foreign_holder (marker)');
            $before = $this->indexCatalog();
            try {
                $this->migration()->down();
                $this->assertSame([9123], DB::table('rep_foreign_holder')->pluck('marker')->all());
                $this->migration()->up();
                $this->assertSame($before, $this->indexCatalog());
                $this->assertSame([9123], DB::table('rep_foreign_holder')->pluck('marker')->all());
            } finally {
                DB::unprepared('DROP TABLE rep_foreign_holder');
            }

            return;
        }
        foreach (['foreign_index', 'foreign_case_index', 'foreign_table', 'foreign_case_table', 'foreign_trigger', 'temp_index', 'temp_case_index', 'temp_table', 'temp_target_shadow'] as $kind) {
            foreach (['up', 'down'] as $direction) {
                // An early missing index would be created by up; an early installed one would be dropped by down.
                if ($direction === 'up') {
                    Schema::table('quotes', fn (Blueprint $table) => $table->dropIndex(self::INDEXES['quotes'][1]));
                }
                Schema::table('refund_line_allocations', fn (Blueprint $table) => $table->dropIndex($last));
                $temporary = str_starts_with($kind, 'temp_');
                $holder = $kind === 'temp_table' ? $last : ($kind === 'temp_target_shadow' ? 'quotes'
                    : (in_array($kind, ['foreign_table', 'foreign_case_table'], true) ? ($kind === 'foreign_case_table' ? strtoupper($last) : $last) : 'rep_foreign_holder'));
                $namespace = $temporary ? 'temp' : 'main';
                DB::unprepared('CREATE '.($temporary ? 'TEMP ' : '').'TABLE "'.$holder.'" (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
                DB::unprepared('INSERT INTO '.$namespace.'."'.$holder.'" (marker) VALUES (9123)');
                if (in_array($kind, ['foreign_index', 'foreign_case_index', 'temp_index', 'temp_case_index'], true)) {
                    $index = str_contains($kind, 'case') ? strtoupper($last) : $last;
                    DB::unprepared('CREATE INDEX '.$namespace.'."'.$index.'" ON "'.$holder.'" (marker)');
                } elseif ($kind === 'foreign_trigger') {
                    DB::unprepared('CREATE TRIGGER "'.$last.'" BEFORE INSERT ON "'.$holder.'" BEGIN SELECT 1; END');
                }
                try {
                    $before = [$this->retained(), $this->indexCatalog(), DB::table($namespace.'.'.$holder)->pluck('marker')->all()];
                    $this->assertReportingRefusal($direction, $kind);
                    $this->assertSame($before, [$this->retained(), $this->indexCatalog(), DB::table($namespace.'.'.$holder)->pluck('marker')->all()], $kind.' '.$direction);
                } finally {
                    DB::unprepared('DROP TABLE '.$namespace.'."'.$holder.'"');
                    $this->migration()->up();
                }
            }
        }
        $this->assertIndexes(true);
    }

    private function assertReportingRefusal(string $direction, string $case): void
    {
        $this->assertSame([], $this->ddlDuring(function () use ($direction, $case): void {
            try {
                $this->migration()->{$direction}();
                $this->fail($case.' was adopted or removed by '.$direction);
            } catch (LogicException $error) {
                $this->assertStringContainsString('Reporting index definition differs', $error->getMessage());
            }
        }), $case.' '.$direction.' must refuse before any DDL');
    }

    private function indexCatalog(): array
    {
        $rows = static fn (array $objects): array => array_map(static fn (object $row): array => (array) $row, $objects);
        if (DB::getDriverName() === 'sqlite') {
            return [$rows(DB::select('SELECT type, name, tbl_name, rootpage, sql FROM main.sqlite_master ORDER BY type, name')),
                $rows(DB::select('SELECT type, name, tbl_name, rootpage, sql FROM sqlite_temp_master ORDER BY type, name'))];
        }

        return $rows(DB::select('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE, SUB_PART, COLLATION, EXPRESSION, IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [DB::getDatabaseName()]));
    }
}
