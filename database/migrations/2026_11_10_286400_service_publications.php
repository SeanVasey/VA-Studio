<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-64 phase 1: staff approval of a service draft version for the default-off public service pages. A row names one
 * immutable draft version and its content hash; the public page renders only that pinned version, so a later draft edit
 * is never published silently. The optional price is staff-entered here (the draft has no price field). Nothing here
 * references orders, payments, quotes or service projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Service publications require SQLite or MySQL.');
        }
        if (Schema::hasTable('service_publications')) {
            throw new LogicException('Unexpected service publication objects; migration refused.');
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('service_publications', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->foreignId('service_draft_id')->unique()->constrained('service_drafts')->restrictOnDelete();
            $table->foreignId('service_draft_version_id')->constrained('service_draft_versions')->restrictOnDelete();
            $table->string('manifest_sha256', 64)->collation($binary);
            $table->string('slug', 80)->unique()->collation($binary);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->nullable()->collation($binary);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain service publication history; rollback is refused.');
    }
};
