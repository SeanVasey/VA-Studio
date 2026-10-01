<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['test_delivery_redemptions', 'test_delivery_authorizations', 'test_delivery_controls'];

    public function up(): void
    {
        Schema::create('test_delivery_controls', function (Blueprint $table) {
            $table->id(); $this->identity($table, 'public_id', 36)->unique('delivery_control_public_unique');
            $this->foreign($table, 'order_id', 'orders', 'delivery_control_order_fk')->unique('delivery_control_order_unique');
            $this->foreign($table, 'test_fulfillment_activation_id', 'test_fulfillment_activations', 'delivery_control_activation_fk')->unique('delivery_control_activation_unique');
            $table->boolean('blocked')->default(true); $table->unsignedInteger('control_version')->default(0);
            $table->dateTime('created_at'); $table->dateTime('updated_at');
        });
        Schema::create('test_delivery_authorizations', function (Blueprint $table) {
            $table->id(); $this->identity($table, 'public_id', 36)->unique('delivery_authorization_public_unique');
            $this->foreign($table, 'order_id', 'orders', 'delivery_authorization_order_fk');
            $this->foreign($table, 'test_fulfillment_activation_id', 'test_fulfillment_activations', 'delivery_authorization_activation_fk');
            $this->foreign($table, 'test_delivery_control_id', 'test_delivery_controls', 'delivery_authorization_control_fk');
            $table->unsignedInteger('control_version');
            foreach (['owner_key', 'token_hash', 'idempotency_key_hash', 'request_hash'] as $name) { $this->identity($table, $name, 64); }
            $table->unique('token_hash', 'delivery_authorization_token_unique');
            $table->unique(['order_id', 'idempotency_key_hash'], 'delivery_authorization_request_unique');
            $table->index(['order_id', 'issued_at'], 'delivery_authorization_budget');
            $this->identity($table, 'kind', 32);
            $this->foreign($table, 'license_grant_id', 'license_grants', 'delivery_authorization_grant_fk');
            $this->foreign($table, 'grant_contract_id', 'grant_contracts', 'delivery_authorization_contract_fk')->nullable();
            $this->foreign($table, 'pending_entitlement_id', 'pending_entitlements', 'delivery_authorization_entitlement_fk')->nullable();
            $this->identity($table, 'policy_version', 64); $this->evidence($table);
            $table->dateTime('issued_at'); $table->dateTime('expires_at');
        });
        Schema::create('test_delivery_redemptions', function (Blueprint $table) {
            $table->id(); $this->identity($table, 'public_id', 36)->unique('delivery_redemption_public_unique');
            $this->foreign($table, 'test_delivery_authorization_id', 'test_delivery_authorizations', 'delivery_redemption_authorization_fk')->unique('delivery_redemption_authorization_unique');
            $table->unsignedInteger('control_version');
            $this->identity($table, 'content_hash', 64); $table->unsignedBigInteger('size_bytes');
            $this->evidence($table); $table->dateTime('redeemed_at');
        });

        $this->controlGuards(); $this->authorizationGuard(); $this->redemptionGuard();
        foreach (['test_delivery_authorizations', 'test_delivery_redemptions'] as $table) {
            foreach (['update', 'delete'] as $operation) { $this->guard($table, 'immutable_'.$operation, $operation); }
        }
        $this->guard('test_delivery_controls', 'retain', 'delete');
    }

    private function controlGuards(): void
    {
        $binding = 'EXISTS (SELECT 1 FROM test_fulfillment_activations a JOIN order_finalizations f ON f.id = a.order_finalization_id'
            .' WHERE a.id = NEW.test_fulfillment_activation_id AND a.order_id = NEW.order_id AND f.order_id = NEW.order_id'
            ." AND f.outcome = 'paid' AND f.mode = 'test' AND NEW.created_at >= a.activated_at)";
        // Starting blocked at zero and toggling once per version makes the final reachable state blocked.
        $shape = $this->uuid('NEW.public_id').' AND '.$this->timestamp('NEW.created_at').' AND '.$this->timestamp('NEW.updated_at')
            .' AND NEW.updated_at >= NEW.created_at AND NEW.blocked IN (0, 1) AND NEW.control_version BETWEEN 0 AND 4294967294';
        if (DB::getDriverName() === 'sqlite') { $shape .= " AND TYPEOF(NEW.blocked) = 'integer' AND TYPEOF(NEW.control_version) = 'integer'"; }
        $this->guard('test_delivery_controls', 'valid_insert', 'insert',
            "{$shape} AND {$binding} AND NEW.blocked = 1 AND NEW.control_version = 0 AND NEW.updated_at = NEW.created_at");
        $identity = $this->unchanged(['id', 'public_id', 'order_id', 'test_fulfillment_activation_id', 'created_at']);
        $this->guard('test_delivery_controls', 'valid_update', 'update',
            "{$shape} AND {$identity} AND NEW.blocked <> OLD.blocked AND NEW.control_version = OLD.control_version + 1 AND NEW.updated_at >= OLD.updated_at");
    }

    private function authorizationGuard(): void
    {
        $binding = 'EXISTS (SELECT 1 FROM test_delivery_controls d JOIN test_fulfillment_activations a ON a.id = d.test_fulfillment_activation_id'
            .' JOIN order_finalizations f ON f.id = a.order_finalization_id JOIN orders o ON o.id = a.order_id'
            .' JOIN license_grants g ON g.order_finalization_id = f.id JOIN order_lines l ON l.id = g.order_line_id'
            .' WHERE d.id = NEW.test_delivery_control_id AND d.order_id = NEW.order_id AND a.id = NEW.test_fulfillment_activation_id'
            .' AND o.id = NEW.order_id AND f.order_id = NEW.order_id AND l.order_id = NEW.order_id AND g.id = NEW.license_grant_id'
            ." AND f.mode = 'test' AND f.outcome = 'paid' AND d.blocked = 0 AND d.control_version = NEW.control_version"
            .' AND '.$this->equalBytes('o.owner_key', 'NEW.owner_key')
            .' AND NEW.issued_at >= a.activated_at AND NEW.issued_at >= d.updated_at AND '.$this->target('NEW').')';
        $shape = $this->uuid('NEW.public_id').' AND '.$this->evidenceShape().' AND '.$this->timestamp('NEW.issued_at')
            .' AND '.$this->timestamp('NEW.expires_at').' AND NEW.expires_at = '.$this->plusSeconds('NEW.issued_at', 60)
            ." AND ".$this->equalBytes('NEW.policy_version', "'test-owner-delivery-v1'").' AND NEW.control_version BETWEEN 1 AND 4294967293';
        foreach (['owner_key', 'token_hash', 'idempotency_key_hash', 'request_hash'] as $name) { $shape .= ' AND '.$this->hash('NEW.'.$name); }
        if (DB::getDriverName() === 'sqlite') { $shape .= " AND TYPEOF(NEW.control_version) = 'integer'"; }
        // The rolling budget and idempotency decision serialize under the domain's order/control mutex.
        $this->guard('test_delivery_authorizations', 'valid_insert', 'insert', "{$shape} AND {$binding}");
    }

    private function redemptionGuard(): void
    {
        $binding = 'EXISTS (SELECT 1 FROM test_delivery_authorizations x JOIN test_delivery_controls d ON d.id = x.test_delivery_control_id'
            .' JOIN test_fulfillment_activations a ON a.id = x.test_fulfillment_activation_id'
            .' JOIN order_finalizations f ON f.id = a.order_finalization_id JOIN orders o ON o.id = a.order_id'
            .' JOIN license_grants g ON g.id = x.license_grant_id JOIN order_lines l ON l.id = g.order_line_id'
            .' WHERE x.id = NEW.test_delivery_authorization_id AND x.order_id = o.id AND d.order_id = o.id'
            .' AND d.test_fulfillment_activation_id = a.id AND f.order_id = o.id AND l.order_id = o.id AND g.order_finalization_id = f.id'
            ." AND f.mode = 'test' AND f.outcome = 'paid' AND d.blocked = 0 AND d.control_version = x.control_version"
            .' AND NEW.control_version = x.control_version AND '.$this->equalBytes('x.owner_key', 'o.owner_key')
            .' AND NEW.redeemed_at >= x.issued_at AND NEW.redeemed_at >= d.updated_at AND NEW.redeemed_at < x.expires_at'
            .' AND '.$this->target('x', true).')';
        $shape = $this->uuid('NEW.public_id').' AND '.$this->evidenceShape().' AND '.$this->timestamp('NEW.redeemed_at')
            .' AND '.$this->hash('NEW.content_hash').' AND NEW.size_bytes BETWEEN 1 AND 1073741824 AND NEW.control_version BETWEEN 1 AND 4294967293';
        if (DB::getDriverName() === 'sqlite') { $shape .= " AND TYPEOF(NEW.size_bytes) = 'integer' AND TYPEOF(NEW.control_version) = 'integer'"; }
        $this->guard('test_delivery_redemptions', 'valid_insert', 'insert', "{$shape} AND {$binding}");
    }

    /** Exact target branch and parent bindings; cryptographic full-graph verification remains in the domain. */
    private function target(string $prefix, bool $bytes = false): string
    {
        $contractBytes = $bytes ? ' AND NEW.content_hash = c.pdf_hash AND NEW.size_bytes = c.size_bytes' : '';
        $assetBytes = $bytes ? ' AND NEW.content_hash = e.asset_hash AND NEW.size_bytes = e.size_bytes' : '';
        return "(({$prefix}.kind = 'contract' AND {$prefix}.grant_contract_id IS NOT NULL AND {$prefix}.pending_entitlement_id IS NULL"
            .' AND EXISTS (SELECT 1 FROM grant_contracts c JOIN contract_render_requests r ON r.id = c.contract_render_request_id'
            .' JOIN contract_render_work w ON w.contract_render_request_id = r.id'
            ." WHERE c.id = {$prefix}.grant_contract_id AND c.license_grant_id = g.id AND r.license_grant_id = g.id"
            ." AND w.state = 'completed' AND c.public_id = r.document_public_id AND c.input_hash = g.render_input_hash"
            .' AND c.input_hash = r.input_hash AND c.profile_hash = r.profile_hash AND c.issued_at <= a.verified_from'.$contractBytes.'))'
            ." OR ({$prefix}.kind IN ('master_wav', 'download_mp3', 'stems_zip') AND {$prefix}.grant_contract_id IS NULL AND {$prefix}.pending_entitlement_id IS NOT NULL"
            .' AND EXISTS (SELECT 1 FROM pending_entitlements e JOIN media_assets m ON m.id = e.media_asset_id'
            ." WHERE e.id = {$prefix}.pending_entitlement_id AND e.license_grant_id = g.id AND e.role = {$prefix}.kind AND e.state = 'pending'"
            ." AND m.role = e.role AND m.status = 'ready' AND m.disk = 'local' AND m.sha256 = e.asset_hash AND m.size_bytes = e.size_bytes".$assetBytes.')))';
    }

    private function identity(Blueprint $table, string $name, int $length): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') { $column->charset('ascii')->collation('ascii_bin'); }
        return $column;
    }

    private function foreign(Blueprint $table, string $name, string $parent, string $constraint): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = $table->foreignId($name);
        $table->foreign($name, $constraint)->references('id')->on($parent)->restrictOnDelete();
        return $column;
    }

    private function evidence(Blueprint $table): void
    {
        $table->longText('evidence_ciphertext'); $this->identity($table, 'evidence_hash', 64);
        $this->identity($table, 'canonicalization_version', 32);
    }

    private function evidenceShape(): string
    {
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(NEW.evidence_ciphertext AS BLOB))' : 'OCTET_LENGTH(NEW.evidence_ciphertext)';
        return "{$length} BETWEEN 1 AND 4194304 AND ".$this->hash('NEW.evidence_hash')
            .' AND '.$this->equalBytes('NEW.canonicalization_version', "'vasey-json-v1'");
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
        return DB::getDriverName() === 'sqlite' ? "LENGTH({$value}) = 64 AND {$value} NOT GLOB '*[^0-9a-f]*'"
            : "REGEXP_LIKE({$value}, '^[0-9a-f]{64}$', 'c')";
    }

    private function timestamp(string $value): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "TYPEOF({$value}) = 'text' AND LENGTH({$value}) = 19 AND SUBSTR({$value}, 1, 4) BETWEEN '1000' AND '9999' AND DATETIME({$value}, '+0 seconds') = {$value}"
            : "YEAR({$value}) BETWEEN 1000 AND 9999 AND MICROSECOND({$value}) = 0";
    }

    private function plusSeconds(string $value, int $seconds): string
    {
        return DB::getDriverName() === 'sqlite' ? "DATETIME({$value}, '+{$seconds} seconds')" : "DATE_ADD({$value}, INTERVAL {$seconds} SECOND)";
    }

    private function equalBytes(string $left, string $right): string
    {
        return DB::getDriverName() === 'sqlite' ? "{$left} = {$right}" : "CAST({$left} AS BINARY) = CAST({$right} AS BINARY)";
    }

    private function unchanged(array $fields): string
    {
        return implode(' AND ', array_map(fn ($field) => DB::getDriverName() === 'sqlite'
            ? "NEW.{$field} IS OLD.{$field}" : "CAST(NEW.{$field} AS BINARY) <=> CAST(OLD.{$field} AS BINARY)", $fields));
    }

    private function guard(string $table, string $suffix, string $operation, ?string $allowed = null): void
    {
        $name = $table.'_'.$suffix;
        if ($allowed !== null && DB::getDriverName() === 'mysql') {
            // Policy/kind spellings are exact even on MySQL's PAD SPACE identity collation.
            $allowed = $this->bytewise($allowed);
        }
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable test delivery evidence'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable test delivery evidence';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    /** Compare only whole whitelisted column names; longer or additionally qualified names stay unchanged. */
    public function bytewise(string $condition): string
    {
        $names = implode('|', array_map(fn (string $column): string => preg_quote($column, '/'), ['NEW.kind', 'x.kind']));

        return preg_replace('/(?<![\w$.])(?:'.$names.')(?![\w$])/', 'CAST($0 AS BINARY)', $condition)
            ?? throw new \LogicException('The guard condition could not be rewritten.');
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) { throw new \LogicException('Test delivery evidence must be retained; populated rollback is refused.'); }
        }
        foreach (self::TABLES as $table) {
            foreach (['valid_insert', 'valid_update', 'immutable_update', 'immutable_delete', 'retain'] as $suffix) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_'.$suffix);
            }
        }
        foreach (self::TABLES as $table) { Schema::dropIfExists($table); }
    }
};
