<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_attempt_id')->unique()->constrained()->restrictOnDelete();
            $this->providerIdentity($table, ['account_id' => 80, 'mode' => 8, 'idempotency_key' => 128]);
            $table->unique(['account_id', 'mode', 'idempotency_key'], 'checkout_intent_scope_unique');
            $table->longText('request_ciphertext');
            // Hash authenticated randomized ciphertext, never the private request plaintext.
            $table->char('request_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->dateTime('created_at');
            $table->dateTime('initiate_before');
            $table->dateTime('retry_before');
            $table->dateTime('provider_expires_at');
        });
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_intent_id')->unique()->constrained()->restrictOnDelete();
            $this->providerIdentity($table, ['account_id' => 80, 'mode' => 8, 'provider_session_id' => 128]);
            $table->unique(['account_id', 'mode', 'provider_session_id'], 'checkout_session_scope_unique');
            $table->longText('evidence_ciphertext');
            $table->char('evidence_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->dateTime('created_at');
        });
        Schema::create('checkout_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_session_id')->constrained()->restrictOnDelete();
            $table->dateTime('observed_at');
            $table->string('status', 32);
            $table->longText('evidence_ciphertext');
            $table->char('evidence_hash', 64);
            $table->string('canonicalization_version', 32);
        });
        foreach (['checkout_intents', 'checkout_sessions', 'checkout_observations'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $name = $table.'_immutable_'.$operation;
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Checkout evidence is immutable'); END");
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Checkout evidence is immutable'");
                }
            }
        }
    }

    private function providerIdentity(Blueprint $table, array $columns): void
    {
        // Provider identity and idempotency keys are case-sensitive on both database engines.
        foreach ($columns as $name => $length) {
            $column = $table->string($name, $length);
            if (DB::getDriverName() === 'mysql') {
                $column->charset('ascii')->collation('ascii_bin');
            }
        }
    }

    public function down(): void
    {
        // Empty disposable development rollback only; retain initiated checkout evidence operationally.
        foreach (['checkout_observations', 'checkout_sessions', 'checkout_intents'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$operation);
            }
            Schema::dropIfExists($table);
        }
    }
};
