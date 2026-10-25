<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane CX (FP-075): one email delivery row per private order-ready intent. Additive and forward-only. The notice is
 * referenced by id and public id and proven by the insert guard rather than a foreign key, so the retained notification
 * family's own migrations keep their rollback shape. Notices are never deleted (their own guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Transactional mail deliveries require SQLite or MySQL.');
        }
        if (Schema::hasTable('transactional_mail_deliveries')) {
            throw new LogicException('Unexpected transactional mail delivery objects; migration refused.');
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('transactional_mail_deliveries', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->char('public_id', 36)->collation($binary)->unique();
            $table->unsignedBigInteger('notice_id')->unique();
            $table->char('notice_public_id', 36)->collation($binary);
            $table->string('template_version', 40)->collation($binary);
            $table->char('capability', 64)->collation($binary);
            $table->string('state', 16)->collation($binary);
            $table->string('reason', 40)->collation($binary)->nullable();
            $table->char('message_hash', 64)->collation($binary)->nullable();
            $table->unsignedInteger('version');
            $table->dateTime('queued_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->index(['state', 'id']);
        });
        foreach ($this->guards() as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain transactional mail delivery evidence; this migration is forward-only.');
    }

    /** @return array<string, string> */
    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $text = fn (string $expression): string => $sqlite ? $expression : "BINARY {$expression}";
        $same = implode(' AND ', array_map(fn (string $column): string => $sqlite ? "NEW.{$column} IS OLD.{$column}" : "BINARY NEW.{$column} <=> BINARY OLD.{$column}",
            ['id', 'public_id', 'notice_id', 'notice_public_id', 'template_version', 'capability', 'queued_at']));
        $hex = fn (string $column): string => $sqlite ? "length({$column}) = 64 AND {$column} NOT GLOB '*[^0-9a-f]*'" : "REGEXP_LIKE({$column}, '^[0-9a-f]{64}$', 'c')";
        $state = $text('NEW.state');
        $old = $text('OLD.state');
        $reason = $text('NEW.reason');
        $insert = "{$state} = 'queued' AND NEW.version = 0 AND NEW.reason IS NULL AND NEW.message_hash IS NULL AND NEW.started_at IS NULL"
            ." AND NEW.finished_at IS NULL AND {$text('NEW.template_version')} = 'order-ready-mail-v1' AND ".$hex('NEW.capability')
            .' AND length(NEW.public_id) = 36 AND EXISTS (SELECT 1 FROM transactional_notices n WHERE n.id = NEW.notice_id'
            ." AND {$text('n.public_id')} = {$text('NEW.notice_public_id')} AND {$text('n.notification_type')} = 'test_order_ready')";
        $update = "{$same} AND NEW.version = OLD.version + 1 AND ("
            ."({$old} = 'queued' AND {$state} = 'submitting' AND NEW.started_at IS NOT NULL AND NEW.finished_at IS NULL AND NEW.reason IS NULL AND NEW.message_hash IS NULL)"
            ." OR ({$old} = 'queued' AND {$state} = 'failed' AND NEW.started_at IS NULL AND NEW.finished_at IS NOT NULL AND {$reason} IN ('not_configured', 'configuration_changed') AND NEW.message_hash IS NULL)"
            ." OR ({$old} = 'submitting' AND NEW.started_at = OLD.started_at AND NEW.finished_at IS NOT NULL AND NEW.finished_at >= NEW.started_at AND ("
            ."({$state} = 'accepted' AND NEW.reason IS NULL AND NEW.message_hash IS NOT NULL AND ".$hex('NEW.message_hash').')'
            ." OR ({$state} = 'failed' AND {$reason} IN ('authority_withdrawn', 'intent_unreadable', 'not_configured') AND NEW.message_hash IS NULL)"
            ." OR ({$state} = 'uncertain' AND {$reason} = 'transport_uncertain' AND NEW.message_hash IS NULL))))";
        $guards = [];
        foreach (['insert' => $insert, 'update' => $update, 'delete' => null] as $operation => $valid) {
            $name = 'transactional_mail_deliveries_'.$operation;
            $message = 'Transactional mail delivery evidence is invalid or immutable';
            if ($sqlite) {
                $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
                $guards[$name] = "CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON transactional_mail_deliveries{$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END";
            } else {
                $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
                if ($valid !== null) {
                    $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;";
                }
                $guards[$name] = "CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON transactional_mail_deliveries FOR EACH ROW BEGIN {$body} END";
            }
        }

        return $guards;
    }
};
