<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CANDIDATES = 'production_track_capability_candidates';

    private const APPROVALS = 'production_track_capability_approvals';

    private const CLOSURES = 'production_track_capability_closures';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Production capability preparation requires a supported database.');
        }
        Schema::create(self::CANDIDATES, function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $this->identity($table, 'public_id', 36)->unique('ptc_candidate_public');
            $table->foreignId('production_track_policy_draft_id')->constrained(table: 'production_track_policy_drafts', indexName: 'ptc_candidate_source')->restrictOnDelete();
            $table->foreignId('production_track_policy_version_id')->constrained(table: 'production_track_policy_versions', indexName: 'ptc_candidate_version_parent')->restrictOnDelete();
            $table->foreignId('production_track_policy_source_review_id')->constrained(table: 'production_track_policy_source_reviews', indexName: 'ptc_candidate_review_parent')->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->unsignedInteger('schema_version');
            $this->identity($table, 'version_key', 64);
            $this->identity($table, 'source_graph_hash', 64);
            $table->longText('payload_ciphertext');
            $this->identity($table, 'payload_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['production_track_policy_draft_id', 'generation'], 'ptc_candidate_generation');
            $table->unique(['production_track_policy_draft_id', 'version_key'], 'ptc_candidate_version');
        });
        Schema::create(self::APPROVALS, function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('production_track_capability_candidate_id')->unique('ptc_approval_candidate_unique')->constrained(table: self::CANDIDATES, indexName: 'ptc_approval_candidate')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $this->identity($table, 'candidate_hash', 64);
            $table->longText('approval_ciphertext');
            $this->identity($table, 'approval_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->dateTime('created_at');
        });
        Schema::create(self::CLOSURES, function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('production_track_capability_candidate_id')->unique('ptc_closure_candidate_unique')->constrained(table: self::CANDIDATES, indexName: 'ptc_closure_candidate')->restrictOnDelete();
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $this->identity($table, 'candidate_hash', 64);
            $table->longText('closure_ciphertext');
            $this->identity($table, 'closure_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->dateTime('created_at');
        });

        $integer = DB::getDriverName() === 'sqlite' ? "TYPEOF(NEW.generation) = 'integer' AND TYPEOF(NEW.schema_version) = 'integer' AND " : '';
        $this->guard('ptc_candidate_insert', self::CANDIDATES, 'insert', $integer
            .'NEW.generation BETWEEN 1 AND 256 AND NEW.schema_version = 1 AND LENGTH(NEW.public_id) = 36'
            .' AND '.$this->hash('version_key').' AND '.$this->hash('source_graph_hash').' AND '.$this->hash('payload_hash')
            ." AND NEW.canonicalization_version = 'vasey-json-v1' AND LENGTH(NEW.payload_ciphertext) BETWEEN 1 AND 131072"
            .' AND NOT EXISTS (SELECT 1 FROM '.self::CANDIDATES.' c WHERE c.id = NEW.id OR c.public_id = NEW.public_id'
            .' OR (c.production_track_policy_draft_id = NEW.production_track_policy_draft_id AND (c.generation = NEW.generation OR c.version_key = NEW.version_key)))'
            .' AND NEW.generation = (SELECT COALESCE(MAX(c.generation), 0) + 1 FROM '.self::CANDIDATES.' c WHERE c.production_track_policy_draft_id = NEW.production_track_policy_draft_id)'
            .' AND EXISTS (SELECT 1 FROM production_track_policy_drafts d JOIN production_track_policy_versions v ON v.production_track_policy_draft_id = d.id'
            .' JOIN production_track_policy_source_reviews r ON r.production_track_policy_version_id = v.id'
            .' WHERE d.id = NEW.production_track_policy_draft_id AND v.id = NEW.production_track_policy_version_id AND d.revision = v.number'
            .' AND r.id = NEW.production_track_policy_source_review_id AND r.version_evidence_hash = v.payload_hash AND NEW.created_at >= r.created_at)');

        $this->guard('ptc_approval_insert', self::APPROVALS, 'insert', $this->hash('candidate_hash').' AND '.$this->hash('approval_hash')
            ." AND NEW.canonicalization_version = 'vasey-json-v1' AND LENGTH(NEW.approval_ciphertext) BETWEEN 1 AND 131072"
            .' AND NOT EXISTS (SELECT 1 FROM '.self::APPROVALS.' a WHERE a.id = NEW.id OR a.production_track_capability_candidate_id = NEW.production_track_capability_candidate_id)'
            .' AND EXISTS (SELECT 1 FROM '.self::CANDIDATES.' c JOIN production_track_policy_drafts d ON d.id = c.production_track_policy_draft_id'
            .' JOIN production_track_policy_versions v ON v.id = c.production_track_policy_version_id'
            .' WHERE c.id = NEW.production_track_capability_candidate_id AND c.payload_hash = NEW.candidate_hash AND d.revision = v.number'
            .' AND NEW.created_at >= c.created_at AND d.created_by <> NEW.reviewed_by'
            .' AND NOT EXISTS (SELECT 1 FROM production_track_policy_versions s WHERE s.production_track_policy_draft_id = d.id AND s.created_by = NEW.reviewed_by)'
            .' AND NOT EXISTS (SELECT 1 FROM '.self::CANDIDATES.' later WHERE later.production_track_policy_draft_id = d.id AND (later.generation > c.generation OR later.created_by = NEW.reviewed_by))'
            .' AND NOT EXISTS (SELECT 1 FROM '.self::CLOSURES.' x WHERE x.production_track_capability_candidate_id = c.id))');

        $this->guard('ptc_closure_insert', self::CLOSURES, 'insert', $this->hash('candidate_hash').' AND '.$this->hash('closure_hash')
            ." AND NEW.canonicalization_version = 'vasey-json-v1' AND LENGTH(NEW.closure_ciphertext) BETWEEN 1 AND 131072"
            .' AND NOT EXISTS (SELECT 1 FROM '.self::CLOSURES.' x WHERE x.id = NEW.id OR x.production_track_capability_candidate_id = NEW.production_track_capability_candidate_id)'
            .' AND EXISTS (SELECT 1 FROM '.self::CANDIDATES.' c WHERE c.id = NEW.production_track_capability_candidate_id AND c.payload_hash = NEW.candidate_hash AND NEW.created_at >= c.created_at)');
        foreach ([self::CANDIDATES => 'ptc_candidate', self::APPROVALS => 'ptc_approval', self::CLOSURES => 'ptc_closure'] as $table => $prefix) {
            $this->guard($prefix.'_update', $table, 'update');
            $this->guard($prefix.'_delete', $table, 'delete');
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

    private function hash(string $field): string
    {
        return DB::getDriverName() === 'sqlite' ? "LENGTH(NEW.{$field}) = 64 AND NEW.{$field} NOT GLOB '*[^a-f0-9]*'"
            : "LENGTH(NEW.{$field}) = 64 AND NEW.{$field} = LOWER(NEW.{$field}) AND NEW.{$field} NOT REGEXP '[^a-f0-9]'";
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable production capability preparation'); END");
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable production capability preparation';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        foreach ([self::CLOSURES, self::APPROVALS, self::CANDIDATES] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Retain populated production capability evidence.');
            }
        }
        foreach (['ptc_candidate_insert', 'ptc_approval_insert', 'ptc_closure_insert', 'ptc_candidate_update', 'ptc_candidate_delete',
            'ptc_approval_update', 'ptc_approval_delete', 'ptc_closure_update', 'ptc_closure_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists(self::CLOSURES);
        Schema::dropIfExists(self::APPROVALS);
        Schema::dropIfExists(self::CANDIDATES);
    }
};
