<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_availabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_campaign_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision'); $table->boolean('enabled'); $table->dateTime('updated_at');
        });
        Schema::create('promotion_availability_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_campaign_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision'); $table->boolean('enabled'); $table->boolean('previous_enabled')->nullable();
            $table->string('operation', 16); $table->char('policy_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $table->dateTime('created_at');
            $table->unique(['promotion_campaign_id', 'revision'], 'promotion_availability_revision_unique');
        });
        foreach (['update', 'delete'] as $operation) {
            $this->guard('promotion_availability_revisions', 'immutable_'.$operation, $operation);
        }
        $this->guard('promotion_availabilities', 'retain', 'delete');
        $initial = "NEW.revision = 1 AND NEW.enabled = 0 AND NEW.previous_enabled IS NULL AND NEW.operation = 'create'"
            .' AND NOT EXISTS (SELECT 1 FROM promotion_availabilities a WHERE a.promotion_campaign_id = NEW.promotion_campaign_id)';
        $transition = "NEW.revision BETWEEN 2 AND 2147483646 AND NEW.enabled IN (0, 1) AND NEW.previous_enabled IN (0, 1)"
            ." AND ((NEW.enabled = 1 AND NEW.operation = 'enable') OR (NEW.enabled = 0 AND NEW.operation = 'disable'))"
            .' AND EXISTS (SELECT 1 FROM promotion_availabilities a WHERE a.promotion_campaign_id = NEW.promotion_campaign_id'
            .' AND NEW.revision = a.revision + 1 AND NEW.previous_enabled = a.enabled AND NEW.enabled <> a.enabled AND NEW.created_at >= a.updated_at)';
        $this->guard('promotion_availability_revisions', 'transition', 'insert',
            '(('.$initial.') OR ('.$transition.')) AND EXISTS (SELECT 1 FROM promotion_campaigns c WHERE c.id = NEW.promotion_campaign_id AND c.snapshot_hash = NEW.policy_hash)');
        $matchingHistory = 'EXISTS (SELECT 1 FROM promotion_availability_revisions r WHERE r.promotion_campaign_id = NEW.promotion_campaign_id'
            .' AND r.revision = NEW.revision AND r.enabled = NEW.enabled AND r.created_at = NEW.updated_at)';
        $this->guard('promotion_availabilities', 'initial', 'insert', 'NEW.revision = 1 AND NEW.enabled = 0 AND '.$matchingHistory);
        $this->guard('promotion_availabilities', 'transition', 'update',
            'NEW.id = OLD.id AND NEW.promotion_campaign_id = OLD.promotion_campaign_id AND NEW.revision = OLD.revision + 1'
            .' AND NEW.revision BETWEEN 2 AND 2147483646 AND NEW.enabled IN (0, 1) AND NEW.enabled <> OLD.enabled'
            .' AND NEW.updated_at >= OLD.updated_at AND '.$matchingHistory);
    }

    private function guard(string $table, string $suffix, string $operation, ?string $valid = null): void
    {
        // Short explicit prefixes keep MySQL trigger identifiers below its 64-character limit.
        $name = ($table === 'promotion_availabilities' ? 'promotion_availability_' : 'promotion_avail_history_').$suffix;
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Promotion administration evidence is invalid or immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion administration evidence is invalid or immutable';";
            if ($valid !== null) { $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;"; }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        if (DB::table('promotion_availabilities')->exists() || DB::table('promotion_availability_revisions')->exists()) {
            throw new \LogicException('Promotion administration history must be retained; populated rollback is refused.');
        }
        foreach (['initial', 'transition', 'retain'] as $suffix) { DB::unprepared('DROP TRIGGER IF EXISTS promotion_availability_'.$suffix); }
        foreach (['transition', 'immutable_update', 'immutable_delete'] as $suffix) { DB::unprepared('DROP TRIGGER IF EXISTS promotion_avail_history_'.$suffix); }
        Schema::dropIfExists('promotion_availability_revisions');
        Schema::dropIfExists('promotion_availabilities');
    }
};
