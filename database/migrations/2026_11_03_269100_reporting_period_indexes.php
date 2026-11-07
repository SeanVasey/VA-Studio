<?php

use App\Domain\Commerce\ProductionCheckout\CheckoutSchemaInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Existing immutable evidence tables; only these non-unique indexes belong to this migration. */
    private const INDEXES = [
        'quotes' => ['created_at', 'quotes_reporting_created_at_index'],
        'orders' => ['created_at', 'orders_reporting_created_at_index'],
        'order_finalizations' => ['finalized_at', 'order_finalizations_reporting_finalized_at_index'],
        'test_unpaid_releases' => ['released_at', 'test_unpaid_releases_reporting_released_at_index'],
        'production_checkout_orders' => ['created_at', 'pco_orders_reporting_created_at_index'],
        'production_checkout_payments' => ['observed_at', 'pco_payments_reporting_observed_at_index'],
        'refund_line_allocations' => ['order_id', 'refund_line_allocations_reporting_order_id_index'],
    ];

    public function up(): void
    {
        $installed = $this->installed();
        (new CheckoutSchemaInstaller)->assertReportingUpgrade();
        foreach (self::INDEXES as $table => [$column, $name]) {
            if (! $installed[$table]) {
                if (str_starts_with($table, 'production_checkout_') && DB::getDriverName() === 'sqlite') {
                    // Exact canonical SQL is part of the frozen family's dictionary admission.
                    DB::unprepared('CREATE INDEX '.$name.' ON '.$table.' ('.$column.')');
                } else {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column, $name));
                }
            }
        }
        (new CheckoutSchemaInstaller)->up(); // Now the complete current graph must pass strict admission with no DDL.
    }

    public function down(): void
    {
        $installed = $this->installed();
        (new CheckoutSchemaInstaller)->assertReportingUpgrade();
        foreach (self::INDEXES as $table => [, $name]) {
            if ($installed[$table]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    /**
     * Inspect every owned name before issuing DDL. A partial MySQL install can resume,
     * but an index with a different definition is never accepted, replaced or removed.
     * No table, column, constraint, trigger or retained row is changed here.
     *
     * @return array<string, bool>
     */
    private function installed(): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new LogicException('Reporting indexes require SQLite or MySQL.');
        }
        $sqlite = $driver === 'sqlite' ? $this->sqliteCatalog() : null;
        $installed = [];
        foreach (self::INDEXES as $table => [$column, $name]) {
            if (! Schema::hasTable($table)) {
                throw new LogicException('Reporting index prerequisite table is missing: '.$table);
            }
            if ($sqlite !== null) {
                // Inspect native keys before any helper can flatten expression/collation metadata.
                if (isset($sqlite[$name])) {
                    $listed = array_values(array_filter(DB::select('PRAGMA main.index_list("'.$table.'")'),
                        fn (object $index): bool => $index->name === $name));
                    $keys = array_values(array_filter(DB::select('PRAGMA main.index_xinfo("'.$name.'")'),
                        fn (object $key): bool => (int) $key->key === 1));
                    if (count($listed) !== 1 || (int) $listed[0]->unique !== 0 || $listed[0]->origin !== 'c'
                        || (int) $listed[0]->partial !== 0 || count($keys) !== 1 || (int) $keys[0]->cid < 0
                        || $keys[0]->name !== $column || (int) $keys[0]->desc !== 0 || $keys[0]->coll !== 'BINARY') {
                        throw new LogicException('Reporting index definition differs; existing schema and data are unchanged: '.$name);
                    }
                }
                $installed[$table] = isset($sqlite[$name]);

                continue;
            }
            // MySQL names remain table-scoped, but aliases and expression keys must not disappear through a helper.
            $native = DB::select('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE, SUB_PART, COLLATION, EXPRESSION, IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
                .' AND CONVERT(INDEX_NAME USING utf8mb3) COLLATE utf8mb3_general_ci = CONVERT(? USING utf8mb3) COLLATE utf8mb3_general_ci ORDER BY SEQ_IN_INDEX', [DB::getDatabaseName(), $table, $name]);
            if ($native !== []) {
                if (count($native) !== 1 || $native[0]->INDEX_NAME !== $name || (int) $native[0]->NON_UNIQUE !== 1
                    || (int) $native[0]->SEQ_IN_INDEX !== 1 || $native[0]->COLUMN_NAME !== $column
                    || $native[0]->INDEX_TYPE !== 'BTREE' || $native[0]->SUB_PART !== null
                    || $native[0]->COLLATION !== 'A' || $native[0]->EXPRESSION !== null || $native[0]->IS_VISIBLE !== 'YES') {
                    throw new LogicException('Reporting index definition differs; existing schema and data are unchanged: '.$name);
                }
            }
            $installed[$table] = $native !== [];
        }

        return $installed;
    }

    /** SQLite names are database-global; prove every reserved name before either direction can issue DDL. */
    private function sqliteCatalog(): array
    {
        $owners = [];
        foreach (self::INDEXES as $table => [, $name]) {
            $owners[$name] = $table;
        }
        $names = array_keys($owners);
        $slots = implode(',', array_fill(0, count($names), '?'));
        // A temp name (or temp target-table shadow) must never redirect an unqualified schema operation.
        $temporary = DB::select('SELECT type, name, tbl_name FROM sqlite_temp_master WHERE name COLLATE NOCASE IN ('.$slots.')'
            .' OR (type = \'table\' AND name COLLATE NOCASE IN ('.$slots.'))', [...$names, ...array_keys(self::INDEXES)]);
        if ($temporary !== []) {
            throw new LogicException('Reporting index definition differs; temporary reserved namespace is not owned.');
        }
        $found = [];
        foreach (DB::select('SELECT type, name, tbl_name, sql FROM main.sqlite_master WHERE name COLLATE NOCASE IN ('.$slots.')', $names) as $object) {
            if (! isset($owners[$object->name]) || isset($found[$object->name]) || $object->type !== 'index'
                || $object->tbl_name !== $owners[$object->name] || ! is_string($object->sql)) {
                throw new LogicException('Reporting index definition differs; reserved namespace is not owned.');
            }
            $found[$object->name] = true;
        }

        return $found;
    }
};
