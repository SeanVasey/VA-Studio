<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_receipt_work', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stripe_webhook_receipt_id')->unique()->constrained()->restrictOnDelete();
            $this->identity($table, 'state', 32)->default('pending');
            $table->string('outcome', 64)->nullable();
            $this->identity($table, 'claim_token', 36)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['state', 'next_attempt_at', 'id'], 'stripe_receipt_work_due');
            $table->index(['state', 'lease_expires_at', 'id'], 'stripe_receipt_work_lease');
        });
        Schema::create('payment_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('stripe_webhook_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $this->identity($table, 'account_id', 80);
            $this->identity($table, 'mode', 8);
            $this->identity($table, 'provider_payment_intent_id', 128)->nullable();
            $this->identity($table, 'outcome', 32);
            $this->evidence($table);
            $table->dateTime('observed_at');
            $table->index(['checkout_intent_id', 'id'], 'payment_observation_intent');
        });
        Schema::create('verified_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('checkout_intent_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('checkout_session_id')->unique()->constrained()->restrictOnDelete();
            $this->identity($table, 'account_id', 80);
            $this->identity($table, 'mode', 8);
            $this->identity($table, 'provider_payment_intent_id', 128);
            $table->unique(['account_id', 'mode', 'provider_payment_intent_id'], 'verified_payment_scope_unique');
            $table->unsignedBigInteger('amount_minor');
            $this->identity($table, 'currency', 3);
            $this->evidence($table);
            $table->dateTime('confirmed_at');
        });

        $work = "NEW.state IN ('pending', 'processing', 'retry', 'processed', 'quarantined', 'unsupported')"
            ." AND ((NEW.state = 'processing' AND NEW.claim_token IS NOT NULL AND LENGTH(NEW.claim_token) = 36 AND NEW.lease_expires_at IS NOT NULL)"
            ." OR (NEW.state <> 'processing' AND NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL))"
            .' AND NEW.attempts >= 0 AND NEW.attempts <= 4294967295 AND (NEW.outcome IS NULL OR LENGTH(NEW.outcome) <= 64)';
        if (DB::getDriverName() === 'sqlite') {
            $work .= " AND TYPEOF(NEW.attempts) = 'integer'";
        }
        $this->guard('stripe_receipt_work_valid_insert', 'stripe_receipt_work', 'insert', $work);
        $this->guard('stripe_receipt_work_valid_update', 'stripe_receipt_work', 'update',
            $work.' AND '.$this->unchanged(['id', 'stripe_webhook_receipt_id', 'created_at']));
        $this->guard('stripe_receipt_work_retain', 'stripe_receipt_work', 'delete');

        $intentScope = "EXISTS (SELECT 1 FROM checkout_intents i WHERE i.id = NEW.checkout_intent_id AND i.account_id = NEW.account_id AND i.mode = NEW.mode)";
        $receiptScope = 'NEW.stripe_webhook_receipt_id IS NULL OR EXISTS (SELECT 1 FROM stripe_webhook_receipts r WHERE r.id = NEW.stripe_webhook_receipt_id AND r.account_id = NEW.account_id AND r.livemode = 0)';
        $this->guard('payment_observations_valid_insert', 'payment_observations', 'insert',
            "NEW.mode = 'test' AND NEW.outcome IN ('pending', 'authorized', 'canceled', 'expired', 'confirmed') AND {$intentScope} AND ({$receiptScope})");
        $bindings = 'EXISTS (SELECT 1 FROM checkout_intents i JOIN checkout_sessions s ON s.checkout_intent_id = i.id'
            .' JOIN order_attempts a ON a.id = i.order_attempt_id WHERE i.id = NEW.checkout_intent_id'
            .' AND i.order_id = NEW.order_id AND i.order_attempt_id = NEW.order_attempt_id AND a.order_id = NEW.order_id'
            .' AND s.id = NEW.checkout_session_id AND i.account_id = NEW.account_id AND s.account_id = NEW.account_id'
            .' AND i.mode = NEW.mode AND s.mode = NEW.mode)';
        $amount = 'NEW.amount_minor > 0';
        if (DB::getDriverName() === 'sqlite') {
            $amount .= " AND TYPEOF(NEW.amount_minor) = 'integer'";
        }
        $this->guard('verified_payments_valid_insert', 'verified_payments', 'insert',
            "NEW.mode = 'test' AND NEW.currency = 'USD' AND {$amount} AND {$bindings}");
        foreach (['payment_observations', 'verified_payments'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $this->guard($table.'_immutable_'.$operation, $table, $operation);
            }
        }
    }

    private function identity(Blueprint $table, string $name, int $length): \Illuminate\Database\Schema\ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function evidence(Blueprint $table): void
    {
        $table->longText('evidence_ciphertext');
        // Hash authenticated randomized ciphertext, never private evidence plaintext.
        $table->char('evidence_hash', 64);
        $table->string('canonicalization_version', 32);
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
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable payment evidence'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable payment evidence';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        // Empty disposable development rollback only. Operational rollback retains payment evidence.
        foreach (['stripe_receipt_work_valid_insert', 'stripe_receipt_work_valid_update', 'stripe_receipt_work_retain',
            'payment_observations_valid_insert', 'verified_payments_valid_insert',
            'payment_observations_immutable_update', 'payment_observations_immutable_delete',
            'verified_payments_immutable_update', 'verified_payments_immutable_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        foreach (['verified_payments', 'payment_observations', 'stripe_receipt_work'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
