<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();
            $table->foreignId('license_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('rights_declaration_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('price_minor');
            $table->char('currency', 3);
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->unique(['offer_id', 'revision']);
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->foreignId('current_revision_id')->nullable()->constrained('offer_revisions')->restrictOnDelete();
        });
        // Existing active flags are not evidence of a commercial revision. No backfill is performed.
        foreach (['update', 'delete'] as $operation) {
            $name = 'offer_revisions_immutable_'.$operation;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON offer_revisions BEGIN SELECT RAISE(ABORT, 'Commercial revisions are immutable'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON offer_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Commercial revisions are immutable'");
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER offers_track_immutable_update BEFORE UPDATE ON offers WHEN NEW.track_id <> OLD.track_id BEGIN SELECT RAISE(ABORT, 'Offer product identity is immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER offers_track_immutable_update BEFORE UPDATE ON offers FOR EACH ROW BEGIN IF NEW.track_id <> OLD.track_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Offer product identity is immutable'; END IF; END");
        }
        foreach (['insert', 'update'] as $operation) {
            $name = 'offers_revision_owner_'.$operation;
            $condition = 'NEW.current_revision_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM offer_revisions WHERE id = NEW.current_revision_id AND offer_id = NEW.id AND track_id = NEW.track_id)';
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON offers WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Commercial revision belongs to a different offer or track'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON offers FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Commercial revision belongs to a different offer or track'; END IF; END");
            }
        }
    }

    public function down(): void
    {
        foreach (['offers_track_immutable_update', 'offers_revision_owner_insert', 'offers_revision_owner_update', 'offer_revisions_immutable_update', 'offer_revisions_immutable_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::table('offers', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_revision_id'));
        Schema::dropIfExists('offer_revisions');
    }
};
