<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['exclusive_sales', 'fulfillment_outbox', 'pending_entitlements', 'license_grants', 'order_finalizations'];

    public function up(): void
    {
        Schema::create('order_finalizations', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('verified_payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_attempt_id')->unique()->constrained()->restrictOnDelete();
            $this->identity($table, 'mode', 8)->default('test');
            $this->identity($table, 'outcome', 32);
            $this->identity($table, 'reason', 32)->nullable();
            $this->identity($table, 'policy_version', 64);
            $table->dateTime('confirmed_at');
            $table->dateTime('eligibility_cutoff');
            $table->dateTime('finalized_at');
            $this->evidence($table, 'evidence');
        });
        Schema::create('license_grants', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('order_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_finalization_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('license_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('rights_scope_id')->constrained()->restrictOnDelete();
            $this->evidence($table, 'render_input');
            $table->dateTime('created_at');
        });
        Schema::create('pending_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_grant_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();
            $this->identity($table, 'role', 32);
            $this->identity($table, 'asset_hash', 64);
            $table->unsignedBigInteger('size_bytes');
            $this->identity($table, 'state', 16)->default('pending');
            $table->dateTime('created_at');
            $table->unique(['license_grant_id', 'media_asset_id', 'role'], 'pending_entitlement_identity');
        });
        Schema::create('fulfillment_outbox', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('order_finalization_id')->constrained()->restrictOnDelete();
            $table->foreignId('license_grant_id')->nullable()->constrained()->restrictOnDelete();
            $this->identity($table, 'effect_key', 96);
            $this->identity($table, 'kind', 32);
            $table->json('payload');
            $this->identity($table, 'state', 16)->default('pending');
            $table->dateTime('created_at');
            $table->unique(['order_finalization_id', 'effect_key'], 'fulfillment_outbox_effect_unique');
        });
        Schema::create('exclusive_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rights_scope_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('license_grant_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_finalization_id')->constrained()->restrictOnDelete();
            $table->dateTime('created_at');
        });
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dateTime('consumed_at')->nullable());
        }

        $this->guardFinalizationGraph();
        $this->resourceGuards(true);
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $operation) {
                $this->guard($table.'_immutable_'.$operation, $table, $operation);
            }
        }
    }

    private function guardFinalizationGraph(): void
    {
        $bindings = 'EXISTS (SELECT 1 FROM verified_payments p JOIN order_attempts a ON a.id = p.order_attempt_id'
            .' JOIN checkout_intents i ON i.id = p.checkout_intent_id WHERE p.id = NEW.verified_payment_id'
            .' AND p.order_id = NEW.order_id AND a.order_id = NEW.order_id AND a.id = NEW.order_attempt_id'
            ." AND p.mode = 'test' AND p.confirmed_at = NEW.confirmed_at AND a.expires_at = NEW.eligibility_cutoff"
            .' AND NEW.confirmed_at >= i.created_at'
            ." AND EXISTS (SELECT 1 FROM inventory_reservations r WHERE r.id = a.inventory_reservation_id AND r.state = 'pending'"
            .' AND r.attempt_id = a.public_id AND r.consumed_at IS NULL)'
            ." AND (a.promotion_use_id IS NULL OR EXISTS (SELECT 1 FROM promotion_uses u WHERE u.id = a.promotion_use_id AND u.state = 'pending'"
            .' AND u.attempt_id = a.public_id AND u.consumed_at IS NULL)))';
        $outcome = "(NEW.outcome = 'paid' AND NEW.reason IS NULL AND NEW.confirmed_at < NEW.eligibility_cutoff)"
            ." OR (NEW.outcome = 'paid_exception' AND NEW.reason IN ('late_confirmation', 'inventory_blocked', 'inventory_unavailable', 'asset_unavailable', 'rights_unavailable'))";
        $this->guard('order_finalizations_valid_insert', 'order_finalizations', 'insert',
            "NEW.mode = 'test' AND NEW.policy_version = 'test-order-finalization-v1' AND ({$outcome})"
            ." AND NEW.finalized_at >= NEW.confirmed_at AND {$bindings} AND ".$this->evidenceShape('evidence')
            .' AND LENGTH(NEW.public_id) = 36');
        $grant = 'EXISTS (SELECT 1 FROM order_finalizations f JOIN order_lines l ON l.order_id = f.order_id'
            .' JOIN offer_revisions r ON r.id = l.offer_revision_id JOIN rights_scope_offers s ON s.offer_revision_id = r.id'
            ." WHERE f.id = NEW.order_finalization_id AND f.outcome = 'paid' AND f.mode = 'test'"
            .' AND l.id = NEW.order_line_id AND r.id = NEW.offer_revision_id AND r.license_version_id = NEW.license_version_id'
            .' AND s.rights_scope_id = NEW.rights_scope_id AND NEW.created_at = f.finalized_at)';
        $this->guard('license_grants_valid_insert', 'license_grants', 'insert',
            $grant.' AND NOT EXISTS (SELECT 1 FROM exclusive_sales s WHERE s.rights_scope_id = NEW.rights_scope_id) AND '.$this->evidenceShape('render_input').' AND LENGTH(NEW.public_id) = 36');
        $manifestAsset = DB::getDriverName() === 'sqlite'
            ? "EXISTS (SELECT 1 FROM JSON_EACH(r.snapshot, '$.assets') x WHERE JSON_EXTRACT(x.value, '$.id') = NEW.media_asset_id AND JSON_EXTRACT(x.value, '$.role') = NEW.role AND JSON_EXTRACT(x.value, '$.sha256') = NEW.asset_hash AND JSON_EXTRACT(x.value, '$.size_bytes') = NEW.size_bytes)"
            : "JSON_CONTAINS(r.snapshot, JSON_OBJECT('id', NEW.media_asset_id, 'role', NEW.role, 'sha256', NEW.asset_hash, 'size_bytes', NEW.size_bytes), '$.assets') = 1";
        $asset = 'EXISTS (SELECT 1 FROM license_grants g JOIN order_finalizations f ON f.id = g.order_finalization_id'
            .' JOIN offer_revisions r ON r.id = g.offer_revision_id JOIN media_assets a ON a.track_id = r.track_id'
            ." WHERE g.id = NEW.license_grant_id AND f.outcome = 'paid' AND f.mode = 'test'"
            ." AND a.id = NEW.media_asset_id AND a.role = NEW.role AND a.status = 'ready'"
            .' AND a.sha256 = NEW.asset_hash AND a.size_bytes = NEW.size_bytes AND NEW.created_at = f.finalized_at AND '.$manifestAsset.')';
        $size = "NEW.size_bytes > 0 AND LENGTH(NEW.asset_hash) = 64";
        if (DB::getDriverName() === 'sqlite') { $size .= " AND TYPEOF(NEW.size_bytes) = 'integer'"; }
        $this->guard('pending_entitlements_valid_insert', 'pending_entitlements', 'insert',
            "NEW.state = 'pending' AND NEW.role IN ('download_mp3', 'master_wav', 'stems_zip') AND {$size} AND {$asset}");
        $payload = $this->outboxPayload();
        $exceptionKey = "NEW.effect_key = 'exception'";
        $grantKey = DB::getDriverName() === 'sqlite' ? "NEW.effect_key = ('grant:' || l.position)" : "NEW.effect_key = CONCAT('grant:', l.position)";
        $outbox = 'EXISTS (SELECT 1 FROM order_finalizations f WHERE f.id = NEW.order_finalization_id'
            ." AND f.mode = 'test' AND NEW.created_at = f.finalized_at AND ".$this->jsonScalar('finalization_id')." = f.public_id"
            ." AND ((f.outcome = 'paid_exception' AND NEW.kind = 'order_paid_exception_v1' AND {$exceptionKey}"
            .' AND NEW.license_grant_id IS NULL AND '.$this->jsonType('grant_id')." = 'null'"
            .' AND '.$this->jsonScalar('evidence_hash')." = f.evidence_hash) OR (f.outcome = 'paid'"
            ." AND NEW.kind = 'render_test_contract_v1' AND EXISTS (SELECT 1 FROM license_grants g JOIN order_lines l ON l.id = g.order_line_id"
            .' WHERE g.id = NEW.license_grant_id AND g.order_finalization_id = f.id AND '.$grantKey
            .' AND '.$this->jsonScalar('grant_id').' = g.public_id AND '.$this->jsonScalar('evidence_hash').' = g.render_input_hash))))';
        $this->guard('fulfillment_outbox_valid_insert', 'fulfillment_outbox', 'insert',
            "NEW.state = 'pending' AND LENGTH(NEW.public_id) = 36 AND LENGTH(NEW.effect_key) BETWEEN 1 AND 96 AND {$payload} AND {$outbox}");
        $exclusiveType = DB::getDriverName() === 'sqlite' ? "JSON_EXTRACT(r.snapshot, '$.commercial.type')" : "JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.commercial.type'))";
        $sale = 'EXISTS (SELECT 1 FROM license_grants g JOIN order_finalizations f ON f.id = g.order_finalization_id'
            .' JOIN offer_revisions r ON r.id = g.offer_revision_id'
            ." WHERE g.id = NEW.license_grant_id AND f.outcome = 'paid' AND f.mode = 'test'"
            .' AND g.rights_scope_id = NEW.rights_scope_id AND g.order_line_id = NEW.order_line_id'
            .' AND f.id = NEW.order_finalization_id AND NEW.created_at = f.finalized_at AND '.$exclusiveType." = 'exclusive')";
        $this->guard('exclusive_sales_valid_insert', 'exclusive_sales', 'insert', $sale);
    }

    /** Queue messages contain only the agreed opaque identifiers and randomized ciphertext hash. */
    private function outboxPayload(): string
    {
        $shape = DB::getDriverName() === 'sqlite'
            ? "JSON_VALID(NEW.payload) = 1 AND JSON_TYPE(NEW.payload) = 'object' AND (SELECT COUNT(*) FROM JSON_EACH(NEW.payload)) = 4"
            : "JSON_TYPE(NEW.payload) = 'OBJECT' AND JSON_LENGTH(NEW.payload) = 4";

        return $shape.' AND '.$this->jsonType('schema_version')." = 'integer' AND ".$this->jsonScalar('schema_version')." = 1"
            .' AND '.$this->jsonType('finalization_id')." = 'text' AND ".$this->jsonType('evidence_hash')." = 'text'"
            .' AND '.$this->jsonType('grant_id')." IN ('text', 'null')";
    }

    private function jsonScalar(string $key): string
    {
        $expression = "JSON_EXTRACT(NEW.payload, '$.{$key}')";

        return DB::getDriverName() === 'sqlite' ? $expression : "JSON_UNQUOTE({$expression})";
    }

    private function jsonType(string $key): string
    {
        if (DB::getDriverName() === 'sqlite') { return "JSON_TYPE(NEW.payload, '$.{$key}')"; }

        // Match SQLite's scalar type names while preserving JSON null versus absent keys.
        return "CASE JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.{$key}')) WHEN 'STRING' THEN 'text' WHEN 'INTEGER' THEN 'integer' WHEN 'NULL' THEN 'null' ELSE 'invalid' END";
    }

    /** Preserve old pending/expiry transitions and add only paid-linked, immutable consumption. */
    private function resourceGuards(bool $finalization): void
    {
        foreach (['inventory_reservations_initial', 'inventory_reservations_transition', 'promotion_uses_guard_insert', 'promotion_uses_guard_update'] as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        $emptyConsumption = $finalization ? ' AND NEW.consumed_at IS NULL' : '';
        $oldEmptyConsumption = $finalization ? ' AND OLD.consumed_at IS NULL' : '';
        $this->guard('inventory_reservations_initial', 'inventory_reservations', 'insert',
            "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NULL AND NEW.expires_at > NEW.created_at".$emptyConsumption);
        $identity = $this->unchanged(['id', 'public_id', 'quote_id', 'snapshot', 'snapshot_hash', 'canonicalization_version', 'created_at', 'expires_at']);
        $pending = "NEW.state = 'pending' AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at AND NEW.expired_at IS NULL";
        $expired = "NEW.state = 'expired' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NOT NULL AND NEW.expired_at >= OLD.expires_at";
        $old = "OLD.state = 'held' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND OLD.expired_at IS NULL AND (({$pending}) OR ({$expired}))".$emptyConsumption.$oldEmptyConsumption;
        $consumed = $finalization ? ' OR ('.$this->consume('inventory_reservation_id').' AND '.$this->unchanged(['attempt_id', 'pending_at', 'expired_at']).' AND OLD.expired_at IS NULL)' : '';
        $this->guard('inventory_reservations_transition', 'inventory_reservations', 'update', "{$identity} AND (({$old}){$consumed})");

        $this->guard('promotion_uses_guard_insert', 'promotion_uses', 'insert',
            "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expires_at > NEW.created_at".$emptyConsumption);
        $identity = $this->unchanged(['id', 'promotion_campaign_id', 'quote_pricing_id', 'created_at', 'expires_at']);
        $old = "OLD.state = 'held' AND NEW.state = 'pending' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at".$emptyConsumption.$oldEmptyConsumption;
        $consumed = $finalization ? ' OR ('.$this->consume('promotion_use_id').' AND '.$this->unchanged(['attempt_id', 'pending_at']).')' : '';
        $this->guard('promotion_uses_guard_update', 'promotion_uses', 'update', "{$identity} AND (({$old}){$consumed})");
    }

    private function consume(string $resource): string
    {
        return "OLD.state = 'pending' AND NEW.state = 'consumed' AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NOT NULL"
            .' AND OLD.attempt_id IS NOT NULL AND OLD.pending_at IS NOT NULL AND NEW.consumed_at >= OLD.pending_at'
            .' AND EXISTS (SELECT 1 FROM order_finalizations f JOIN order_attempts a ON a.id = f.order_attempt_id'
            ." WHERE f.outcome = 'paid' AND f.mode = 'test' AND a.{$resource} = NEW.id"
            .' AND a.public_id = NEW.attempt_id AND f.order_id = a.order_id AND f.finalized_at = NEW.consumed_at)';
    }

    private function identity(Blueprint $table, string $name, int $length): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') { $column->charset('ascii')->collation('ascii_bin'); }

        return $column;
    }

    private function evidence(Blueprint $table, string $prefix): void
    {
        $table->longText($prefix.'_ciphertext');
        $this->identity($table, $prefix.'_hash', 64);
        $this->identity($table, 'canonicalization_version', 32);
    }

    private function evidenceShape(string $prefix): string
    {
        return "LENGTH(NEW.{$prefix}_ciphertext) > 0 AND LENGTH(NEW.{$prefix}_hash) = 64 AND LENGTH(NEW.canonicalization_version) BETWEEN 1 AND 32";
    }

    private function unchanged(array $fields): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return implode(' AND ', array_map(fn ($field) => "NEW.{$field} IS OLD.{$field}", $fields));
        }

        // Existing resource columns predate ASCII identity columns. Preserve their bytes, not collation equivalence.
        return implode(' AND ', array_map(fn ($field) => "CAST(NEW.{$field} AS BINARY) <=> CAST(OLD.{$field} AS BINARY)", $fields));
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if ($allowed !== null && DB::getDriverName() === 'mysql' && in_array($table, ['inventory_reservations', 'promotion_uses'], true)) {
            // Historical state columns use the database's default collation; state spelling is still exact.
            $allowed = str_replace(['NEW.state', 'OLD.state'], ['CAST(NEW.state AS BINARY)', 'CAST(OLD.state AS BINARY)'], $allowed);
        }
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable finalization evidence'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable finalization evidence';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        // Operational rollback must retain initiated rights. This path is only for empty disposable databases.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) { throw new \LogicException('Finalization evidence must be retained; populated rollback is refused.'); }
        }
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            if (DB::table($table)->whereNotNull('consumed_at')->orWhere('state', 'consumed')->exists()) {
                throw new \LogicException('Consumed resource evidence must be retained; populated rollback is refused.');
            }
        }
        $this->resourceGuards(false);
        // Remove all cross-table trigger references before SQLite validates a dropped column/table.
        foreach (self::TABLES as $table) {
            foreach (['valid_insert', 'immutable_update', 'immutable_delete'] as $suffix) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_'.$suffix);
            }
        }
        foreach (self::TABLES as $table) { Schema::dropIfExists($table); }
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('consumed_at'));
        }
    }
};
