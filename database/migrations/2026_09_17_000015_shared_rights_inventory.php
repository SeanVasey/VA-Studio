<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rights_scopes', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique(); $table->string('scope_key', 96)->unique();
            $table->string('evidence_reference', 192); $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at'); $table->boolean('blocked')->default(false); $table->unsignedInteger('control_version')->default(0);
        });
        Schema::create('rights_scope_offers', function (Blueprint $table) {
            $table->id(); $table->foreignId('rights_scope_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_revision_id')->unique()->constrained()->restrictOnDelete();
            $table->string('evidence_reference', 192); $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique(); $table->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $table->json('snapshot'); $table->char('snapshot_hash', 64); $table->string('canonicalization_version', 32);
            $table->string('state', 16); $table->uuid('attempt_id')->nullable()->unique();
            $table->timestamp('created_at'); $table->timestamp('expires_at');
            $table->timestamp('pending_at')->nullable(); $table->timestamp('expired_at')->nullable();
        });
        Schema::create('inventory_claims', function (Blueprint $table) {
            $table->id(); $table->foreignId('rights_scope_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_reservation_id')->constrained()->restrictOnDelete();
            $table->unique(['rights_scope_id', 'inventory_reservation_id'], 'inventory_claim_identity');
        });
        foreach (['rights_scopes', 'rights_scope_offers', 'inventory_reservations', 'inventory_claims'] as $table) {
            $this->reject($table.'_retain', $table, 'delete');
        }
        foreach (['rights_scope_offers', 'inventory_claims'] as $table) { $this->reject($table.'_immutable', $table, 'update'); }
        $identity = $this->same(['id', 'public_id', 'scope_key', 'evidence_reference', 'created_by', 'created_at']);
        $this->reject('rights_scopes_control', 'rights_scopes', 'update',
            "{$identity} AND NEW.blocked IN (0, 1) AND NEW.blocked <> OLD.blocked AND NEW.control_version = OLD.control_version + 1");
        $this->reject('rights_scopes_initial', 'rights_scopes', 'insert', 'NEW.blocked = 0 AND NEW.control_version = 0');
        $this->reject('inventory_reservations_initial', 'inventory_reservations', 'insert',
            "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NULL AND NEW.expires_at > NEW.created_at");
        $identity = $this->same(['id', 'public_id', 'quote_id', 'snapshot', 'snapshot_hash', 'canonicalization_version', 'created_at', 'expires_at']);
        $pending = "NEW.state = 'pending' AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at AND NEW.expired_at IS NULL";
        $expired = "NEW.state = 'expired' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NOT NULL AND NEW.expired_at >= OLD.expires_at";
        $this->reject('inventory_reservations_transition', 'inventory_reservations', 'update',
            "{$identity} AND OLD.state = 'held' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND OLD.expired_at IS NULL AND (({$pending}) OR ({$expired}))");
    }

    private function same(array $fields): string
    {
        $op = DB::getDriverName() === 'sqlite' ? 'IS' : '<=>';

        return implode(' AND ', array_map(fn ($field) => "NEW.{$field} {$op} OLD.{$field}", $fields));
    }

    private function reject(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid inventory evidence transition'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid inventory evidence transition';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        foreach (['inventory_claims', 'inventory_reservations', 'rights_scope_offers', 'rights_scopes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
