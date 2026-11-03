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
        $installed = [];
        foreach (self::INDEXES as $table => [$column, $name]) {
            if (! Schema::hasTable($table)) {
                throw new LogicException('Reporting index prerequisite table is missing: '.$table);
            }
            $matches = array_values(array_filter(Schema::getIndexes($table), fn (array $index): bool => $index['name'] === $name));
            if ($matches !== [] && (count($matches) !== 1 || $matches[0]['columns'] !== [$column]
                || $matches[0]['unique'] !== false || $matches[0]['primary'] !== false)) {
                throw new LogicException('Reporting index definition differs; existing schema and data are unchanged: '.$name);
            }
            if ($matches !== [] && DB::getDriverName() === 'mysql') {
                $native = DB::select('SELECT INDEX_TYPE, SUB_PART, COLLATION, EXPRESSION, IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [DB::getDatabaseName(), $table, $name]);
                if (count($native) !== 1 || $native[0]->INDEX_TYPE !== 'BTREE' || $native[0]->SUB_PART !== null
                    || $native[0]->COLLATION !== 'A' || $native[0]->EXPRESSION !== null || $native[0]->IS_VISIBLE !== 'YES') {
                    throw new LogicException('Reporting index definition differs; existing schema and data are unchanged: '.$name);
                }
            }
            $installed[$table] = $matches !== [];
        }

        return $installed;
    }
};
