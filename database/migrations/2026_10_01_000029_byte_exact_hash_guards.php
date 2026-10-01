<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Only fields that already have a 64-character/hexadecimal SQL guard are included.
     * false retains the existing length-only policy; true also retains lowercase hexadecimal.
     * Unconstrained hashes and the interpretation of retained license evidence are not changed.
     */
    private const FIELDS = [
        'license_versions' => ['submission_hash' => false, 'source_hash' => false, 'model_hash' => false, 'render_fixture_hash' => false],
        'license_review_evidence' => ['evidence_hash' => false],
        'order_finalizations' => ['evidence_hash' => false],
        'license_grants' => ['render_input_hash' => false],
        'pending_entitlements' => ['asset_hash' => false],
        'contract_render_requests' => ['input_hash' => true, 'profile_hash' => true],
        'grant_contracts' => ['input_hash' => true, 'profile_hash' => true, 'pdf_hash' => true],
        'test_fulfillment_activations' => ['evidence_hash' => true],
        'test_delivery_authorizations' => ['owner_key' => true, 'token_hash' => true, 'idempotency_key_hash' => true, 'request_hash' => true, 'evidence_hash' => true],
        'test_delivery_redemptions' => ['content_hash' => true, 'evidence_hash' => true],
        'site_images' => ['source_sha256' => false, 'manifest_sha256' => false, 'profile_fingerprint' => false],
        'site_image_variants' => ['sha256' => false],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Hash byte enforcement requires SQLite or MySQL.');
        }

        // Check every affected field before the first DDL statement. Retained evidence is never
        // rewritten or normalized, and a failed preflight leaves the existing guards in place.
        foreach (self::FIELDS as $table => $fields) {
            foreach ($fields as $column => $hexadecimal) {
                if (DB::table($table)->whereRaw('NOT COALESCE(('.$this->field($table, $column, $hexadecimal).'), 0)')->exists()) {
                    throw new LogicException("Invalid retained hash bytes in {$table}.{$column}; evidence is unchanged. Investigate before retrying the migration.");
                }
            }
        }

        foreach (self::FIELDS as $table => $fields) {
            $allowed = implode(' AND ', array_map(fn (string $column): string =>
                '('.$this->field($table, $column, $fields[$column], 'NEW.').')', array_keys($fields)));
            foreach ($this->operations($table) as $operation) {
                $name = $this->name($table, $operation);
                $body = "BEGIN IF NOT COALESCE(({$allowed}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid hash byte representation'; END IF; END";
                $statement = DB::getDriverName() === 'sqlite'
                    ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN NOT COALESCE(({$allowed}), 0) BEGIN SELECT RAISE(ABORT, 'Invalid hash byte representation'); END"
                    : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
                // MySQL DDL commits individually. A retry after partial installation retains
                // verified guards and adds only missing ones, with no drop/recreate interval.
                if ($this->installed($name, $table, $operation, DB::getDriverName() === 'sqlite' ? $statement : $body)) { continue; }
                DB::unprepared($statement);
            }
        }
    }

    private function field(string $table, string $column, bool $hexadecimal, string $prefix = ''): string
    {
        $value = $prefix.$column;
        $shape = $this->hashBytes($value, $hexadecimal);
        if ($table === 'license_versions') {
            // Draft proof remains nullable; the original lifecycle guard requires it at submission.
            return "{$value} IS NULL OR ({$shape})";
        }
        if ($table === 'site_images' && $column !== 'source_sha256') {
            // Processing/retry rows may have no manifest/profile yet. Their existing lifecycle
            // requires both hashes only when the image becomes ready.
            $status = DB::getDriverName() === 'mysql' ? "CAST({$prefix}status AS BINARY)" : $prefix.'status';

            return "{$status} <> 'ready' OR ({$shape})";
        }

        return $shape;
    }

    /** Match stored bytes, not SQLite's NUL-terminated text length or a regexp end anchor alone. */
    public function hashBytes(string $value, bool $hexadecimal): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "TYPEOF({$value}) = 'text' AND LENGTH({$value}) = 64 AND LENGTH(CAST({$value} AS BLOB)) = 64"
                .($hexadecimal ? " AND {$value} NOT GLOB '*[^0-9a-f]*'" : '');
        }

        return "OCTET_LENGTH({$value}) = 64"
            .($hexadecimal ? " AND REGEXP_LIKE({$value}, '^[0-9a-f]{64}$', 'c')" : '');
    }

    private function operations(string $table): array
    {
        return match ($table) {
            'license_versions' => ['UPDATE'],
            'site_images' => ['INSERT', 'UPDATE'],
            default => ['INSERT'],
        };
    }

    private function name(string $table, string $operation): string
    {
        return 'hash_bytes_'.$table.'_'.strtolower($operation);
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
            throw new LogicException("Unexpected hash guard definition for {$name}; the existing guard is unchanged. Investigate before retrying the migration.");
        }

        return true;
    }

    private function normalized(string $statement): string
    {
        return preg_replace('/\s+/', ' ', trim($statement)) ?? throw new LogicException('Hash guard definition could not be read.');
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
