<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRIGGERS = [
        'license_review_insert_guard', 'license_review_content_guard', 'license_review_state_guard',
        'license_review_delete_guard', 'license_template_review_guard',
        'license_evidence_insert_guard', 'license_evidence_update_guard', 'license_evidence_delete_guard',
    ];

    public function up(): void
    {
        Schema::table('license_versions', function (Blueprint $table) {
            $table->foreignId('predecessor_id')->nullable()->constrained('license_versions')->restrictOnDelete();
            $table->json('content_author_ids')->nullable();
            $table->unsignedSmallInteger('terms_schema_version')->nullable();
            $table->string('canonicalization_version')->nullable();
            $table->json('submission_payload')->nullable();
            $table->string('submission_hash', 64)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
        });
        Schema::create('license_review_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_version_id')->unique()->constrained()->restrictOnDelete();
            $table->string('submission_hash', 64);
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('approval_reference');
            $table->boolean('summary_consistency_confirmed');
            $table->timestamp('reviewed_at');
            $table->string('evidence_hash', 64);
        });

        // Existing approvals remain untouched. They lack new evidence and require a reviewed successor.
        $proof = ['source_hash', 'model_hash', 'renderer_version', 'render_fixture_hash', 'approval_reference', 'approved_by', 'approved_at', 'published_at', 'terms_schema_version', 'canonicalization_version', 'submission_payload', 'submission_hash', 'submitted_by', 'submitted_at'];
        $hasProof = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT NULL", $proof));
        $this->guard('license_review_insert_guard', 'INSERT', 'license_versions', "NEW.status <> 'draft' OR {$hasProof}");
        $frozen = ['license_template_id', 'version', 'authored_source', 'structured_terms', 'author_id', 'content_author_ids', 'predecessor_id', 'effective_from', 'effective_until', 'terms_schema_version', 'canonicalization_version', 'submission_payload', 'submission_hash', 'submitted_by', 'submitted_at', 'source_hash', 'model_hash', 'renderer_version', 'render_fixture_hash', 'created_at'];
        $changed = implode(' OR ', array_map(fn ($field) => $this->different($field), $frozen));
        $approvalChanged = implode(' OR ', array_map(fn ($field) => $this->different($field), ['approved_by', 'approved_at', 'approval_reference']));
        $this->guard('license_review_content_guard', 'UPDATE', 'license_versions', "(OLD.status IN ('legal_review', 'approved', 'published') AND ({$changed})) OR (OLD.status IN ('approved', 'published') AND ({$approvalChanged}))");

        $reviewerIsSeparate = DB::getDriverName() === 'sqlite'
            ? 'NOT EXISTS (SELECT 1 FROM json_each(v.content_author_ids) a WHERE a.value = NEW.reviewer_id)'
            : "JSON_CONTAINS(v.content_author_ids, CAST(NEW.reviewer_id AS JSON), '$') = 0";
        $evidenceMatches = 'EXISTS (SELECT 1 FROM license_review_evidence e WHERE e.license_version_id = NEW.id AND e.submission_hash = NEW.submission_hash AND e.reviewer_id = NEW.approved_by AND e.reviewer_id <> NEW.author_id AND e.approval_reference = NEW.approval_reference AND e.reviewed_at = NEW.approved_at AND e.summary_consistency_confirmed = 1)';
        $submitted = "NEW.submission_hash IS NOT NULL AND length(NEW.submission_hash) = 64 AND NEW.submission_payload IS NOT NULL AND NEW.submitted_by IS NOT NULL AND NEW.submitted_at IS NOT NULL AND NEW.source_hash IS NOT NULL AND length(NEW.source_hash) = 64 AND NEW.model_hash IS NOT NULL AND length(NEW.model_hash) = 64 AND NEW.renderer_version = 'vasey-license-review-html-v1' AND NEW.render_fixture_hash IS NOT NULL AND length(NEW.render_fixture_hash) = 64 AND NEW.canonicalization_version = 'vasey-json-v1' AND NEW.terms_schema_version = 1";
        $valid = "(OLD.status = 'draft' AND NEW.status = 'draft' AND NOT ({$hasProof})) OR (OLD.status = 'draft' AND NEW.status = 'legal_review' AND {$submitted} AND NEW.approved_at IS NULL AND NEW.approved_by IS NULL AND NEW.approval_reference IS NULL AND NEW.published_at IS NULL) OR (OLD.status = 'legal_review' AND NEW.status = 'approved' AND {$submitted} AND {$evidenceMatches} AND NEW.published_at IS NULL) OR (OLD.status = 'approved' AND NEW.status = 'published' AND {$submitted} AND {$evidenceMatches} AND NEW.published_at IS NOT NULL)";
        $this->guard('license_review_state_guard', 'UPDATE', 'license_versions', "NOT COALESCE(({$valid}), 0)");
        $this->guard('license_review_delete_guard', 'DELETE', 'license_versions', '1 = 1');
        $this->guard('license_template_review_guard', 'UPDATE', 'license_templates', "EXISTS (SELECT 1 FROM license_versions v WHERE v.license_template_id = OLD.id AND (v.status <> 'draft' OR v.published_at IS NOT NULL))");
        $this->guard('license_evidence_insert_guard', 'INSERT', 'license_review_evidence', "NEW.summary_consistency_confirmed <> 1 OR length(trim(NEW.approval_reference)) = 0 OR length(NEW.evidence_hash) <> 64 OR NOT EXISTS (SELECT 1 FROM license_versions v WHERE v.id = NEW.license_version_id AND v.status = 'legal_review' AND v.submission_hash = NEW.submission_hash AND v.author_id <> NEW.reviewer_id AND v.content_author_ids IS NOT NULL AND {$reviewerIsSeparate})");
        $this->guard('license_evidence_update_guard', 'UPDATE', 'license_review_evidence', '1 = 1');
        $this->guard('license_evidence_delete_guard', 'DELETE', 'license_review_evidence', '1 = 1');
    }

    private function different(string $field): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "NOT (NEW.{$field} IS OLD.{$field})"
            : "NOT (BINARY NEW.{$field} <=> BINARY OLD.{$field})";
    }

    private function guard(string $name, string $operation, string $table, string $condition): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'License review evidence or lifecycle is immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'License review evidence or lifecycle is immutable'; END IF; END");
        } else {
            throw new RuntimeException('License evidence enforcement requires SQLite or MySQL.');
        }
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('license_review_evidence');
        Schema::table('license_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('predecessor_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['content_author_ids', 'terms_schema_version', 'canonicalization_version', 'submission_payload', 'submission_hash', 'submitted_at', 'effective_from', 'effective_until']);
        });
    }
};
