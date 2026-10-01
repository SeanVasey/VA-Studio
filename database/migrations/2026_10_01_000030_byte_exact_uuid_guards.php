<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Only IDs already checked by a 36-character/canonical UUID SQL predicate are included.
     * false retains the previous length-only policy; true also requires lowercase UUID syntax.
     * Nullable claim tokens remain nullable; their original lifecycle guards still bind state.
     */
    private const FIELDS = [
        'stripe_receipt_work' => ['claim_token' => false],
        'order_finalizations' => ['public_id' => false],
        'license_grants' => ['public_id' => false],
        'fulfillment_outbox' => ['public_id' => false],
        'contract_render_requests' => ['public_id' => true, 'document_public_id' => true],
        'contract_render_work' => ['claim_token' => true],
        'grant_contracts' => ['public_id' => true, 'claim_token' => true],
        'test_fulfillment_activations' => ['public_id' => true],
        'test_delivery_controls' => ['public_id' => true],
        'test_delivery_authorizations' => ['public_id' => true],
        'test_delivery_redemptions' => ['public_id' => true],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('UUID byte enforcement requires SQLite or MySQL.');
        }

        // Check every affected field before the first DDL statement. Retained evidence is never
        // rewritten or normalized, and a failed preflight leaves the existing guards in place.
        foreach (self::FIELDS as $table => $fields) {
            foreach ($fields as $column => $canonical) {
                if (DB::table($table)->whereRaw('NOT COALESCE(('.$this->field($table, $column, $canonical).'), 0)')->exists()) {
                    throw new LogicException("Invalid retained UUID bytes in {$table}.{$column}; evidence is unchanged. Investigate before retrying the migration.");
                }
            }
        }

        foreach (self::FIELDS as $table => $fields) {
            $allowed = implode(' AND ', array_map(fn (string $column): string =>
                '('.$this->field($table, $column, $fields[$column], 'NEW.').')', array_keys($fields)));
            foreach ($this->operations($table) as $operation) {
                $name = $this->name($table, $operation);
                $body = "BEGIN IF NOT COALESCE(({$allowed}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid UUID byte representation'; END IF; END";
                $statement = DB::getDriverName() === 'sqlite'
                    ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN NOT COALESCE(({$allowed}), 0) BEGIN SELECT RAISE(ABORT, 'Invalid UUID byte representation'); END"
                    : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
                // MySQL DDL commits individually. A retry after partial installation retains
                // verified guards and adds only missing ones, with no drop/recreate interval.
                if ($this->installed($name, $table, $operation, DB::getDriverName() === 'sqlite' ? $statement : $body)) { continue; }
                DB::unprepared($statement);
            }
        }
    }

    private function field(string $table, string $column, bool $canonical, string $prefix = ''): string
    {
        $value = $prefix.$column;
        $shape = $this->uuidBytes($value, $canonical);
        if ($column === 'claim_token' && in_array($table, ['stripe_receipt_work', 'contract_render_work'], true)) {
            // The original state guards require a claim while processing and NULL otherwise.
            return "{$value} IS NULL OR ({$shape})";
        }

        return $shape;
    }

    /** Count actual stored bytes; SQLite LENGTH/GLOB alone stop at an embedded NUL. */
    public function uuidBytes(string $value, bool $canonical): string
    {
        if (DB::getDriverName() === 'sqlite') {
            $shape = "TYPEOF({$value}) = 'text' AND LENGTH({$value}) = 36 AND LENGTH(CAST({$value} AS BLOB)) = 36";

            return $shape.($canonical
                ? " AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-'"
                    ." AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-'"
                    ." AND LENGTH(REPLACE({$value}, '-', '')) = 32 AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'"
                : '');
        }

        return "OCTET_LENGTH({$value}) = 36"
            .($canonical ? " AND REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')" : '');
    }

    private function operations(string $table): array
    {
        return match ($table) {
            'stripe_receipt_work', 'contract_render_work', 'test_delivery_controls' => ['INSERT', 'UPDATE'],
            default => ['INSERT'],
        };
    }

    private function name(string $table, string $operation): string
    {
        return 'uuid_bytes_'.$table.'_'.strtolower($operation);
    }

    private function installed(string $name, string $table, string $operation, string $definition): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $existing = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->first();
            if ($existing === null) { return false; }
            $matches = $existing->tbl_name === $table && $this->normalized($existing->sql) === $this->normalized($definition);
        } else {
            $existing = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->first();
            if ($existing === null) { return false; }
            $matches = $existing->EVENT_OBJECT_TABLE === $table && $existing->ACTION_TIMING === 'BEFORE'
                && $existing->EVENT_MANIPULATION === $operation && $this->normalized($existing->ACTION_STATEMENT) === $this->normalized($definition);
        }
        if (! $matches) {
            throw new LogicException("Unexpected UUID guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.");
        }

        return true;
    }

    private function normalized(string $statement): string
    {
        return preg_replace('/\s+/', ' ', trim($statement)) ?? throw new LogicException('UUID guard definition could not be read.');
    }

    public function down(): void
    {
        // Remove only this migration's supplemental guards. All original lifecycle, relational
        // and immutable-evidence guards and every retained row remain in place.
        foreach (array_keys(self::FIELDS) as $table) {
            foreach ($this->operations($table) as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$this->name($table, $operation));
            }
        }
    }
};
