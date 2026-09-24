<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('owner_key', 64);
            $table->foreign('owner_key')->references('owner_key')->on('quote_owners')->restrictOnDelete();
            $table->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('quote_pricing_id')->unique()->constrained()->restrictOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->unique(['owner_key', 'idempotency_key_hash']);
            // Canonical private request, identity, assent and order evidence remain encrypted.
            $table->longText('payload_ciphertext');
            $table->char('payload_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->dateTime('created_at');
        });
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('quote_line_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('offer_revision_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->char('line_hash', 64);
            $table->unique(['order_id', 'position']);
            $table->unique(['order_id', 'offer_revision_id']);
        });
        Schema::create('order_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('promotion_use_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->json('binding');
            $table->char('binding_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->dateTime('created_at');
            $table->dateTime('expires_at');
        });
        foreach (['orders', 'order_lines', 'order_attempts'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $name = $table.'_immutable_'.$operation;
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Order evidence is immutable'); END");
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order evidence is immutable'");
                }
            }
        }
    }

    public function down(): void
    {
        // Disposable development rollback only; retain initiated orders operationally.
        foreach (['order_attempts', 'order_lines', 'orders'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$operation);
            }
            Schema::dropIfExists($table);
        }
    }
};
