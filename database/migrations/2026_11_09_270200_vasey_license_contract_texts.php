<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract increment 5, payload growth: the template and renderer profile of a `vasey-license-pdf-v2` selection, stored
 * once each by SHA-256 instead of in every paid and paid-tax grant origin (App\Domain\Grants\VaseyLicenseContractTexts).
 * Additive. Rows are immutable: triggers refuse any UPDATE or DELETE, and an INSERT of an unknown kind or a malformed hash.
 */
return new class extends Migration
{
    private const TABLE = 'vasey_license_contract_texts';

    private const TRIGGERS = ['vasey_license_contract_texts_insert', 'vasey_license_contract_texts_immutable', 'vasey_license_contract_texts_retain'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('v2 contract texts require SQLite or MySQL.');
        }
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->char('sha256', 64);
            $table->longText('payload');
            $table->timestamp('created_at');
            $table->unique(['kind', 'sha256'], 'vasey_license_contract_texts_address');
        });
        $shape = "NEW.kind IN ('template', 'renderer_profile') AND length(NEW.sha256) = 64 AND length(NEW.payload) > 0";
        [$insert, $immutable, $retain] = self::TRIGGERS;
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$insert} BEFORE INSERT ON ".self::TABLE." WHEN NOT COALESCE(({$shape}), 0) BEGIN SELECT RAISE(ABORT, 'v2 contract text shape refused'); END");
            DB::unprepared("CREATE TRIGGER {$immutable} BEFORE UPDATE ON ".self::TABLE." BEGIN SELECT RAISE(ABORT, 'v2 contract texts are immutable'); END");
            DB::unprepared("CREATE TRIGGER {$retain} BEFORE DELETE ON ".self::TABLE." BEGIN SELECT RAISE(ABORT, 'v2 contract texts are retained'); END");
        } else {
            DB::unprepared("CREATE TRIGGER {$insert} BEFORE INSERT ON ".self::TABLE." FOR EACH ROW BEGIN IF NOT COALESCE(({$shape}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'v2 contract text shape refused'; END IF; END");
            DB::unprepared("CREATE TRIGGER {$immutable} BEFORE UPDATE ON ".self::TABLE." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'v2 contract texts are immutable'");
            DB::unprepared("CREATE TRIGGER {$retain} BEFORE DELETE ON ".self::TABLE." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'v2 contract texts are retained'");
        }
    }

    public function down(): void
    {
        // Retained grant origins resolve their template and profile by hash: rollback only while nothing was stored.
        if (DB::table(self::TABLE)->exists()) {
            throw new LogicException('Retain immutable v2 contract texts: paid grant origins resolve them by hash.');
        }
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::drop(self::TABLE);
    }
};
