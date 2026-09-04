<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_processing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_asset_id')->constrained('media_assets')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('profile_version');
            $table->char('profile_fingerprint', 64);
            $table->char('input_sha256', 64);
            $table->json('profile');
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->json('output_asset_ids')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->unique(['source_asset_id', 'profile_fingerprint'], 'media_runs_source_profile_unique');
        });
        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreignId('parent_asset_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->foreignId('processing_run_id')->nullable()->constrained('media_processing_runs')->restrictOnDelete();
            $table->unique(['processing_run_id', 'role'], 'media_outputs_run_role_unique');
        });
        foreach (['update', 'delete'] as $operation) {
            foreach (['media_assets' => "OLD.status IN ('ready', 'processed')", 'media_processing_runs' => "OLD.status = 'completed'"] as $table => $condition) {
                $name = $table.'_immutable_'.$operation;
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Verified media evidence is immutable'); END");
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verified media evidence is immutable'; END IF; END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['media_assets', 'media_processing_runs'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$operation);
            }
        }
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique('media_outputs_run_role_unique');
            $table->dropConstrainedForeignId('processing_run_id');
            $table->dropConstrainedForeignId('parent_asset_id');
        });
        Schema::dropIfExists('media_processing_runs');
    }
};
