<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_images', function (Blueprint $table): void {
            $table->id(); $table->string('slot', 16); $table->string('original_name', 240);
            $table->string('source_path')->unique(); $table->char('source_sha256', 64); $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 32); $table->unsignedInteger('width'); $table->unsignedInteger('height');
            // Provenance is part of the upload and never changes.
            $table->string('credit', 200); $table->dateTime('rights_confirmed_at');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('quarantined'); $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable(); $table->dateTime('claimed_until')->nullable(); $table->string('failure_code', 40)->nullable();
            $table->string('profile_version', 32)->nullable(); $table->char('profile_fingerprint', 64)->nullable();
            $table->json('evidence')->nullable(); $table->char('manifest_sha256', 64)->nullable(); $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            $table->index(['slot', 'status']);
        });
        Schema::create('site_image_variants', function (Blueprint $table): void {
            $table->id(); $table->foreignId('site_image_id')->constrained('site_images')->restrictOnDelete();
            $table->string('format', 8); $table->unsignedInteger('width'); $table->unsignedInteger('height');
            $table->string('storage_path')->unique(); $table->char('sha256', 64)->index(); $table->unsignedBigInteger('size_bytes');
            $table->dateTime('created_at');
            $table->unique(['site_image_id', 'format', 'width']);
        });

        $slots = "'hero_desktop', 'hero_mobile', 'studio', 'share'";
        $this->guard('site_images', 'retain', 'delete');
        $this->guard('site_images', 'valid_insert', 'insert',
            "NEW.status = 'quarantined' AND NEW.attempts = 0 AND NEW.claim_token IS NULL AND NEW.claimed_until IS NULL"
            .' AND NEW.failure_code IS NULL AND NEW.profile_version IS NULL AND NEW.profile_fingerprint IS NULL AND NEW.evidence IS NULL'
            .' AND NEW.manifest_sha256 IS NULL AND NEW.processed_at IS NULL'
            ." AND NEW.slot IN ({$slots}) AND NEW.mime_type IN ('image/jpeg', 'image/png') AND LENGTH(NEW.source_sha256) = 64"
            ." AND SUBSTR(NEW.source_path, 1, 23) = 'site-images/quarantine/' AND NEW.size_bytes > 0 AND NEW.width > 0 AND NEW.height > 0"
            ." AND LENGTH(TRIM(NEW.credit)) > 0");
        $identity = 'NEW.id = OLD.id AND NEW.slot = OLD.slot AND NEW.original_name = OLD.original_name AND NEW.source_path = OLD.source_path'
            .' AND NEW.source_sha256 = OLD.source_sha256 AND NEW.size_bytes = OLD.size_bytes AND NEW.mime_type = OLD.mime_type'
            .' AND NEW.width = OLD.width AND NEW.height = OLD.height AND NEW.credit = OLD.credit AND NEW.rights_confirmed_at = OLD.rights_confirmed_at'
            .' AND NEW.uploaded_by = OLD.uploaded_by AND NEW.created_at = OLD.created_at';
        // A claim clears the last transient failure; an expired claim may be taken over (the domain checks the lease).
        $claim = "NEW.status = 'processing' AND NEW.claim_token IS NOT NULL AND NEW.claimed_until IS NOT NULL AND NEW.attempts = OLD.attempts + 1"
            .' AND NEW.failure_code IS NULL AND NEW.manifest_sha256 IS NULL AND NEW.processed_at IS NULL AND NEW.evidence IS NULL'
            ." AND (OLD.status = 'quarantined' OR (OLD.status = 'processing' AND NEW.claim_token <> OLD.claim_token))";
        $release = "OLD.status = 'processing' AND NEW.status = 'quarantined' AND NEW.claim_token IS NULL AND NEW.claimed_until IS NULL"
            .' AND NEW.failure_code IS NOT NULL AND NEW.attempts = OLD.attempts AND NEW.manifest_sha256 IS NULL AND NEW.processed_at IS NULL';
        $failed = "OLD.status = 'processing' AND NEW.status = 'failed' AND NEW.claim_token IS NULL AND NEW.claimed_until IS NULL"
            .' AND NEW.failure_code IS NOT NULL AND NEW.attempts = OLD.attempts AND NEW.manifest_sha256 IS NULL AND NEW.processed_at IS NOT NULL';
        // Ready only after the complete variant set for the slot exists; the manifest hash itself is recomputed by the domain.
        $ready = "OLD.status = 'processing' AND NEW.status = 'ready' AND NEW.claim_token IS NULL AND NEW.claimed_until IS NULL"
            .' AND NEW.failure_code IS NULL AND NEW.attempts = OLD.attempts AND LENGTH(NEW.manifest_sha256) = 64 AND NEW.processed_at IS NOT NULL'
            .' AND NEW.profile_version IS NOT NULL AND LENGTH(NEW.profile_fingerprint) = 64 AND NEW.evidence IS NOT NULL'
            ." AND (SELECT COUNT(*) FROM site_image_variants v WHERE v.site_image_id = NEW.id) = (CASE NEW.slot WHEN 'share' THEN 1 ELSE 6 END)";
        $this->guard('site_images', 'transition', 'update',
            $identity." AND OLD.status IN ('quarantined', 'processing') AND (({$claim}) OR ({$release}) OR ({$failed}) OR ({$ready}))");

        foreach (['retain' => 'delete', 'immutable' => 'update'] as $suffix => $operation) {
            $this->guard('site_image_variants', $suffix, $operation);
        }
        $this->guard('site_image_variants', 'valid_insert', 'insert',
            "NEW.format IN ('jpeg', 'webp') AND NEW.width > 0 AND NEW.height > 0 AND LENGTH(NEW.sha256) = 64 AND NEW.size_bytes > 0"
            // A prefix comparison rather than LIKE, which ignores ASCII case on SQLite.
            ." AND SUBSTR(NEW.storage_path, 1, 22) = 'site-images/revisions/'"
            ." AND EXISTS (SELECT 1 FROM site_images i WHERE i.id = NEW.site_image_id AND i.status = 'processing')");
    }

    private function guard(string $table, string $suffix, string $operation, ?string $valid = null): void
    {
        $name = $table.'_'.$suffix;
        if ($valid !== null && DB::getDriverName() === 'mysql') {
            $valid = $this->bytewise($valid);
        }
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Site image evidence is invalid or immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Site image evidence is invalid or immutable';";
            if ($valid !== null) { $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;"; }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    /**
     * Makes a MySQL guard condition compare its text columns byte for byte, as SQLite does. The default collation ignores case,
     * accents and trailing spaces, so 'Studio' would pass as a slot and a case-only change to a credit or path would pass as
     * unchanged. Only whole names are wrapped, so a column such as NEW.slot_x is never read as NEW.slot plus a suffix.
     */
    public function bytewise(string $condition): string
    {
        $text = ['NEW.status', 'OLD.status', 'i.status', 'NEW.slot', 'OLD.slot', 'NEW.original_name', 'OLD.original_name', 'NEW.source_path', 'OLD.source_path',
            'NEW.source_sha256', 'OLD.source_sha256', 'NEW.mime_type', 'OLD.mime_type', 'NEW.credit', 'OLD.credit', 'NEW.claim_token', 'OLD.claim_token',
            'NEW.format', 'NEW.storage_path'];
        $names = implode('|', array_map(fn (string $column): string => preg_quote($column, '/'), $text));

        return preg_replace('/(?<![\w$.])(?:'.$names.')(?![\w$])/', 'CAST($0 AS BINARY)', $condition)
            ?? throw new \LogicException('The guard condition could not be rewritten.');
    }

    public function down(): void
    {
        if (DB::table('site_images')->exists()) {
            throw new \LogicException('Site images and their provenance must be retained; populated migration rollback is refused.');
        }
        foreach (['site_image_variants_retain', 'site_image_variants_immutable', 'site_image_variants_valid_insert', 'site_images_retain', 'site_images_valid_insert', 'site_images_transition'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists('site_image_variants');
        Schema::dropIfExists('site_images');
    }
};
