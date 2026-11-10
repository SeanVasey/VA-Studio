<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-53 follow-up (FP-007): the public demo audio of a sound-kit product. A demo is staff-uploaded WAV audio that the media
 * pipeline scans and turns into the same seller-tagged MP3 preview a track gets; this table holds the private source, the
 * retained scan evidence and the prepared preview of each upload. It is a record of staff uploads: no customer, payment or
 * entitlement data, and the kit archive is a separate private object that nothing here can name. A row is never deleted, and
 * once `ready` or `failed` it never changes (a replacement is a new row). Which demo a product shows, and whether staff approved
 * it, lives on `sound_kit_products` (2026_11_11_000601) and is decided by App\Domain\SoundKits\Products\SoundKitProductMedia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Sound-kit demos require SQLite or MySQL.');
        }
        if (Schema::hasTable('sound_kit_demos')) {
            throw new LogicException('Unexpected sound-kit demo objects; migration refused.');
        }
        Schema::create('sound_kit_demos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sound_kit_product_id')->constrained('sound_kit_products')->restrictOnDelete();
            $table->string('original_name', 240);
            $table->string('source_path')->unique();
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 32);
            $table->string('status', 16)->default('quarantined');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->dateTime('claimed_until')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->string('preview_path')->nullable()->unique();
            $table->char('preview_sha256', 64)->nullable();
            $table->unsignedBigInteger('preview_size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('waveform')->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            $table->index(['sound_kit_product_id', 'status']);
        });
        $message = 'Sound-kit demo evidence is retained and immutable once processed';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER sound_kit_demos_retain BEFORE DELETE ON sound_kit_demos BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            DB::unprepared("CREATE TRIGGER sound_kit_demos_immutable BEFORE UPDATE ON sound_kit_demos WHEN OLD.status IN ('ready', 'failed') BEGIN SELECT RAISE(ABORT, '{$message}'); END");

            return;
        }
        DB::unprepared("CREATE TRIGGER sound_kit_demos_retain BEFORE DELETE ON sound_kit_demos FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END");
        DB::unprepared("CREATE TRIGGER sound_kit_demos_immutable BEFORE UPDATE ON sound_kit_demos FOR EACH ROW BEGIN IF OLD.status IN ('ready', 'failed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
    }

    public function down(): void
    {
        if (DB::table('sound_kit_demos')->exists()) {
            throw new LogicException('Sound-kit demo evidence is retained; populated migration rollback is refused.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS sound_kit_demos_retain');
        DB::unprepared('DROP TRIGGER IF EXISTS sound_kit_demos_immutable');
        Schema::dropIfExists('sound_kit_demos');
    }
};
