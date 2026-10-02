<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_payment_exception_work', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_finalization_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $this->identity($table, 'request_id', 36)->nullable();
            $this->identity($table, 'claim_token', 36)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
        });
        Schema::create('test_payment_exception_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_finalization_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $this->identity($table, 'request_id', 36);
            $this->identity($table, 'kind', 32);
            $this->identity($table, 'outcome', 32);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('observed_at')->nullable();
            $table->dateTime('created_at');
            $table->unique(['order_finalization_id', 'sequence'], 'test_exception_event_sequence');
            $table->unique(['order_finalization_id', 'request_id', 'kind'], 'test_exception_event_request');
        });
        $parent = "EXISTS (SELECT 1 FROM order_finalizations f WHERE f.id = NEW.order_finalization_id AND f.mode = 'test' AND f.outcome = 'paid_exception')";
        $this->guard('test_exception_work_insert', 'test_payment_exception_work', 'insert', $parent.' AND NEW.sequence = 0 AND NEW.request_id IS NULL AND NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL');
        $this->guard('test_exception_event_insert', 'test_payment_exception_events', 'insert', $parent
            .' AND EXISTS (SELECT 1 FROM test_payment_exception_work w WHERE w.order_finalization_id = NEW.order_finalization_id AND NEW.sequence = w.sequence + 1)'
            .' AND NEW.sequence > 0 AND '.$this->uuid('NEW.request_id')." AND ((NEW.kind = 'disposition' AND NEW.outcome IN ('acknowledged', 'needs_review') AND NEW.observed_at IS NULL)"
            ." OR (NEW.kind = 'reconciliation_requested' AND NEW.outcome = 'pending' AND NEW.observed_at IS NULL)"
            ." OR (NEW.kind = 'reconciliation_observed' AND NEW.outcome IN ('confirmed', 'pending', 'authorized', 'expired', 'canceled', 'attention', 'unavailable') AND NEW.observed_at IS NOT NULL))");
        foreach (['update', 'delete'] as $operation) {
            $this->guard('test_exception_event_'.$operation, 'test_payment_exception_events', $operation);
        }
        $this->guard('test_exception_work_update', 'test_payment_exception_work', 'update', 'NEW.id = OLD.id AND NEW.order_finalization_id = OLD.order_finalization_id'
            .' AND (NEW.sequence = OLD.sequence OR (NEW.sequence = OLD.sequence + 1 AND EXISTS (SELECT 1 FROM test_payment_exception_events e WHERE e.order_finalization_id = NEW.order_finalization_id AND e.sequence = NEW.sequence)))'
            .' AND ((NEW.request_id IS NULL AND NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL)'
            .' OR ('.$this->uuid('NEW.request_id').' AND '.$this->uuid('NEW.claim_token').' AND NEW.lease_expires_at IS NOT NULL))');
        $this->guard('test_exception_work_delete', 'test_payment_exception_work', 'delete');
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Invalid or immutable test exception operations'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            if ($allowed !== null) {
                $allowed = str_replace(['NEW.kind', 'NEW.outcome'], ['CAST(NEW.kind AS BINARY)', 'CAST(NEW.outcome AS BINARY)'], $allowed);
            }
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable test exception operations';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    private function uuid(string $value): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "TYPEOF({$value}) = 'text' AND LENGTH({$value}) = 36 AND LENGTH(CAST({$value} AS BLOB)) = 36"
                ." AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-'"
                ." AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-'"
                ." AND LENGTH(REPLACE({$value}, '-', '')) = 32 AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'";
        }

        return "OCTET_LENGTH({$value}) = 36 AND REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
    }

    public function down(): void
    {
        foreach (['test_payment_exception_events', 'test_payment_exception_work'] as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Test exception operations must be retained.');
            }
        }
        Schema::dropIfExists('test_payment_exception_events');
        Schema::dropIfExists('test_payment_exception_work');
    }
};
