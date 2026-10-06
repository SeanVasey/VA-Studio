<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_payment_financial_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_payment_exception_event_id')->unique('test_financial_event_unique')
                ->constrained('test_payment_exception_events', indexName: 'test_financial_event_foreign')->restrictOnDelete();
            $table->string('state', 16);
            $table->mediumText('evidence_ciphertext');
            $table->string('evidence_hash', 64);
            $table->string('canonicalization_version', 32);
            $table->dateTime('observed_at');
        });
        $allowed = "NEW.state IN ('observed', 'incomplete', 'attention', 'unavailable') AND NEW.canonicalization_version = 'vasey-json-v1'"
            .' AND LENGTH(NEW.evidence_ciphertext) > 0 AND LENGTH(NEW.evidence_hash) = 64'
            ." AND EXISTS (SELECT 1 FROM test_payment_exception_events e WHERE e.id = NEW.test_payment_exception_event_id AND e.kind = 'reconciliation_observed' AND e.observed_at = NEW.observed_at)"
            .' AND NOT EXISTS (SELECT 1 FROM test_payment_financial_observations f WHERE f.id = NEW.id OR f.test_payment_exception_event_id = NEW.test_payment_exception_event_id)';
        if (DB::getDriverName() === 'mysql') {
            $allowed = str_replace(['NEW.state', 'NEW.canonicalization_version'], ['CAST(NEW.state AS BINARY)', 'CAST(NEW.canonicalization_version AS BINARY)'], $allowed);
        }
        foreach (['insert', 'update', 'delete'] as $operation) {
            $predicate = $operation === 'insert' ? $allowed : null;
            $name = 'test_financial_observation_'.$operation;
            if (DB::getDriverName() === 'sqlite') {
                $when = $predicate === null ? '' : " WHEN NOT COALESCE(({$predicate}), 0)";
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON test_payment_financial_observations{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable financial observation'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable financial observation';";
                $body = $predicate === null ? $signal : "IF NOT COALESCE(({$predicate}), 0) THEN {$signal} END IF;";
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON test_payment_financial_observations FOR EACH ROW BEGIN {$body} END");
            } else {
                throw new LogicException('Financial observations require SQLite or MySQL guards.');
            }
        }
    }

    public function down(): void
    {
        if (DB::table('test_payment_financial_observations')->exists()) {
            throw new LogicException('Financial observations must be retained.');
        }
        Schema::dropIfExists('test_payment_financial_observations');
    }
};
