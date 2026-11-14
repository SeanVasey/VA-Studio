<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive declaration evidence: absent originals never become fabricated contract artifacts. */
return new class extends Migration
{
    private const TABLE = 'legacy_history_contract_declarations';

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true) || Schema::hasTable(self::TABLE)) {
            throw new LogicException('Retained declaration schema requires explicit recovery review.');
        }
        foreach (['legacy_history_batches', 'legacy_history_customers', 'legacy_history_orders', 'legacy_history_lines',
            'legacy_history_contracts', 'legacy_history_grant_reviews', 'legacy_exclusivity_authority_events'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException('The complete retained legacy history archive is required.');
            }
        }
        if (! Schema::hasColumns('legacy_history_contracts', ['id', 'batch_id', 'record_key', 'record_sha256', 'line_record_key',
            'source_contract_id', 'original_sha256', 'original_bytes', 'storage_path', 'record_ciphertext', 'actor_id'])) {
            throw new LogicException('The retained original archive shape is not admitted.');
        }
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('batch_id')->constrained('legacy_history_batches')->restrictOnDelete();
            $table->char('record_key', 64);
            $table->char('record_sha256', 64);
            $table->char('line_record_key', 64);
            $table->char('order_record_key', 64);
            $table->string('source_contract_id', 190);
            $table->foreignId('original_id')->nullable()->constrained('legacy_history_contracts')->restrictOnDelete();
            $table->char('supplied_original_record_sha256', 64)->nullable();
            $table->char('supplied_original_sha256', 64)->nullable();
            $table->unsignedBigInteger('supplied_original_bytes')->nullable();
            $table->longText('record_ciphertext');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['record_key', 'record_sha256'], 'legacy_decl_identity_version');
            $table->index(['line_record_key', 'record_key'], 'legacy_decl_line_identity');
            $table->index('order_record_key', 'legacy_decl_order');
            $table->index('original_id', 'legacy_decl_original');
        });
        $table = self::TABLE;
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared($driver === 'sqlite'
                ? "CREATE TRIGGER {$table}_immutable_{$operation} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT RAISE(ABORT, 'Legacy declarations are immutable'); END"
                : "CREATE TRIGGER {$table}_immutable_{$operation} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy declarations are immutable'");
        }
        // Refuse identity conflicts before REPLACE can remove a retained row, on both engines.
        $duplicate = "EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id OR (record_key = NEW.record_key AND record_sha256 = NEW.record_sha256))";
        $guards = [$duplicate];
        foreach (['record_key', 'record_sha256', 'line_record_key', 'order_record_key'] as $column) {
            $guards[] = $this->badHash($driver, $column);
        }
        foreach (['supplied_original_record_sha256', 'supplied_original_sha256'] as $column) {
            $guards[] = "(NEW.{$column} IS NOT NULL AND (".$this->badHash($driver, $column).'))';
        }
        $guards[] = $driver === 'sqlite'
            ? "(typeof(NEW.source_contract_id) <> 'text' OR length(NEW.source_contract_id) NOT BETWEEN 1 AND 190 OR substr(NEW.source_contract_id, 1, 1) NOT GLOB '[A-Za-z0-9]' OR NEW.source_contract_id GLOB '*[^A-Za-z0-9._:/#-]*')"
            : "(NEW.source_contract_id IS NULL OR NOT REGEXP_LIKE(NEW.source_contract_id, '^[A-Za-z0-9][A-Za-z0-9._:/#-]{0,189}$', 'c'))";
        $guards[] = '(NEW.record_ciphertext IS NULL OR LENGTH(NEW.record_ciphertext) = 0)';
        $allNull = 'NEW.original_id IS NULL AND NEW.supplied_original_record_sha256 IS NULL AND NEW.supplied_original_sha256 IS NULL AND NEW.supplied_original_bytes IS NULL';
        $allPresent = 'NEW.original_id IS NOT NULL AND NEW.supplied_original_record_sha256 IS NOT NULL AND NEW.supplied_original_sha256 IS NOT NULL AND NEW.supplied_original_bytes IS NOT NULL';
        $guards[] = "NOT (({$allNull}) OR ({$allPresent}))";
        $guards[] = $driver === 'sqlite'
            ? "(NEW.supplied_original_bytes IS NOT NULL AND (typeof(NEW.supplied_original_bytes) <> 'integer' OR NEW.supplied_original_bytes < 0))"
            : '(NEW.supplied_original_bytes IS NOT NULL AND NEW.supplied_original_bytes < 0)';
        $same = static fn (string $left, string $right): string => $driver === 'mysql'
            ? 'CAST('.$left.' AS BINARY) = CAST('.$right.' AS BINARY)' : $left.' = '.$right;
        $exactOriginal = implode(' AND ', [$same('o.record_key', 'NEW.record_key'),
            $same('o.record_sha256', 'NEW.supplied_original_record_sha256'), $same('o.original_sha256', 'NEW.supplied_original_sha256'),
            $same('o.line_record_key', 'NEW.line_record_key'), 'o.original_bytes = NEW.supplied_original_bytes']);
        $guards[] = '(NEW.original_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM legacy_history_contracts o WHERE o.id = NEW.original_id AND '.$exactOriginal.'))';
        $condition = implode(' OR ', array_map(static fn (string $guard): string => '('.$guard.')', $guards));
        DB::unprepared($driver === 'sqlite'
            ? "CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Legacy declaration shape or original link refused'); END"
            : "CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy declaration shape or original link refused'; END IF; END");
    }

    private function badHash(string $driver, string $column): string
    {
        return $driver === 'sqlite'
            ? "(NEW.{$column} IS NULL OR typeof(NEW.{$column}) <> 'text' OR length(NEW.{$column}) <> 64 OR NEW.{$column} GLOB '*[^a-f0-9]*')"
            : "(NEW.{$column} IS NULL OR NOT REGEXP_LIKE(NEW.{$column}, '^[a-f0-9]{64}$', 'c'))";
    }

    public function down(): void
    {
        throw new LogicException('Retain immutable contract declarations and original evidence.');
    }
};
