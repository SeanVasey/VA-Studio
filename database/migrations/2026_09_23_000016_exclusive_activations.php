<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exclusive_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_revision_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('rights_scope_id')->constrained()->restrictOnDelete();
            $table->foreignId('activated_by')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->timestamp('created_at');
        });
        foreach (['update', 'delete'] as $operation) {
            $name = 'exclusive_activations_immutable_'.$operation;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON exclusive_activations BEGIN SELECT RAISE(ABORT, 'Exclusive activation is immutable'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON exclusive_activations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Exclusive activation is immutable'");
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $operation) { DB::unprepared('DROP TRIGGER IF EXISTS exclusive_activations_immutable_'.$operation); }
        Schema::dropIfExists('exclusive_activations');
    }
};
