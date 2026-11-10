<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-53 (FP-007): the staff-managed sound-kit product, the catalog record a public kit page is built from. One product binds
 * one private kit (`sound_kit_drafts`) to the exact verified archive revision staff chose, a published sound-kit license
 * version, a staff-entered integer price, an explicit rights clearance and an explicit offer approval. It holds no customer,
 * payment, order or entitlement data and nothing here can create one: purchase and delivery are not built (docs/sound-kit-storefront.md).
 * Rules are enforced by App\Domain\SoundKits\Products\SoundKitProducts; the public projection is
 * App\Domain\SoundKits\Products\SoundKitPresentation, behind the literal-false `sound-kits.storefront_enabled` flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Sound-kit products require SQLite or MySQL.');
        }
        if (Schema::hasTable('sound_kit_products')) {
            throw new LogicException('Unexpected sound-kit product objects; migration refused.');
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('sound_kit_products', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // One product per private kit: a kit is sold once, and its archive revisions are chosen below.
            $table->foreignId('sound_kit_draft_id')->unique()->constrained('sound_kit_drafts')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('slug', 80)->collation($binary)->unique();
            $table->text('summary')->nullable();
            // The exact verified archive revision, pinned together with its manifest hash.
            $table->foreignId('sound_kit_revision_id')->nullable()->constrained('sound_kit_revisions')->restrictOnDelete();
            $table->char('manifest_sha256', 64)->nullable();
            $table->foreignId('license_version_id')->nullable()->constrained('license_versions')->restrictOnDelete();
            $table->unsignedInteger('price_minor')->nullable();
            $table->char('currency', 3)->default('USD');
            // Rights clearance is bound to one revision; approval is bound to the hash of the exact offer terms.
            $table->foreignId('rights_cleared_revision_id')->nullable()->constrained('sound_kit_revisions')->restrictOnDelete();
            $table->string('rights_note', 500)->nullable();
            $table->dateTime('rights_cleared_at')->nullable();
            $table->foreignId('rights_cleared_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('offer_terms_hash', 64)->nullable();
            $table->dateTime('offer_approved_at')->nullable();
            $table->foreignId('offer_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['is_published', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sound_kit_products')->exists()) {
            throw new LogicException('Sound-kit products are staff-entered records; populated migration rollback is refused.');
        }
        Schema::dropIfExists('sound_kit_products');
    }
};
