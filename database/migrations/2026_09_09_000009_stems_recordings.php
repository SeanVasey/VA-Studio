<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stems_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();
            $table->foreignId('stems_asset_id')->unique()->constrained('media_assets')->restrictOnDelete();
            $table->foreignId('master_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->foreignId('preview_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->foreignId('recording_source_id')->constrained('media_assets')->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->string('verification_reference', 240);
            $table->json('evidence');
            $table->char('evidence_hash', 64);
            $table->string('canonicalization_version', 32);
        });
        // No inferred backfill. Associations are explicit operator attestations.
        foreach (['update', 'delete'] as $operation) {
            $name = 'stems_recordings_immutable_'.$operation;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON stems_recordings BEGIN SELECT RAISE(ABORT, 'Stems recording evidence is immutable'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON stems_recordings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stems recording evidence is immutable'");
            }
        }
    }

    public function down(): void
    {
        // For disposable databases only; production code rollback must retain populated evidence.
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS stems_recordings_immutable_'.$operation);
        }
        Schema::dropIfExists('stems_recordings');
    }
};
