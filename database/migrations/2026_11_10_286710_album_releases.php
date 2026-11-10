<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-67: the albums page for Sean's own released music. Staff-entered catalog metadata only. A release is not a product: nothing
 * here references prices, offers, orders, grants or entitlements, and there is no purchase path. Cover art is a prepared site image
 * (D-25); `artwork_manifest_sha256` pins the exact bytes that were reviewed when the release was published. Rules (https and
 * allow-listed links, ready artwork with alt text, a non-empty tracklist) are enforced by App\Domain\Albums\AlbumReleases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Album releases require SQLite or MySQL.');
        }
        foreach (['album_releases', 'album_release_tracks'] as $table) {
            if (Schema::hasTable($table)) {
                throw new LogicException('Unexpected album release objects; migration refused.');
            }
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('album_releases', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->string('title', 160);
            $table->string('artist_name', 120);
            $table->string('slug', 80)->collation($binary)->unique();
            $table->string('release_type', 8)->collation($binary);
            $table->date('release_date');
            $table->text('description')->nullable();
            $table->foreignId('artwork_image_id')->nullable()->constrained('site_images')->restrictOnDelete();
            $table->string('artwork_alt', 200)->nullable();
            $table->char('artwork_manifest_sha256', 64)->nullable();
            $table->json('listening_links');
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['is_published', 'release_date']);
        });
        Schema::create('album_release_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_release_id')->constrained('album_releases')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 160);
            $table->unsignedInteger('duration_seconds');
            $table->timestamps();
            $table->unique(['album_release_id', 'position']);
        });
    }

    public function down(): void
    {
        if (DB::table('album_releases')->exists()) {
            throw new LogicException('Album releases are staff-entered records; populated migration rollback is refused.');
        }
        Schema::dropIfExists('album_release_tracks');
        Schema::dropIfExists('album_releases');
    }
};
