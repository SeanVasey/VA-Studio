<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-47 (FP-075): a staff requeue of an order-ready email whose earlier attempt failed before any transport call is a NEW
 * delivery row (attempt 2, 3, ...), never a revival of the old one: the existing guards keep every delivery forward-only and
 * undeletable, so the failed attempt stays as evidence. The first attempt keeps attempt = 1 (the column default), so code that
 * does not know about attempts is unchanged. One notice can no longer be limited to one delivery row, so uniqueness moves from
 * notice_id to (notice_id, attempt); the adapter allows a further attempt only while no earlier attempt was accepted.
 * No table is added (transactional_mail_deliveries is already classified in RetentionSchedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Transactional mail attempts require SQLite or MySQL.');
        }
        if (! Schema::hasTable('transactional_mail_deliveries') || Schema::hasColumn('transactional_mail_deliveries', 'attempt')) {
            throw new LogicException('Unexpected transactional mail delivery shape; migration refused.');
        }
        Schema::table('transactional_mail_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('attempt')->default(1);
        });
        Schema::table('transactional_mail_deliveries', function (Blueprint $table): void {
            $table->unique(['notice_id', 'attempt'], 'transactional_mail_deliveries_notice_attempt_unique');
        });
        Schema::table('transactional_mail_deliveries', function (Blueprint $table): void {
            $table->dropUnique('transactional_mail_deliveries_notice_id_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain transactional mail delivery evidence; this migration is forward-only.');
    }
};
