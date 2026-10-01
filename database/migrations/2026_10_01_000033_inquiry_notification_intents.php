<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Inquiry notification guards require SQLite or MySQL.');
        }
        Schema::create('inquiry_notification_intents', function (Blueprint $table): void {
            $collation = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
            $table->id();
            $table->foreignId('customer_inquiry_id')->unique()->constrained('customer_inquiries')->restrictOnDelete();
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 24)->collation($collation);
            $table->string('state', 16)->collation($collation);
            $table->unsignedInteger('attempts');
            // VARCHAR retains trailing bytes for the exact UUID guard; CHAR may trim them on MySQL.
            $table->string('claim_token', 36)->collation($collation)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->string('outcome', 32)->collation($collation)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['state', 'next_attempt_at', 'id'], 'inquiry_notifications_due');
            $table->index(['state', 'lease_expires_at', 'id'], 'inquiry_notifications_lease');
        });

        $state = $this->exact('NEW.state');
        $oldState = $this->exact('OLD.state');
        $outcome = $this->exact('NEW.outcome');
        $clearClaim = 'NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL';
        $clearSchedule = 'NEW.next_attempt_at IS NULL';
        $clearOutcome = 'NEW.outcome IS NULL';
        $attempts = 'NEW.attempts = CAST(NEW.attempts AS '.(DB::getDriverName() === 'mysql' ? 'SIGNED' : 'INTEGER').') AND NEW.attempts BETWEEN 0 AND 3';
        $dates = $this->dateTime('NEW.created_at').' AND '.$this->dateTime('NEW.updated_at')
            .' AND (NEW.lease_expires_at IS NULL OR '.$this->dateTime('NEW.lease_expires_at').')'
            .' AND (NEW.next_attempt_at IS NULL OR '.$this->dateTime('NEW.next_attempt_at').')';
        $shape = "{$attempts} AND {$dates} AND NEW.updated_at >= NEW.created_at AND ("
            ."({$state} = 'pending' AND NEW.attempts = 0 AND {$clearClaim} AND {$clearSchedule} AND {$clearOutcome})"
            ." OR ({$state} = 'processing' AND NEW.attempts >= 1 AND ".$this->uuid('NEW.claim_token')
            ." AND NEW.lease_expires_at > NEW.updated_at AND {$clearSchedule} AND {$clearOutcome})"
            ." OR ({$state} = 'retry' AND NEW.attempts BETWEEN 1 AND 2 AND {$clearClaim}"
            ." AND NEW.next_attempt_at > NEW.updated_at AND {$outcome} = 'definitely_not_submitted')"
            ." OR ({$state} = 'submitted' AND NEW.attempts >= 1 AND {$clearClaim} AND {$clearSchedule} AND {$outcome} = 'handed_off')"
            ." OR ({$state} = 'unknown' AND NEW.attempts >= 1 AND {$clearClaim} AND {$clearSchedule}"
            ." AND {$outcome} IN ('handoff_uncertain', 'lease_expired'))"
            ." OR ({$state} = 'blocked' AND {$clearClaim} AND {$clearSchedule}"
            ." AND ({$outcome} IN ('authority_withdrawn', 'configuration_withdrawn') OR (NEW.attempts = 3 AND {$outcome} = 'retry_exhausted'))))";
        $parent = 'EXISTS (SELECT 1 FROM customer_inquiries i WHERE i.id = NEW.customer_inquiry_id AND i.operator_user_id = NEW.operator_user_id)';
        $this->guard('insert', $shape." AND {$state} = 'pending' AND ".$this->exact('NEW.kind')." = 'operator_inbox_v1'"
            .' AND NEW.created_at = NEW.updated_at AND '.$parent);

        $identity = implode(' AND ', array_map(fn (string $column): string => DB::getDriverName() === 'mysql'
            ? "BINARY NEW.{$column} = BINARY OLD.{$column}" : "NEW.{$column} IS OLD.{$column}",
            ['id', 'customer_inquiry_id', 'operator_user_id', 'kind', 'created_at']));
        $claim = "{$state} = 'processing' AND NEW.attempts = OLD.attempts + 1 AND OLD.attempts < 3"
            ." AND ({$oldState} = 'pending' OR ({$oldState} = 'retry' AND OLD.next_attempt_at <= NEW.updated_at))";
        $active = "{$oldState} = 'processing' AND OLD.lease_expires_at > NEW.updated_at AND NEW.attempts = OLD.attempts";
        $finish = "{$active} AND ({$state} IN ('submitted', 'retry', 'blocked')"
            ." OR ({$state} = 'unknown' AND {$outcome} = 'handoff_uncertain'))";
        $expired = "{$oldState} = 'processing' AND OLD.lease_expires_at <= NEW.updated_at"
            ." AND {$state} = 'unknown' AND {$outcome} = 'lease_expired' AND NEW.attempts = OLD.attempts";
        $withdrawn = "{$oldState} IN ('pending', 'retry') AND {$state} = 'blocked'"
            ." AND {$outcome} = 'authority_withdrawn' AND NEW.attempts = OLD.attempts";
        $this->guard('update', $identity.' AND '.$shape.' AND NEW.updated_at >= OLD.updated_at'
            ." AND (({$claim}) OR ({$finish}) OR ({$expired}) OR ({$withdrawn}))");
        $this->guard('delete');
    }

    private function exact(string $value): string
    {
        // ascii_bin still uses PAD SPACE; binary operands reject padded states, kinds and outcomes.
        return DB::getDriverName() === 'mysql' ? 'BINARY '.$value : $value;
    }

    private function uuid(string $value): string
    {
        if (DB::getDriverName() === 'sqlite') {
            // LENGTH(text) and GLOB stop at NUL: check all stored bytes as well.
            return "{$value} IS NOT NULL AND LENGTH(CAST({$value} AS BLOB)) = 36 AND LENGTH({$value}) = 36"
                ." AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-'"
                ." AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-'"
                ." AND LENGTH(REPLACE({$value}, '-', '')) = 32 AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'";
        }

        return "{$value} IS NOT NULL AND OCTET_LENGTH({$value}) = 36"
            ." AND REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
    }

    private function dateTime(string $value): string
    {
        // MySQL's DATETIME type validates its calendar. SQLite's TEXT affinity does not.
        return DB::getDriverName() === 'sqlite'
            ? "{$value} IS NOT NULL AND LENGTH(CAST({$value} AS BLOB)) = 19"
                ." AND STRFTIME('%Y-%m-%d %H:%M:%S', {$value}, '+0 days') = {$value}"
            : "{$value} IS NOT NULL";
    }

    private function guard(string $operation, ?string $valid = null): void
    {
        $name = 'inquiry_notification_intents_'.$operation;
        $message = 'Inquiry notification identity or transition is invalid';
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON inquiry_notification_intents{$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        } else {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
            if ($valid !== null) {
                $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;";
            }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON inquiry_notification_intents FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        if (DB::table('inquiry_notification_intents')->exists()) {
            throw new LogicException('Populated inquiry notification rollback requires an approved retention workflow.');
        }
        foreach (['insert', 'update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS inquiry_notification_intents_'.$operation);
        }
        Schema::dropIfExists('inquiry_notification_intents');
    }
};
