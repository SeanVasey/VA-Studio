<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Production policy preparation requires a supported database.');
        }
        Schema::create('production_track_policy_drafts', function (Blueprint $table): void {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->unsignedInteger('revision');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        Schema::create('production_track_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_track_policy_draft_id')->constrained(table: 'production_track_policy_drafts', indexName: 'production_policy_version_parent')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('schema_version');
            $table->longText('payload_ciphertext');
            $this->identity($table, 'payload_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['production_track_policy_draft_id', 'number'], 'production_policy_version_number');
        });
        Schema::create('production_track_policy_source_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_track_policy_version_id')->unique('production_policy_source_review_unique')->constrained(table: 'production_track_policy_versions', indexName: 'production_policy_source_review_parent')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $this->identity($table, 'version_evidence_hash', 64);
            $table->longText('review_ciphertext');
            $this->identity($table, 'review_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->dateTime('created_at');
        });

        $revision = DB::getDriverName() === 'sqlite' ? "TYPEOF(NEW.revision) = 'integer' AND " : '';
        $this->guard('production_policy_draft_insert', 'production_track_policy_drafts', 'insert',
            $revision.'NEW.revision = 0 AND LENGTH(NEW.public_id) = 36 AND NEW.created_at = NEW.updated_at');
        $same = implode(' AND ', array_map(fn (string $field): string => $this->same('NEW.'.$field, 'OLD.'.$field), ['id', 'public_id', 'created_by', 'created_at']));
        $this->guard('production_policy_draft_update', 'production_track_policy_drafts', 'update', $same.' AND '.$revision
            .'NEW.revision = OLD.revision + 1 AND NEW.revision <= 2147483647 AND NEW.updated_at >= OLD.updated_at'
            .' AND EXISTS (SELECT 1 FROM production_track_policy_versions v WHERE v.production_track_policy_draft_id = NEW.id AND v.number = NEW.revision)');
        $this->guard('production_policy_draft_retain', 'production_track_policy_drafts', 'delete');
        $number = DB::getDriverName() === 'sqlite' ? "TYPEOF(NEW.number) = 'integer' AND TYPEOF(NEW.schema_version) = 'integer' AND " : '';
        $this->guard('production_policy_version_insert', 'production_track_policy_versions', 'insert', $number
            .'NEW.number > 0 AND NEW.number <= 2147483647 AND NEW.schema_version = 1 AND '.$this->hash('payload_hash')
            ." AND NEW.canonicalization_version = 'vasey-json-v1' AND LENGTH(NEW.payload_ciphertext) BETWEEN 1 AND 131072"
            .' AND EXISTS (SELECT 1 FROM production_track_policy_drafts d WHERE d.id = NEW.production_track_policy_draft_id'
            .' AND d.revision = NEW.number - 1 AND NEW.created_at >= d.created_at)');
        $this->guard('production_policy_source_review_insert', 'production_track_policy_source_reviews', 'insert',
            $this->hash('review_hash').' AND '.$this->hash('version_evidence_hash')
            ." AND NEW.canonicalization_version = 'vasey-json-v1' AND LENGTH(NEW.review_ciphertext) BETWEEN 1 AND 131072"
            .' AND EXISTS (SELECT 1 FROM production_track_policy_versions v JOIN production_track_policy_drafts d ON d.id = v.production_track_policy_draft_id'
            .' WHERE v.id = NEW.production_track_policy_version_id AND d.revision = v.number AND v.payload_hash = NEW.version_evidence_hash'
            .' AND d.created_by <> NEW.reviewed_by AND NEW.created_at >= v.created_at'
            .' AND NOT EXISTS (SELECT 1 FROM production_track_policy_versions a WHERE a.production_track_policy_draft_id = d.id AND a.created_by = NEW.reviewed_by))');
        foreach (['production_track_policy_versions' => 'production_policy_version', 'production_track_policy_source_reviews' => 'production_policy_source_review'] as $table => $prefix) {
            $this->guard($prefix.'_immutable_update', $table, 'update');
            $this->guard($prefix.'_immutable_delete', $table, 'delete');
        }
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function same(string $left, string $right): string
    {
        return DB::getDriverName() === 'sqlite' ? "{$left} IS {$right}" : "{$left} <=> {$right}";
    }

    private function hash(string $field): string
    {
        return DB::getDriverName() === 'sqlite' ? "LENGTH(NEW.{$field}) = 64 AND NEW.{$field} NOT GLOB '*[^a-f0-9]*'"
            : "LENGTH(NEW.{$field}) = 64 AND NEW.{$field} = LOWER(NEW.{$field}) AND NEW.{$field} NOT REGEXP '[^a-f0-9]'";
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable production policy preparation'); END");
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable production policy preparation';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        foreach (['production_track_policy_source_reviews', 'production_track_policy_versions', 'production_track_policy_drafts'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Retain populated production policy preparation.');
            }
        }
        foreach (['production_policy_draft_insert', 'production_policy_draft_update', 'production_policy_draft_retain', 'production_policy_version_insert',
            'production_policy_source_review_insert', 'production_policy_version_immutable_update', 'production_policy_version_immutable_delete',
            'production_policy_source_review_immutable_update', 'production_policy_source_review_immutable_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists('production_track_policy_source_reviews');
        Schema::dropIfExists('production_track_policy_versions');
        Schema::dropIfExists('production_track_policy_drafts');
    }
};
