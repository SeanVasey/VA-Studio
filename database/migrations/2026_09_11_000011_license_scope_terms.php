<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Install the successor before removing the prior guard: no unguarded write interval.
        $this->install('license_review_state_guard_v3', true);
        DB::unprepared('DROP TRIGGER IF EXISTS license_review_state_guard_v2');
    }

    public function down(): void
    {
        // Code rollback must retain v3-aware validation for any retained v3 evidence.
        $this->install('license_review_state_guard_v2', false);
        DB::unprepared('DROP TRIGGER IF EXISTS license_review_state_guard_v3');
    }

    private function install(string $name, bool $scoped): void
    {
        $proof = ['source_hash', 'model_hash', 'renderer_version', 'render_fixture_hash', 'approval_reference', 'approved_by', 'approved_at', 'published_at', 'terms_schema_version', 'canonicalization_version', 'submission_payload', 'submission_hash', 'submitted_by', 'submitted_at'];
        $hasProof = implode(' OR ', array_map(fn ($field) => "NEW.{$field} IS NOT NULL", $proof));
        $pair = "((NEW.terms_schema_version = 1 AND NEW.renderer_version = 'vasey-license-review-html-v1') OR (NEW.terms_schema_version = 2 AND NEW.renderer_version = 'vasey-license-review-html-v2'))";
        if ($scoped) {
            $pair = "({$pair} OR (NEW.terms_schema_version = 3 AND NEW.renderer_version = 'vasey-license-review-html-v3'))";
        }
        $submitted = "NEW.submission_hash IS NOT NULL AND length(NEW.submission_hash) = 64 AND NEW.submission_payload IS NOT NULL AND NEW.submitted_by IS NOT NULL AND NEW.submitted_at IS NOT NULL AND NEW.source_hash IS NOT NULL AND length(NEW.source_hash) = 64 AND NEW.model_hash IS NOT NULL AND length(NEW.model_hash) = 64 AND {$pair} AND NEW.render_fixture_hash IS NOT NULL AND length(NEW.render_fixture_hash) = 64 AND NEW.canonicalization_version = 'vasey-json-v1'";
        $evidenceMatches = 'EXISTS (SELECT 1 FROM license_review_evidence e WHERE e.license_version_id = NEW.id AND e.submission_hash = NEW.submission_hash AND e.reviewer_id = NEW.approved_by AND e.reviewer_id <> NEW.author_id AND e.approval_reference = NEW.approval_reference AND e.reviewed_at = NEW.approved_at AND e.summary_consistency_confirmed = 1)';
        $valid = "(OLD.status = 'draft' AND NEW.status = 'draft' AND NOT ({$hasProof})) OR (OLD.status = 'draft' AND NEW.status = 'legal_review' AND {$submitted} AND NEW.approved_at IS NULL AND NEW.approved_by IS NULL AND NEW.approval_reference IS NULL AND NEW.published_at IS NULL) OR (OLD.status = 'legal_review' AND NEW.status = 'approved' AND {$submitted} AND {$evidenceMatches} AND NEW.published_at IS NULL) OR (OLD.status = 'approved' AND NEW.status = 'published' AND {$submitted} AND {$evidenceMatches} AND NEW.published_at IS NOT NULL)";
        $condition = "NOT COALESCE(({$valid}), 0)";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE UPDATE ON license_versions WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'License review evidence or lifecycle is immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE UPDATE ON license_versions FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'License review evidence or lifecycle is immutable'; END IF; END");
        } else {
            throw new RuntimeException('License evidence enforcement requires SQLite or MySQL.');
        }
    }
};
