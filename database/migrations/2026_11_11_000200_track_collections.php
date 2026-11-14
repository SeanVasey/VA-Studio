<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-54 (FP-006): public collections as browse groupings of individually licensed tracks. A collection is a staff-ordered list
 * of references to existing tracks. It has no price, offer, license or entitlement of its own and nothing here can create
 * one: every track in it is still sold, licensed and delivered through its own track page. Which member tracks are shown is
 * decided at read time from the track's own publication state (App\Domain\Collections\CollectionPresentation). This is
 * deliberately separate from `product_drafts`, which holds immutable private composition evidence that never authorizes
 * publication. Rules are enforced by App\Domain\Collections\TrackCollections.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Track collections require SQLite or MySQL.');
        }
        foreach (['track_collections', 'track_collection_items'] as $table) {
            if (Schema::hasTable($table)) {
                throw new LogicException('Unexpected track collection objects; migration refused.');
            }
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('track_collections', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->string('title', 160);
            $table->string('slug', 80)->collation($binary)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['is_published', 'id']);
        });
        Schema::create('track_collection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('track_collection_id')->constrained('track_collections')->restrictOnDelete();
            $table->foreignId('track_id')->constrained('tracks')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['track_collection_id', 'position']);
            $table->unique(['track_collection_id', 'track_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('track_collections')->exists()) {
            throw new LogicException('Track collections are staff-entered records; populated migration rollback is refused.');
        }
        Schema::dropIfExists('track_collection_items');
        Schema::dropIfExists('track_collections');
    }
};
