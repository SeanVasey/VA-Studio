<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sean's decision of 2026-11-11: whether a track's PUBLIC preview carries the seller tag is a per-track staff setting with a
 * store-wide default (default: tagged). Both previews are kept, so the setting only chooses which one the public hears.
 *
 * One small table holds both: the row `scope_key = 'default'` is the store default (`tagged` is never null there) and a row
 * `scope_key = 'track:<id>'` is one track's override (`tagged = null` follows the default). It deliberately lives beside
 * `tracks` instead of adding a column: a track write bumps publication versions and re-runs every publication guard, and this
 * setting must never do either, because it changes no license, deliverable, price or contract. A missing row means revision 0
 * and the built-in default (tagged). Rules, authority, expected-revision checks and audit are App\Domain\Media\PreviewTag\PreviewTagPolicy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Preview tag settings require SQLite or MySQL.');
        }
        if (Schema::hasTable('preview_tag_settings')) {
            throw new LogicException('Unexpected preview tag settings table; migration refused.');
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('preview_tag_settings', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->string('scope_key', 40)->collation($binary)->unique();
            $table->foreignId('track_id')->nullable()->unique()->constrained('tracks')->restrictOnDelete();
            $table->boolean('tagged')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('preview_tag_settings')->exists()) {
            throw new LogicException('Preview tag settings are staff decisions; populated migration rollback is refused.');
        }
        Schema::dropIfExists('preview_tag_settings');
    }
};
