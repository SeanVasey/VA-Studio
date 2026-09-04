<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('artist')->default('VASEY.AUDIO');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->string('musical_key', 24)->nullable();
            $table->string('genre')->nullable();
            $table->string('mood')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('tags')->nullable();
            $table->json('waveform')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('rights_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();
            $table->text('provenance_reference');
            $table->text('sample_disclosure');
            $table->string('status')->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();
            $table->string('role');
            $table->string('disk')->default('local');
            $table->string('storage_path')->unique();
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->json('technical_metadata')->nullable();
            $table->string('status')->default('quarantined');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['track_id', 'role', 'status']);
        });
        Schema::create('license_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('non-exclusive');
            $table->timestamps();
        });
        Schema::create('license_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('authored_source');
            $table->json('structured_terms');
            $table->string('status')->default('draft')->index();
            $table->string('source_hash', 64)->nullable();
            $table->string('model_hash', 64)->nullable();
            $table->string('renderer_version')->nullable();
            $table->string('render_fixture_hash', 64)->nullable();
            $table->string('approval_reference')->nullable();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['license_template_id', 'version']);
        });
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();
            $table->foreignId('license_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('price_minor');
            $table->char('currency', 3)->default('USD');
            $table->json('deliverable_asset_ids');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('context');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'offers', 'license_versions', 'license_templates', 'media_assets', 'rights_declarations', 'tracks'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
