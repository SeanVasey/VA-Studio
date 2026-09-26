<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REASONS = "'render_failed', 'storage_failed', 'evidence_changed', 'profile_changed', 'unsupported_input', 'invalid_pdf', 'retry_exhausted', 'original_unavailable'";

    public function up(): void
    {
        Schema::create('contract_render_requests', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('license_grant_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('fulfillment_outbox_id')->unique()->constrained('fulfillment_outbox')->restrictOnDelete();
            $this->identity($table, 'input_hash', 64);
            $table->json('profile');
            $this->identity($table, 'profile_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $this->identity($table, 'document_public_id', 36)->unique();
            $table->dateTime('created_at');
        });
        Schema::create('contract_render_work', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_render_request_id')->unique()->constrained()->restrictOnDelete();
            $this->identity($table, 'state', 16)->default('pending');
            $this->identity($table, 'claim_token', 36)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $this->identity($table, 'reason', 32)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['state', 'next_attempt_at', 'id'], 'contract_render_work_due');
            $table->index(['state', 'lease_expires_at', 'id'], 'contract_render_work_lease');
        });
        Schema::create('grant_contracts', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('license_grant_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('contract_render_request_id')->unique()->constrained()->restrictOnDelete();
            $this->identity($table, 'input_hash', 64);
            $this->identity($table, 'profile_hash', 64);
            $this->identity($table, 'disk', 16);
            $this->identity($table, 'storage_path', 192)->unique();
            $this->identity($table, 'claim_token', 36);
            $this->identity($table, 'pdf_hash', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedTinyInteger('page_count');
            $table->dateTime('issued_at');
        });

        $this->requestGuard();
        $this->workGuards();
        $this->resultGuard();
        foreach (['contract_render_requests', 'grant_contracts'] as $table) {
            foreach (['update', 'delete'] as $operation) { $this->guard($table.'_immutable_'.$operation, $table, $operation); }
        }
        $this->guard('contract_render_work_retain', 'contract_render_work', 'delete');
    }

    private function requestGuard(): void
    {
        $effectKey = DB::getDriverName() === 'sqlite' ? "('grant:' || l.position)" : "CONCAT('grant:', l.position)";
        $binding = 'EXISTS (SELECT 1 FROM license_grants g JOIN order_finalizations f ON f.id = g.order_finalization_id'
            .' JOIN order_lines l ON l.id = g.order_line_id JOIN fulfillment_outbox o ON o.license_grant_id = g.id'
            .' WHERE g.id = NEW.license_grant_id AND o.id = NEW.fulfillment_outbox_id'
            ." AND f.outcome = 'paid' AND f.mode = 'test' AND o.order_finalization_id = f.id AND o.state = 'pending'"
            ." AND o.kind = 'render_test_contract_v1' AND o.effect_key = {$effectKey}"
            .' AND NEW.input_hash = g.render_input_hash AND NEW.created_at >= g.created_at)';
        $profile = DB::getDriverName() === 'sqlite' ? "JSON_VALID(NEW.profile) = 1 AND JSON_TYPE(NEW.profile) = 'object'" : "JSON_TYPE(NEW.profile) = 'OBJECT'";
        $this->guard('contract_render_requests_valid_insert', 'contract_render_requests', 'insert',
            $binding.' AND '.$profile.' AND '.$this->uuid('NEW.public_id').' AND '.$this->uuid('NEW.document_public_id')
            .' AND '.$this->hash('NEW.input_hash').' AND '.$this->hash('NEW.profile_hash')
            .' AND LENGTH(NEW.canonicalization_version) BETWEEN 1 AND 32');
    }

    private function workGuards(): void
    {
        $shape = $this->workShape();
        $pending = "NEW.state = 'pending' AND NEW.attempts = 0 AND NEW.reason IS NULL AND NEW.next_attempt_at IS NULL";
        $binding = 'EXISTS (SELECT 1 FROM contract_render_requests r WHERE r.id = NEW.contract_render_request_id AND NEW.created_at >= r.created_at)';
        $this->guard('contract_render_work_valid_insert', 'contract_render_work', 'insert',
            "{$shape} AND {$pending} AND NEW.updated_at = NEW.created_at AND {$binding}");

        $identity = $this->unchanged(['id', 'contract_render_request_id', 'created_at']);
        $noResult = 'NOT EXISTS (SELECT 1 FROM grant_contracts c WHERE c.contract_render_request_id = NEW.contract_render_request_id)';
        $freshClaim = "NEW.state = 'processing' AND NEW.attempts = OLD.attempts + 1 AND OLD.attempts < 5"
            .' AND NEW.claim_token IS NOT NULL AND (OLD.claim_token IS NULL OR NEW.claim_token <> OLD.claim_token)'
            .' AND NEW.lease_expires_at = '.$this->plusSeconds('NEW.updated_at', 300)
            ." AND (OLD.state = 'pending' OR (OLD.state = 'retry' AND OLD.next_attempt_at <= NEW.updated_at)"
            ." OR (OLD.state = 'processing' AND OLD.lease_expires_at <= NEW.updated_at)) AND {$noResult}";
        $active = "OLD.state = 'processing' AND OLD.lease_expires_at > NEW.updated_at AND NEW.attempts = OLD.attempts";
        $retry = "{$active} AND NEW.state = 'retry' AND NEW.attempts < 5 AND NEW.reason IN ('render_failed', 'storage_failed')"
            .' AND NEW.next_attempt_at >= '.$this->plusSeconds('NEW.updated_at', 60)." AND {$noResult}";
        $quarantine = "{$active} AND NEW.state = 'quarantined' AND {$noResult}"
            ." AND (NEW.reason <> 'retry_exhausted' OR NEW.attempts = 5)";
        $exhausted = "OLD.attempts = 5 AND NEW.attempts = OLD.attempts AND NEW.state = 'quarantined' AND NEW.reason = 'retry_exhausted'"
            ." AND ((OLD.state = 'processing' AND OLD.lease_expires_at <= NEW.updated_at)"
            ." OR (OLD.state = 'retry' AND OLD.next_attempt_at <= NEW.updated_at)) AND {$noResult}";
        $completed = "{$active} AND NEW.state = 'completed' AND EXISTS (SELECT 1 FROM grant_contracts c"
            .' WHERE c.contract_render_request_id = NEW.contract_render_request_id AND c.claim_token = OLD.claim_token'
            .' AND c.issued_at >= OLD.updated_at AND c.issued_at <= NEW.updated_at AND c.issued_at < OLD.lease_expires_at)';
        $this->guard('contract_render_work_valid_update', 'contract_render_work', 'update',
            "{$identity} AND {$shape} AND NEW.updated_at >= OLD.updated_at AND (({$freshClaim}) OR ({$retry}) OR ({$quarantine}) OR ({$exhausted}) OR ({$completed}))");
    }

    private function workShape(): string
    {
        $shape = "NEW.state IN ('pending', 'processing', 'retry', 'completed', 'quarantined') AND NEW.attempts BETWEEN 0 AND 5"
            ." AND ((NEW.state = 'processing' AND NEW.attempts >= 1 AND ".$this->uuid('NEW.claim_token')
            .' AND NEW.lease_expires_at IS NOT NULL AND NEW.lease_expires_at > NEW.updated_at AND NEW.next_attempt_at IS NULL AND NEW.reason IS NULL)'
            ." OR (NEW.state <> 'processing' AND NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL))"
            ." AND ((NEW.state = 'retry' AND NEW.attempts >= 1 AND NEW.next_attempt_at IS NOT NULL AND NEW.reason IN ('render_failed', 'storage_failed'))"
            ." OR (NEW.state <> 'retry' AND NEW.next_attempt_at IS NULL))"
            ." AND ((NEW.state = 'quarantined' AND NEW.attempts >= 1 AND NEW.reason IN (".self::REASONS."))"
            ." OR (NEW.state <> 'quarantined' AND (NEW.state = 'retry' OR NEW.reason IS NULL))) AND NEW.updated_at >= NEW.created_at";
        if (DB::getDriverName() === 'sqlite') { $shape .= " AND TYPEOF(NEW.attempts) = 'integer'"; }

        return $shape;
    }

    private function resultGuard(): void
    {
        $path = DB::getDriverName() === 'sqlite' ? "('contracts/test/' || r.public_id || '/' || NEW.claim_token || '/original.pdf')"
            : "CONCAT('contracts/test/', r.public_id, '/', NEW.claim_token, '/original.pdf')";
        $binding = 'EXISTS (SELECT 1 FROM contract_render_requests r JOIN contract_render_work w ON w.contract_render_request_id = r.id'
            .' JOIN license_grants g ON g.id = r.license_grant_id JOIN order_finalizations f ON f.id = g.order_finalization_id'
            .' WHERE r.id = NEW.contract_render_request_id AND g.id = NEW.license_grant_id AND NEW.public_id = r.document_public_id'
            .' AND NEW.input_hash = r.input_hash AND NEW.input_hash = g.render_input_hash AND NEW.profile_hash = r.profile_hash'
            ." AND f.outcome = 'paid' AND f.mode = 'test' AND w.state = 'processing' AND w.claim_token = NEW.claim_token"
            .' AND NEW.issued_at >= w.updated_at AND NEW.issued_at < w.lease_expires_at AND NEW.storage_path = '.$path.')';
        $shape = "NEW.disk = 'local' AND NEW.size_bytes BETWEEN 1 AND 16777216 AND NEW.page_count BETWEEN 1 AND 100"
            .' AND '.$this->uuid('NEW.public_id').' AND '.$this->uuid('NEW.claim_token')
            .' AND '.$this->hash('NEW.pdf_hash').' AND '.$this->hash('NEW.input_hash').' AND '.$this->hash('NEW.profile_hash');
        if (DB::getDriverName() === 'sqlite') { $shape .= " AND TYPEOF(NEW.size_bytes) = 'integer' AND TYPEOF(NEW.page_count) = 'integer'"; }
        $this->guard('grant_contracts_valid_insert', 'grant_contracts', 'insert', $shape.' AND '.$binding);
    }

    private function uuid(string $value): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "LENGTH({$value}) = 36 AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-'"
                ." AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-' AND LENGTH(REPLACE({$value}, '-', '')) = 32"
                ." AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'";
        }

        return "REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
    }

    private function hash(string $value): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "LENGTH({$value}) = 64 AND {$value} NOT GLOB '*[^0-9a-f]*'"
            : "REGEXP_LIKE({$value}, '^[0-9a-f]{64}$', 'c')";
    }

    private function plusSeconds(string $value, int $seconds): string
    {
        return DB::getDriverName() === 'sqlite' ? "DATETIME({$value}, '+{$seconds} seconds')" : "DATE_ADD({$value}, INTERVAL {$seconds} SECOND)";
    }

    private function identity(Blueprint $table, string $name, int $length): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') { $column->charset('ascii')->collation('ascii_bin'); }

        return $column;
    }

    private function unchanged(array $fields): string
    {
        $operator = DB::getDriverName() === 'sqlite' ? 'IS' : '<=>';

        return implode(' AND ', array_map(fn ($field) => "NEW.{$field} {$operator} OLD.{$field}", $fields));
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable contract evidence'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable contract evidence';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        foreach (['grant_contracts', 'contract_render_work', 'contract_render_requests'] as $table) {
            if (DB::table($table)->exists()) { throw new \LogicException('Original contract evidence must be retained; populated rollback is refused.'); }
        }
        foreach (['contract_render_requests_valid_insert', 'contract_render_requests_immutable_update', 'contract_render_requests_immutable_delete',
            'contract_render_work_valid_insert', 'contract_render_work_valid_update', 'contract_render_work_retain',
            'grant_contracts_valid_insert', 'grant_contracts_immutable_update', 'grant_contracts_immutable_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        foreach (['grant_contracts', 'contract_render_work', 'contract_render_requests'] as $table) { Schema::dropIfExists($table); }
    }
};
