<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-53 follow-up (FP-007): which public cover art and demo audio a sound-kit product shows, and the staff approval of each.
 * Cover art is a ready `release_cover` site image (the albums pipeline); the demo is a `sound_kit_demos` row. Each approval
 * stores a hash over the exact asset it approved, which the domain recomputes at every public read, so choosing or uploading a
 * replacement voids it. Neither column can name the private kit archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sound_kit_products', 'cover_image_id') || Schema::hasColumn('sound_kit_products', 'demo_id')) {
            throw new LogicException('Unexpected sound-kit product media columns; migration refused.');
        }
        Schema::table('sound_kit_products', function (Blueprint $table): void {
            $table->foreignId('cover_image_id')->nullable()->constrained('site_images')->restrictOnDelete();
            $table->string('cover_alt', 200)->nullable();
            $table->char('cover_manifest_sha256', 64)->nullable();
            $table->char('cover_approval_hash', 64)->nullable();
            $table->dateTime('cover_approved_at')->nullable();
            $table->foreignId('cover_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('demo_id')->nullable()->constrained('sound_kit_demos')->restrictOnDelete();
            $table->char('demo_approval_hash', 64)->nullable();
            $table->dateTime('demo_approved_at')->nullable();
            $table->foreignId('demo_approved_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('sound_kit_products')->whereNotNull('cover_image_id')->orWhereNotNull('demo_id')->exists()) {
            throw new LogicException('Sound-kit product media choices and approvals are retained; populated migration rollback is refused.');
        }
        Schema::table('sound_kit_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cover_image_id');
            $table->dropConstrainedForeignId('cover_approved_by');
            $table->dropConstrainedForeignId('demo_id');
            $table->dropConstrainedForeignId('demo_approved_by');
            $table->dropColumn(['cover_alt', 'cover_manifest_sha256', 'cover_approval_hash', 'cover_approved_at', 'demo_approval_hash', 'demo_approved_at']);
        });
    }
};
