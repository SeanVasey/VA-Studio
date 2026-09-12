<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_pricings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
        });
        foreach (['update', 'delete'] as $operation) {
            $name = 'quote_pricings_immutable_'.$operation;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON quote_pricings BEGIN SELECT RAISE(ABORT, 'Pricing evidence is immutable'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON quote_pricings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing evidence is immutable'");
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS quote_pricings_immutable_'.$operation);
        }
        Schema::dropIfExists('quote_pricings');
    }
};
