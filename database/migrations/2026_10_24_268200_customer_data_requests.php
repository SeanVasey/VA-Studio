<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane CX (FP-082): customer data export and deletion requests. Additive and forward-only. Requests move exactly once
 * from their open state to a terminal state; events are append-only; an export's ciphertext can only be purged. Nothing
 * here references or changes orders, contracts, grants, payments, inquiries, consent or audit records.
 */
return new class extends Migration
{
    private const TABLES = ['customer_data_requests', 'customer_data_request_events', 'customer_data_exports'];

    private const EVENTS = "'requested', 'export_ready', 'export_downloaded', 'export_expired', 'deletion_completed', 'deletion_rejected'";

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Customer data requests require SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                throw new LogicException('Unexpected customer data request objects; migration refused.');
            }
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('customer_data_requests', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->char('public_id', 36)->collation($binary)->unique();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->string('kind', 16)->collation($binary);
            $table->string('state', 16)->collation($binary);
            // 1 while the request is open, NULL once resolved: at most one open request of each kind per account.
            $table->unsignedTinyInteger('open_slot')->nullable();
            $table->unsignedInteger('version');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('resolution_hash', 64)->collation($binary)->nullable();
            $table->unique(['customer_account_id', 'kind', 'open_slot'], 'customer_data_requests_open_unique');
            $table->index(['state', 'id']);
        });
        Schema::create('customer_data_request_events', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->foreignId('request_id')->constrained('customer_data_requests')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event', 32)->collation($binary);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->longText('detail');
            $table->char('detail_hash', 64)->collation($binary);
            $table->dateTime('created_at');
            $table->unique(['request_id', 'sequence']);
        });
        Schema::create('customer_data_exports', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('customer_data_requests')->restrictOnDelete();
            $table->longText('ciphertext')->nullable();
            $table->char('content_hash', 64)->collation($binary);
            $table->char('token_hash', 64)->collation($binary)->unique();
            $table->unsignedInteger('size_bytes');
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->dateTime('created_at');
        });
        foreach ($this->guards() as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain customer data request evidence; this migration is forward-only.');
    }

    /** @return array<string, string> trigger name => statement */
    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $same = fn (array $columns): string => implode(' AND ', array_map(fn (string $column): string => $sqlite
            ? "NEW.{$column} IS OLD.{$column}" : "BINARY NEW.{$column} <=> BINARY OLD.{$column}", $columns));
        $text = fn (string $expression): string => $sqlite ? $expression : "BINARY {$expression}";
        $hex = fn (string $column, int $length): string => $sqlite
            ? "length({$column}) = {$length} AND {$column} NOT GLOB '*[^0-9a-f]*'"
            : "REGEXP_LIKE({$column}, '^[0-9a-f]{{$length}}$', 'c')";
        $uuid = $sqlite
            ? "length(NEW.public_id) = 36 AND NEW.public_id NOT GLOB '*[^0-9a-f-]*'"
            : "REGEXP_LIKE(NEW.public_id, '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')";
        $kind = $text('NEW.kind');
        $state = $text('NEW.state');
        $oldKind = $text('OLD.kind');
        $oldState = $text('OLD.state');

        $requestInsert = "{$uuid} AND NEW.version = 0 AND NEW.open_slot = 1 AND NEW.resolved_at IS NULL AND NEW.resolved_by IS NULL"
            .' AND NEW.resolution_hash IS NULL AND NEW.created_at IS NOT NULL AND NEW.updated_at = NEW.created_at'
            ." AND (({$kind} = 'export' AND {$state} = 'ready') OR ({$kind} = 'deletion' AND {$state} = 'received'))";
        $requestUpdate = $same(['id', 'public_id', 'customer_account_id', 'kind', 'created_at'])
            .' AND NEW.version = OLD.version + 1 AND OLD.open_slot = 1 AND NEW.open_slot IS NULL'
            .' AND NEW.resolved_at IS NOT NULL AND NEW.updated_at = NEW.resolved_at AND NEW.updated_at >= OLD.updated_at'
            ." AND (({$oldKind} = 'export' AND {$oldState} = 'ready' AND {$state} IN ('downloaded', 'expired')"
            .' AND NEW.resolved_by IS NULL AND NEW.resolution_hash IS NULL)'
            ." OR ({$oldKind} = 'deletion' AND {$oldState} = 'received' AND {$state} IN ('completed', 'rejected')"
            .' AND NEW.resolved_by IS NOT NULL AND NEW.resolution_hash IS NOT NULL AND '.$hex('NEW.resolution_hash', 64).'))';
        $eventInsert = $text('NEW.event').' IN ('.self::EVENTS.') AND '.$hex('NEW.detail_hash', 64)
            .' AND EXISTS (SELECT 1 FROM customer_data_requests r WHERE r.id = NEW.request_id)'
            .' AND NEW.sequence = COALESCE((SELECT MAX(e.sequence) FROM customer_data_request_events e WHERE e.request_id = NEW.request_id), 0) + 1';
        $exportInsert = 'NEW.ciphertext IS NOT NULL AND NEW.consumed_at IS NULL AND NEW.purged_at IS NULL AND NEW.expires_at > NEW.created_at'
            .' AND '.$hex('NEW.content_hash', 64).' AND '.$hex('NEW.token_hash', 64)
            ." AND EXISTS (SELECT 1 FROM customer_data_requests r WHERE r.id = NEW.request_id AND {$text('r.kind')} = 'export' AND {$text('r.state')} = 'ready')";
        // One purge, optionally recording the single download in the same statement. The ciphertext never returns.
        $exportUpdate = $same(['id', 'request_id', 'content_hash', 'token_hash', 'size_bytes', 'expires_at', 'created_at'])
            .' AND OLD.purged_at IS NULL AND OLD.ciphertext IS NOT NULL AND NEW.ciphertext IS NULL AND NEW.purged_at IS NOT NULL'
            .' AND (NEW.consumed_at IS NULL OR (OLD.consumed_at IS NULL AND NEW.consumed_at = NEW.purged_at))';

        $conditions = [
            'customer_data_requests' => ['insert' => $requestInsert, 'update' => $requestUpdate, 'delete' => null],
            'customer_data_request_events' => ['insert' => $eventInsert, 'update' => null, 'delete' => null],
            'customer_data_exports' => ['insert' => $exportInsert, 'update' => $exportUpdate, 'delete' => null],
        ];
        $guards = [];
        foreach ($conditions as $table => $operations) {
            foreach ($operations as $operation => $valid) {
                $name = $table.'_'.$operation;
                $message = 'Customer data request evidence is invalid or immutable';
                if ($sqlite) {
                    $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
                    $guards[$name] = "CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table}{$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END";
                } else {
                    $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
                    if ($valid !== null) {
                        $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;";
                    }
                    $guards[$name] = "CREATE TRIGGER {$name} BEFORE ".strtoupper($operation)." ON {$table} FOR EACH ROW BEGIN {$body} END";
                }
            }
        }

        return $guards;
    }
};
