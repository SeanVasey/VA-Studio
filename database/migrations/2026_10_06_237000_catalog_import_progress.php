<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['catalog_import_batches', 'catalog_import_mappings'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Catalog import evidence requires SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                // An incomplete or foreign installation is retained for explicit review, never reset.
                throw new LogicException('Retained catalog import schema needs explicit recovery review.');
            }
        }
        Schema::create('catalog_import_batches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->char('review_sha256', 64)->unique();
            $table->char('source_sha256', 64);
            $table->char('source_identity_sha256', 64);
            $table->char('target_commit', 40);
            $table->char('target_schema_sha256', 64);
            $table->string('transform_version', 80);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->longText('review_ciphertext');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('catalog_import_mappings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('batch_id')->constrained('catalog_import_batches')->restrictOnDelete();
            $table->char('record_key', 64)->unique();
            $table->char('source_record_sha256', 64);
            $table->foreignId('track_id')->unique()->constrained('tracks')->restrictOnDelete();
            $table->char('track_sha256', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->longText('evidence_ciphertext');
            $table->timestamp('created_at')->useCurrent();
        });
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $operation) {
                $name = $table.'_immutable_'.$operation;
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT RAISE(ABORT, 'Catalog import evidence is immutable'); END");
                } else {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catalog import evidence is immutable'");
                }
            }
            if (DB::getDriverName() === 'sqlite') {
                // SQLite REPLACE deletes do not fire DELETE triggers unless recursion is enabled.
                // Reject identity conflicts before insertion so evidence cannot be replaced in either mode.
                $unique = $table === 'catalog_import_batches' ? ['review_sha256'] : ['record_key', 'track_id'];
                $conditions = array_map(static fn (string $column): string => '"'.$column.'" = NEW."'.$column.'"', ['id', ...$unique]);
                DB::unprepared('CREATE TRIGGER '.$table.'_immutable_replace BEFORE INSERT ON '.$table.' WHEN EXISTS (SELECT 1 FROM '.$table.' WHERE '.implode(' OR ', $conditions).") BEGIN SELECT RAISE(ABORT, 'Catalog import evidence is immutable'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException('Retain catalog import evidence; a destructive rollback is unavailable.');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
