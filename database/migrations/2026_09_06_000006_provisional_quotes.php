<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_owners', function (Blueprint $table) {
            // Stable first-write lock scope, including when no quote exists yet. Never a raw session identifier.
            $table->char('owner_key', 64)->primary();
        });
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('owner_key', 64);
            $table->foreign('owner_key')->references('owner_key')->on('quote_owners')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->unique(['owner_key', 'idempotency_key_hash']);
            $table->json('request');
            $table->char('request_hash', 64);
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->unsignedBigInteger('subtotal_minor');
            $table->char('currency', 3);
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
        });
        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_revision_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->char('line_hash', 64);
            $table->unique(['quote_id', 'position']);
            $table->unique(['quote_id', 'offer_revision_id']);
        });
        foreach (['quote_owners', 'quotes', 'quote_lines'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $name = $table.'_immutable_'.$operation;
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Quote evidence is immutable'); END");
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote evidence is immutable'");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['quote_lines', 'quotes', 'quote_owners'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$operation);
            }
            Schema::dropIfExists($table);
        }
    }
};
