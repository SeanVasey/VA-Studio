<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private bool $collecting = false;

    private array $definitions = [];

    private const TABLES = ['test_unpaid_releases', 'test_unpaid_release_events', 'test_unpaid_release_work'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Unpaid release requires guarded SQLite or MySQL.');
        }
        $this->preflight(false);
        Schema::create('test_unpaid_release_work', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $this->identity($table, 'request_id', 36)->nullable();
            $this->identity($table, 'claim_token', 36)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
        });
        Schema::create('test_unpaid_release_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('review_sequence');
            $this->identity($table, 'request_id', 36);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $this->identity($table, 'kind', 16);
            $this->identity($table, 'outcome', 32);
            $table->dateTime('observed_at')->nullable();
            $table->dateTime('created_at');
            $table->unique(['order_id', 'sequence'], 'unpaid_release_event_sequence');
            $table->unique(['order_id', 'request_id', 'kind'], 'unpaid_release_event_request');
        });
        Schema::create('test_unpaid_releases', function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('checkout_intent_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('checkout_session_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->unique()->constrained('test_unpaid_release_events')->restrictOnDelete();
            $this->identity($table, 'request_id', 36);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $this->identity($table, 'account_id', 80);
            $this->identity($table, 'mode', 8);
            $this->identity($table, 'policy_version', 64);
            $table->foreignId('inventory_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('promotion_use_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->dateTime('released_at');
            $this->evidence($table, 'evidence');
        });
        $this->ownGuards();
        $this->resourceGuards(true);
        $this->finalizationGuard(true);
    }

    private function ownGuards(): void
    {
        $event = 'NEW.sequence > 0 AND NEW.review_sequence >= 0 AND LENGTH(NEW.request_id) = 36'
            ." AND ((NEW.kind = 'requested' AND NEW.outcome = 'pending' AND NEW.observed_at IS NULL AND NEW.sequence = NEW.review_sequence + 1)"
            ." OR (NEW.kind = 'observed' AND NEW.outcome IN ('released', 'not_unpaid', 'attention', 'unavailable', 'payment_recorded')"
            .' AND NEW.observed_at IS NOT NULL AND NEW.created_at >= NEW.observed_at'
            .' AND EXISTS (SELECT 1 FROM test_unpaid_release_events e WHERE e.order_id = NEW.order_id AND e.request_id = NEW.request_id'
            ." AND e.kind = 'requested' AND e.actor_id = NEW.actor_id AND e.review_sequence = NEW.review_sequence"
            .' AND e.sequence + 1 = NEW.sequence AND e.created_at <= NEW.observed_at)))'
            .' AND EXISTS (SELECT 1 FROM test_unpaid_release_work w WHERE w.order_id = NEW.order_id AND w.sequence + 1 = NEW.sequence)'
            .' AND NOT EXISTS (SELECT 1 FROM test_unpaid_release_events e WHERE e.id = NEW.id OR (e.order_id = NEW.order_id'
            .' AND (e.sequence = NEW.sequence OR (e.request_id = NEW.request_id AND e.kind = NEW.kind))))';
        $this->guard('unpaid_release_event_insert', 'test_unpaid_release_events', 'insert', $event);
        $proof = "NEW.mode = 'test' AND NEW.policy_version = 'test-unpaid-release-v1' AND LENGTH(NEW.public_id) = 36"
            .' AND LENGTH(NEW.request_id) = 36 AND '.$this->evidenceShape('evidence')
            ." AND NEW.canonicalization_version = 'vasey-json-v1'"
            .' AND EXISTS (SELECT 1 FROM order_attempts a JOIN checkout_intents i ON i.order_attempt_id = a.id'
            .' JOIN checkout_sessions s ON s.checkout_intent_id = i.id JOIN inventory_reservations r ON r.id = a.inventory_reservation_id'
            .' JOIN test_unpaid_release_events e ON e.id = NEW.event_id'
            .' WHERE a.id = NEW.order_attempt_id AND a.order_id = NEW.order_id AND i.id = NEW.checkout_intent_id'
            ." AND i.order_id = NEW.order_id AND i.mode = 'test' AND i.account_id = NEW.account_id"
            ." AND s.id = NEW.checkout_session_id AND s.mode = 'test' AND s.account_id = NEW.account_id AND NEW.released_at >= s.created_at"
            ." AND r.id = NEW.inventory_reservation_id AND r.state = 'pending' AND r.attempt_id = a.public_id AND r.consumed_at IS NULL"
            .' AND ((a.promotion_use_id IS NULL AND NEW.promotion_use_id IS NULL) OR (a.promotion_use_id = NEW.promotion_use_id'
            ." AND EXISTS (SELECT 1 FROM promotion_uses u WHERE u.id = a.promotion_use_id AND u.state = 'pending' AND u.attempt_id = a.public_id AND u.consumed_at IS NULL)))"
            ." AND e.order_id = NEW.order_id AND e.actor_id = NEW.actor_id AND e.request_id = NEW.request_id AND e.kind = 'observed' AND e.outcome = 'released' AND e.created_at = NEW.released_at)"
            .' AND NOT EXISTS (SELECT 1 FROM verified_payments p WHERE p.order_id = NEW.order_id)'
            .' AND NOT EXISTS (SELECT 1 FROM order_finalizations f WHERE f.order_id = NEW.order_id)'
            .' AND NOT EXISTS (SELECT 1 FROM test_unpaid_releases r WHERE r.id = NEW.id OR r.public_id = NEW.public_id OR r.order_id = NEW.order_id'
            .' OR r.order_attempt_id = NEW.order_attempt_id OR r.checkout_intent_id = NEW.checkout_intent_id OR r.checkout_session_id = NEW.checkout_session_id'
            .' OR r.event_id = NEW.event_id OR r.inventory_reservation_id = NEW.inventory_reservation_id OR r.promotion_use_id = NEW.promotion_use_id)';
        $this->guard('unpaid_release_proof_insert', 'test_unpaid_releases', 'insert', $proof);
        foreach (['test_unpaid_releases', 'test_unpaid_release_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $this->guard($table.'_immutable_'.$operation, $table, $operation);
            }
        }
    }

    private function releaseResource(string $resource, array $unchanged): string
    {
        return "OLD.state = 'pending' AND NEW.state = 'released' AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NULL"
            .' AND '.$this->unchanged($unchanged)
            .' AND EXISTS (SELECT 1 FROM test_unpaid_releases r JOIN order_attempts a ON a.id = r.order_attempt_id'
            ." WHERE r.{$resource} = NEW.id AND a.public_id = NEW.attempt_id AND a.order_id = r.order_id"
            ." AND r.mode = 'test' AND r.policy_version = 'test-unpaid-release-v1')";
    }

    private function finalizationGuard(bool $release): void
    {
        $bindings = 'EXISTS (SELECT 1 FROM verified_payments p JOIN order_attempts a ON a.id = p.order_attempt_id'
            .' JOIN checkout_intents i ON i.id = p.checkout_intent_id WHERE p.id = NEW.verified_payment_id'
            .' AND p.order_id = NEW.order_id AND a.order_id = NEW.order_id AND a.id = NEW.order_attempt_id'
            ." AND p.mode = 'test' AND p.confirmed_at = NEW.confirmed_at AND a.expires_at = NEW.eligibility_cutoff"
            .' AND NEW.confirmed_at >= i.created_at'
            ." AND EXISTS (SELECT 1 FROM inventory_reservations r WHERE r.id = a.inventory_reservation_id AND r.state = 'pending'"
            .' AND r.attempt_id = a.public_id AND r.consumed_at IS NULL)'
            ." AND (a.promotion_use_id IS NULL OR EXISTS (SELECT 1 FROM promotion_uses u WHERE u.id = a.promotion_use_id AND u.state = 'pending'"
            .' AND u.attempt_id = a.public_id AND u.consumed_at IS NULL)))';
        $outcome = "(NEW.outcome = 'paid' AND NEW.reason IS NULL AND NEW.confirmed_at < NEW.eligibility_cutoff)"
            ." OR (NEW.outcome = 'paid_exception' AND NEW.reason IN ('late_confirmation', 'inventory_blocked', 'inventory_unavailable', 'asset_unavailable', 'rights_unavailable'))";
        $legacy = "NEW.mode = 'test' AND NEW.policy_version = 'test-order-finalization-v1' AND ({$outcome})"
            ." AND NEW.finalized_at >= NEW.confirmed_at AND {$bindings} AND ".$this->evidenceShape('evidence')
            .' AND LENGTH(NEW.public_id) = 36';
        if ($release) {
            $legacy .= ' AND NOT EXISTS (SELECT 1 FROM test_unpaid_releases r WHERE r.order_id = NEW.order_id)';
        }
        $allowed = $legacy;
        if ($release) {
            $allowed = '('.$legacy.') OR ('.$this->releasedFinalization().')';
        }
        if (! $this->collecting) {
            DB::unprepared('DROP TRIGGER IF EXISTS order_finalizations_valid_insert');
        }
        $this->guard('order_finalizations_valid_insert', 'order_finalizations', 'insert', $allowed);
    }

    private function releasedFinalization(): string
    {
        return "NEW.mode = 'test' AND NEW.policy_version = 'test-unpaid-release-v1' AND NEW.outcome = 'paid_exception' AND NEW.reason = 'released_attempt'"
            .' AND NEW.finalized_at >= NEW.confirmed_at AND LENGTH(NEW.public_id) = 36 AND '.$this->evidenceShape('evidence')
            .' AND EXISTS (SELECT 1 FROM verified_payments p JOIN order_attempts a ON a.id = p.order_attempt_id'
            .' JOIN checkout_intents i ON i.id = p.checkout_intent_id JOIN test_unpaid_releases r ON r.order_id = p.order_id'
            .' JOIN inventory_reservations v ON v.id = r.inventory_reservation_id'
            .' WHERE p.id = NEW.verified_payment_id AND p.order_id = NEW.order_id AND a.order_id = NEW.order_id AND a.id = NEW.order_attempt_id'
            ." AND p.mode = 'test' AND p.confirmed_at = NEW.confirmed_at AND a.expires_at = NEW.eligibility_cutoff AND NEW.confirmed_at >= i.created_at"
            .' AND r.order_attempt_id = a.id AND r.checkout_intent_id = i.id AND r.checkout_session_id = p.checkout_session_id'
            .' AND r.account_id = p.account_id AND r.released_at <= NEW.finalized_at'
            ." AND v.id = a.inventory_reservation_id AND v.state = 'released' AND v.attempt_id = a.public_id AND v.consumed_at IS NULL"
            ." AND (a.promotion_use_id IS NULL OR EXISTS (SELECT 1 FROM promotion_uses u WHERE u.id = a.promotion_use_id AND u.state = 'released' AND u.attempt_id = a.public_id AND u.consumed_at IS NULL)))";
    }

    /** Preserve old pending/expiry transitions and add only paid-linked, immutable consumption. */
    private function resourceGuards(bool $release): void
    {
        foreach (['inventory_reservations_initial', 'inventory_reservations_transition', 'promotion_uses_guard_insert', 'promotion_uses_guard_update'] as $name) {
            if (! $this->collecting) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
            }
        }
        $finalization = true;
        $emptyConsumption = ' AND NEW.consumed_at IS NULL';
        $oldEmptyConsumption = $finalization ? ' AND OLD.consumed_at IS NULL' : '';
        $this->guard('inventory_reservations_initial', 'inventory_reservations', 'insert',
            "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NULL AND NEW.expires_at > NEW.created_at".$emptyConsumption);
        $identity = $this->unchanged(['id', 'public_id', 'quote_id', 'snapshot', 'snapshot_hash', 'canonicalization_version', 'created_at', 'expires_at']);
        $pending = "NEW.state = 'pending' AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at AND NEW.expired_at IS NULL";
        $expired = "NEW.state = 'expired' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NOT NULL AND NEW.expired_at >= OLD.expires_at";
        $old = "OLD.state = 'held' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND OLD.expired_at IS NULL AND (({$pending}) OR ({$expired}))".$emptyConsumption.$oldEmptyConsumption;
        $consumed = $finalization ? ' OR ('.$this->consume('inventory_reservation_id').' AND '.$this->unchanged(['attempt_id', 'pending_at', 'expired_at']).' AND OLD.expired_at IS NULL)' : '';
        $this->guard('inventory_reservations_transition', 'inventory_reservations', 'update', "{$identity} AND (({$old}){$consumed}".($release ? ' OR ('.$this->releaseResource('inventory_reservation_id', ['attempt_id', 'pending_at', 'expired_at']).')' : '').')');

        $this->guard('promotion_uses_guard_insert', 'promotion_uses', 'insert',
            "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expires_at > NEW.created_at".$emptyConsumption);
        $identity = $this->unchanged(['id', 'promotion_campaign_id', 'quote_pricing_id', 'created_at', 'expires_at']);
        $old = "OLD.state = 'held' AND NEW.state = 'pending' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at".$emptyConsumption.$oldEmptyConsumption;
        $consumed = $finalization ? ' OR ('.$this->consume('promotion_use_id').' AND '.$this->unchanged(['attempt_id', 'pending_at']).')' : '';
        $this->guard('promotion_uses_guard_update', 'promotion_uses', 'update', "{$identity} AND (({$old}){$consumed}".($release ? ' OR ('.$this->releaseResource('promotion_use_id', ['attempt_id', 'pending_at']).')' : '').')');
    }

    private function consume(string $resource): string
    {
        return "OLD.state = 'pending' AND NEW.state = 'consumed' AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NOT NULL"
            .' AND OLD.attempt_id IS NOT NULL AND OLD.pending_at IS NOT NULL AND NEW.consumed_at >= OLD.pending_at'
            .' AND EXISTS (SELECT 1 FROM order_finalizations f JOIN order_attempts a ON a.id = f.order_attempt_id'
            ." WHERE f.outcome = 'paid' AND f.mode = 'test' AND a.{$resource} = NEW.id"
            .' AND a.public_id = NEW.attempt_id AND f.order_id = a.order_id AND f.finalized_at = NEW.consumed_at)';
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function evidence(Blueprint $table, string $prefix): void
    {
        $table->longText($prefix.'_ciphertext');
        $this->identity($table, $prefix.'_hash', 64);
        $this->identity($table, 'canonicalization_version', 32);
    }

    private function evidenceShape(string $prefix): string
    {
        return "LENGTH(NEW.{$prefix}_ciphertext) > 0 AND LENGTH(NEW.{$prefix}_hash) = 64 AND LENGTH(NEW.canonicalization_version) BETWEEN 1 AND 32";
    }

    private function unchanged(array $fields): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return implode(' AND ', array_map(fn ($field) => "NEW.{$field} IS OLD.{$field}", $fields));
        }

        // Existing resource columns predate ASCII identity columns. Preserve their bytes, not collation equivalence.
        return implode(' AND ', array_map(fn ($field) => "CAST(NEW.{$field} AS BINARY) <=> CAST(OLD.{$field} AS BINARY)", $fields));
    }

    private function guard(string $name, string $table, string $operation, ?string $allowed = null): void
    {
        if ($allowed !== null && DB::getDriverName() === 'mysql' && in_array($table, ['inventory_reservations', 'promotion_uses'], true)) {
            // Historical state columns use the database's default collation; state spelling is still exact.
            $allowed = $this->bytewise($allowed);
        }
        if (DB::getDriverName() === 'sqlite') {
            $when = $allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)";
            $body = "BEGIN SELECT RAISE(ABORT, 'Invalid or immutable finalization evidence'); END";
            $statement = "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} {$body}";
        } elseif (DB::getDriverName() === 'mysql') {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable finalization evidence';";
            $body = $allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;";
            $body = "BEGIN {$body} END";
            $statement = "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
        }
        if ($this->collecting) {
            $this->definitions[$name] = compact('table', 'operation', 'body', 'statement');
        } else {
            DB::unprepared($statement);
        }
    }

    /** Compare only whole whitelisted column names; longer or additionally qualified names stay unchanged. */
    public function bytewise(string $condition): string
    {
        $names = implode('|', array_map(fn (string $column): string => preg_quote($column, '/'), ['NEW.state', 'OLD.state']));

        return preg_replace('/(?<![\w$.])(?:'.$names.')(?![\w$])/', 'CAST($0 AS BINARY)', $condition)
            ?? throw new LogicException('The guard condition could not be rewritten.');
    }

    /** Check every replaced guard's exact bytes and owner before any implicit-commit DDL. */
    private function preflight(bool $installed): void
    {
        $this->collecting = true;
        try {
            $this->definitions = [];
            $this->resourceGuards($installed);
            $this->finalizationGuard($installed);
            $expected = $this->definitions;
            $this->definitions = [];
            $this->ownGuards();
            $owned = $this->definitions;
        } finally {
            $this->collecting = false;
        }
        $tables = [...self::TABLES, 'inventory_reservations', 'promotion_uses', 'order_finalizations'];
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), [...$tables, ...array_keys($expected), ...array_keys($owned)])
                ->orWhereIn(DB::raw('tbl_name COLLATE NOCASE'), $tables)->exists()) {
                throw new LogicException('Temporary release objects prevent migration.');
            }
        } else {
            foreach ($tables as $table) {
                try {
                    $definition = DB::selectOne('SHOW CREATE TABLE '.$table);
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                        continue;
                    }
                    throw $error;
                }
                if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                    throw new LogicException('Temporary release tables prevent migration.');
                }
            }
        }
        foreach (self::TABLES as $table) {
            $objects = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->get()
                : DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
            $object = $objects->first();
            $valid = ! $installed ? $objects->isEmpty() : $objects->count() === 1 && (DB::getDriverName() === 'sqlite'
                ? $object->name === $table && $object->type === 'table'
                : $object->TABLE_NAME === $table && $object->TABLE_TYPE === 'BASE TABLE' && $object->ENGINE === 'InnoDB');
            if (! $valid) {
                throw new LogicException('Unexpected release table ownership.');
            }
        }
        foreach ($expected + $owned as $name => $definition) {
            $mustExist = isset($expected[$name]) || $installed;
            $objects = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
            $guard = $objects->first();
            $valid = ! $mustExist ? $objects->isEmpty() : $objects->count() === 1 && (DB::getDriverName() === 'sqlite'
                ? $guard->type === 'trigger' && $guard->name === $name && $guard->tbl_name === $definition['table'] && $guard->sql === $definition['statement']
                : $guard->TRIGGER_NAME === $name && $guard->EVENT_OBJECT_TABLE === $definition['table'] && $guard->ACTION_TIMING === 'BEFORE'
                    && $guard->EVENT_MANIPULATION === strtoupper($definition['operation']) && $guard->ACTION_STATEMENT === $definition['body']);
            if (! $valid) {
                throw new LogicException('Unexpected release guard ownership: '.$name);
            }
        }
        if ($installed) {
            $columns = [
                'test_unpaid_release_work' => ['id', 'order_id', 'sequence', 'request_id', 'claim_token', 'lease_expires_at'],
                'test_unpaid_release_events' => ['id', 'order_id', 'sequence', 'review_sequence', 'request_id', 'actor_id', 'kind', 'outcome', 'observed_at', 'created_at'],
                'test_unpaid_releases' => ['id', 'public_id', 'order_id', 'order_attempt_id', 'checkout_intent_id', 'checkout_session_id', 'event_id', 'request_id', 'actor_id', 'account_id', 'mode', 'policy_version', 'inventory_reservation_id', 'promotion_use_id', 'released_at', 'evidence_ciphertext', 'evidence_hash', 'canonicalization_version'],
            ];
            foreach ($columns as $table => $expectedColumns) {
                if (array_column(Schema::getColumns($table), 'name') !== $expectedColumns) {
                    throw new LogicException('Unexpected release table columns.');
                }
            }
            foreach (self::TABLES as $table) {
                $count = DB::getDriverName() === 'sqlite'
                    ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->count()
                    : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', $table)->count();
                if ($count !== count(array_filter($owned, fn ($guard) => $guard['table'] === $table))) {
                    throw new LogicException('Foreign release guards prevent rollback.');
                }
            }
        }
    }

    public function down(): void
    {
        $this->preflight(true);
        // All refusal checks precede any guard replacement or DDL, including MySQL's implicit commits.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Unpaid release history must be retained.');
            }
        }
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            if (DB::table($table)->where('state', 'released')->exists()) {
                throw new LogicException('Released resources must be retained.');
            }
        }
        if (DB::table('order_finalizations')->where('policy_version', 'test-unpaid-release-v1')->exists()) {
            throw new LogicException('Released payment exceptions must be retained.');
        }
        $this->resourceGuards(false);
        $this->finalizationGuard(false);
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
