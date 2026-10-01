<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_inquiries', function (Blueprint $table): void {
            $collation = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
            $table->id();
            $table->char('public_id', 36)->collation($collation)->unique();
            $table->char('owner_hash', 64)->collation($collation);
            $table->char('request_key', 36)->collation($collation)->unique();
            $table->char('payload_hash', 64)->collation($collation);
            $table->longText('payload');
            $table->text('privacy_notice');
            $table->char('privacy_notice_hash', 64)->collation($collation);
            $table->string('retention_policy_reference', 120);
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('site_release_id')->constrained('site_releases')->restrictOnDelete();
            $table->char('site_content_hash', 64)->collation($collation);
            $table->string('state', 16)->collation($collation);
            $table->unsignedInteger('version');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['owner_hash', 'request_key']);
            $table->index(['state', 'id']);
        });
        $same = array_map(fn (string $column): string => DB::getDriverName() === 'mysql'
            ? "BINARY NEW.{$column} = BINARY OLD.{$column}" : "NEW.{$column} IS OLD.{$column}", [
                'id', 'public_id', 'owner_hash', 'request_key', 'payload_hash', 'payload', 'privacy_notice',
                'privacy_notice_hash', 'retention_policy_reference', 'operator_user_id', 'site_release_id', 'site_content_hash', 'created_at',
            ]);
        // MySQL ascii_bin is PAD SPACE: VARCHAR values need binary operands to reject padded states.
        $newState = DB::getDriverName() === 'mysql' ? 'BINARY NEW.state' : 'NEW.state';
        $oldState = DB::getDriverName() === 'mysql' ? 'BINARY OLD.state' : 'OLD.state';
        $this->guard('insert', "{$newState} = 'new' AND NEW.version = 0");
        $this->guard('update', implode(' AND ', $same).' AND NEW.version = OLD.version + 1 AND NEW.version BETWEEN 1 AND 2147483646'
            ." AND (({$oldState} = 'new' AND {$newState} IN ('read', 'archived')) OR ({$oldState} = 'read' AND {$newState} = 'archived')) AND NEW.updated_at >= OLD.updated_at");
        $this->guard('delete');
    }

    private function guard(string $operation, ?string $valid = null): void
    {
        $name = 'customer_inquiries_'.$operation;
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON customer_inquiries{$when} BEGIN SELECT RAISE(ABORT, 'Inquiry evidence or transition is invalid'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inquiry evidence or transition is invalid';";
            if ($valid !== null) {
                $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;";
            }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON customer_inquiries FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        if (DB::table('customer_inquiries')->exists()) {
            throw new LogicException('Populated inquiry rollback requires an approved retention workflow.');
        }
        foreach (['insert', 'update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS customer_inquiries_'.$operation);
        }
        Schema::dropIfExists('customer_inquiries');
    }
};
