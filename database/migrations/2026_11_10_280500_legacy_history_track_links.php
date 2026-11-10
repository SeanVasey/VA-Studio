<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, append-only links from one archived legacy order line version to the store track its
 * `track_source_id` names, through the immutable catalog import mapping that created the track (BL-07, FP-099).
 *
 * A link is evidence about history. Nothing reads it to create an order, grant, entitlement or download, and it
 * alters no existing table. One link per line version; rows are immutable on both engines.
 */
return new class extends Migration
{
    public const TABLE = 'legacy_history_track_links';

    public const METHODS = ['exact_source_identity', 'staff_resolution'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true) || Schema::hasTable(self::TABLE)) {
            throw new LogicException('Retained legacy track link schema requires explicit recovery review.');
        }
        foreach (['legacy_history_lines', 'catalog_import_mappings', 'tracks'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new LogicException('The legacy history archive and the catalog import evidence are required.');
            }
        }
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            // One link per immutable line version: a changed line is a new row and is linked afresh.
            $table->foreignId('line_id')->unique('legacy_track_link_line')->constrained('legacy_history_lines', 'id', 'legacy_track_link_line_fk')->restrictOnDelete();
            $table->char('line_record_key', 64)->index('legacy_track_link_line_key');
            $table->char('line_record_sha256', 64);
            $table->foreignId('mapping_id')->constrained('catalog_import_mappings', 'id', 'legacy_track_link_mapping_fk')->restrictOnDelete();
            $table->foreignId('track_id')->constrained('tracks', 'id', 'legacy_track_link_track_fk')->restrictOnDelete();
            $table->string('method', 32);
            // Hash of the sorted candidate mapping ids the decision saw.
            $table->char('candidates_sha256', 64);
            // Keyed hash of the staff evidence reference; present exactly for a staff resolution.
            $table->char('reference_hmac', 64)->nullable();
            $table->foreignId('actor_id')->constrained('users', 'id', 'legacy_track_link_actor_fk')->restrictOnDelete();
            $table->unsignedBigInteger('attestation_audit_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        $table = self::TABLE;
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared($driver === 'sqlite'
                ? "CREATE TRIGGER {$table}_immutable_{$operation} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT RAISE(ABORT, 'Legacy track links are immutable'); END"
                : "CREATE TRIGGER {$table}_immutable_{$operation} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy track links are immutable'");
        }
        $mysql = $driver === 'mysql';
        $same = static fn (string $left, string $right): string => $mysql ? 'CAST('.$left.' AS BINARY) = CAST('.$right.' AS BINARY)' : $left.' = '.$right;
        $is = static fn (string $value): string => $mysql ? "CAST(NEW.method AS BINARY) = CAST('".$value."' AS BINARY)" : "NEW.method = '".$value."'";
        // Refuse identity conflicts before REPLACE can remove a retained row, on both engines.
        $guards = ["EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id OR line_id = NEW.line_id)"];
        $guards[] = $mysql ? 'NEW.method IS NULL OR NOT ('.$is(self::METHODS[0]).' OR '.$is(self::METHODS[1]).')'
            : "NEW.method IS NULL OR typeof(NEW.method) <> 'text' OR NEW.method NOT IN ('".implode("', '", self::METHODS)."')";
        foreach (['line_record_key', 'line_record_sha256', 'candidates_sha256'] as $column) {
            $guards[] = $this->badHash($driver, $column);
        }
        $guards[] = $is('staff_resolution').' AND ('.$this->badHash($driver, 'reference_hmac').')';
        $guards[] = $is('exact_source_identity').' AND NEW.reference_hmac IS NOT NULL';
        // The link names exactly the archived line version and exactly the track its catalog mapping created.
        $guards[] = 'NOT EXISTS (SELECT 1 FROM legacy_history_lines l WHERE l.id = NEW.line_id AND '.$same('l.record_key', 'NEW.line_record_key')
            .' AND '.$same('l.record_sha256', 'NEW.line_record_sha256').')';
        $guards[] = 'NOT EXISTS (SELECT 1 FROM catalog_import_mappings m WHERE m.id = NEW.mapping_id AND m.track_id = NEW.track_id)';
        $condition = implode(' OR ', array_map(static fn (string $guard): string => '('.$guard.')', $guards));
        DB::unprepared($mysql
            ? "CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy track link shape or evidence refused'; END IF; END"
            : "CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Legacy track link shape or evidence refused'); END");
    }

    private function badHash(string $driver, string $column): string
    {
        return $driver === 'sqlite'
            ? "NEW.{$column} IS NULL OR typeof(NEW.{$column}) <> 'text' OR length(NEW.{$column}) <> 64 OR NEW.{$column} GLOB '*[^a-f0-9]*'"
            : "NEW.{$column} IS NULL OR NOT REGEXP_LIKE(NEW.{$column}, '^[a-f0-9]{64}$', 'c')";
    }

    /** Forward-only: links are evidence and are never dropped by a rollback. */
    public function down(): void
    {
        throw new LogicException('Retain the immutable legacy track links.');
    }
};
