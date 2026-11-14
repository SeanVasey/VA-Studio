<?php

use App\Support\MigrationDefinitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff release of a test order that was never sent to the payment provider.
 *
 * HostedCheckout commits a CheckoutIntent under the Order lock before any Checkout Session create can be
 * issued, so an order with no intent was never sent. Once its attempt window has closed, HostedCheckout
 * refuses to create one, and the checkout_intents guard below refuses one for a released order. The proof
 * is local only; nothing here reads or writes the provider.
 */
return new class extends Migration
{
    private const TABLE = 'test_unsent_order_releases';

    private const POLICY = 'test-unsent-release-v1';

    private const PROOF = 'no_checkout_intent_closed_window_v1';

    private const MESSAGE = 'Invalid or immutable unsent release evidence';

    private const RESOURCE_MESSAGE = 'Invalid or immutable finalization evidence';

    private const COLUMNS = ['id', 'public_id', 'order_id', 'order_attempt_id', 'event_id', 'request_id', 'actor_id', 'mode',
        'policy_version', 'proof', 'inventory_reservation_id', 'promotion_use_id', 'checkout_window_closed_at', 'released_at',
        'evidence_ciphertext', 'evidence_hash', 'canonicalization_version'];

    public function up(): void
    {
        $this->preflight(false);
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $this->identity($table, 'public_id', 36)->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->unique()->constrained('test_unpaid_release_events')->restrictOnDelete();
            $this->identity($table, 'request_id', 36);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $this->identity($table, 'mode', 8);
            $this->identity($table, 'policy_version', 64);
            $this->identity($table, 'proof', 64);
            $table->foreignId('inventory_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('promotion_use_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->dateTime('checkout_window_closed_at');
            $table->dateTime('released_at');
            $table->longText('evidence_ciphertext');
            $this->identity($table, 'evidence_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
        });
        foreach ($this->guards() as $guard) {
            DB::unprepared($guard['sql']);
        }
        $this->installResourceGuards($this->resourceDefinitions(true));
    }

    public function down(): void
    {
        $this->preflight(true);
        // All refusal checks precede guard replacement or DDL, including MySQL's implicit commits.
        if (DB::table(self::TABLE)->exists()) {
            throw new LogicException('Unsent release history must be retained.');
        }
        $this->installResourceGuards($this->resourceDefinitions(false));
        foreach (array_keys($this->guards()) as $name) {
            DB::unprepared('DROP TRIGGER '.$name);
        }
        Schema::drop(self::TABLE);
    }

    /** @return array<string, array{table: string, operation: string, body: string, sql: string}> */
    private function guards(): array
    {
        $state = $this->exact('r.state');
        $proof = $this->exact('NEW.mode')." = 'test' AND ".$this->exact('NEW.policy_version')." = '".self::POLICY."'"
            .' AND '.$this->exact('NEW.proof')." = '".self::PROOF."' AND LENGTH(NEW.public_id) = 36 AND LENGTH(NEW.request_id) = 36"
            .' AND LENGTH(NEW.evidence_ciphertext) > 0 AND LENGTH(NEW.evidence_hash) = 64'
            .' AND '.$this->exact('NEW.canonicalization_version')." = 'vasey-json-v1' AND NEW.released_at >= NEW.checkout_window_closed_at"
            .' AND EXISTS (SELECT 1 FROM order_attempts a JOIN inventory_reservations r ON r.id = a.inventory_reservation_id'
            .' JOIN test_unpaid_release_events e ON e.id = NEW.event_id'
            .' WHERE a.id = NEW.order_attempt_id AND a.order_id = NEW.order_id AND a.expires_at = NEW.checkout_window_closed_at'
            ." AND r.id = NEW.inventory_reservation_id AND {$state} = 'pending' AND r.attempt_id = a.public_id AND r.consumed_at IS NULL"
            .' AND ((a.promotion_use_id IS NULL AND NEW.promotion_use_id IS NULL) OR (a.promotion_use_id = NEW.promotion_use_id'
            .' AND EXISTS (SELECT 1 FROM promotion_uses u WHERE u.id = a.promotion_use_id AND '.$this->exact('u.state')." = 'pending'"
            .' AND u.attempt_id = a.public_id AND u.consumed_at IS NULL)))'
            ." AND e.order_id = NEW.order_id AND e.actor_id = NEW.actor_id AND e.request_id = NEW.request_id AND e.kind = 'observed'"
            ." AND e.outcome = 'released' AND e.created_at = NEW.released_at)"
            // The never-sent proof: no checkout intent (the write-ahead record of every provider create) and no money.
            .' AND NOT EXISTS (SELECT 1 FROM checkout_intents i WHERE i.order_id = NEW.order_id)'
            .' AND NOT EXISTS (SELECT 1 FROM verified_payments p WHERE p.order_id = NEW.order_id)'
            .' AND NOT EXISTS (SELECT 1 FROM order_finalizations f WHERE f.order_id = NEW.order_id)'
            .' AND NOT EXISTS (SELECT 1 FROM test_unpaid_releases x WHERE x.order_id = NEW.order_id)'
            .' AND NOT EXISTS (SELECT 1 FROM '.self::TABLE.' x WHERE x.id = NEW.id OR x.public_id = NEW.public_id'
            .' OR x.order_id = NEW.order_id OR x.order_attempt_id = NEW.order_attempt_id OR x.event_id = NEW.event_id'
            .' OR x.inventory_reservation_id = NEW.inventory_reservation_id OR x.promotion_use_id = NEW.promotion_use_id)';
        $guards = [];
        $this->guard($guards, 'unsent_release_insert', self::TABLE, 'insert', $proof);
        $this->guard($guards, 'unsent_release_immutable_update', self::TABLE, 'update');
        $this->guard($guards, 'unsent_release_immutable_delete', self::TABLE, 'delete');
        // A released never-sent order stays terminal: no checkout intent, so no provider create and no payment, can follow.
        $this->guard($guards, 'checkout_intents_unsent_release_insert', 'checkout_intents', 'insert',
            'NOT EXISTS (SELECT 1 FROM '.self::TABLE.' u WHERE u.order_id = NEW.order_id)');

        return $guards;
    }

    private function guard(array &$guards, string $name, string $table, string $operation, ?string $allowed = null): void
    {
        $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."';";
        $body = 'BEGIN '.($allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;").' END';
        $sql = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}".($allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)")
                ." BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END"
            : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
        $guards[$name] = compact('table', 'operation', 'body', 'sql');
    }

    /**
     * The installed resource guards are the refund-resolution migration's; this adds exactly one disjunct to each update guard.
     *
     * @return array<string, array{table: string, operation: string, body: string, sql: string}>
     */
    private function resourceDefinitions(bool $unsent): array
    {
        $refund = MigrationDefinitions::load('2026_10_06_000047_test_refund_resolutions.php');
        $definitions = (new ReflectionMethod($refund, 'resourceDefinitions'))->invoke($refund, true);
        if (array_keys($definitions) !== ['inventory_reservations_transition', 'promotion_uses_guard_update']) {
            throw new LogicException('Unexpected resource guard set.');
        }
        if (! $unsent) {
            return $definitions;
        }
        $unchanged = ['inventory_reservations_transition' => [['id', 'public_id', 'quote_id', 'snapshot', 'snapshot_hash', 'canonicalization_version', 'created_at', 'expires_at'], ['attempt_id', 'pending_at', 'expired_at'], 'inventory_reservation_id'],
            'promotion_uses_guard_update' => [['id', 'promotion_campaign_id', 'quote_pricing_id', 'created_at', 'expires_at'], ['attempt_id', 'pending_at'], 'promotion_use_id']];
        foreach ($definitions as $name => $definition) {
            [$identity, $fields, $resource] = $unchanged[$name];
            $release = $this->unchanged($identity)." AND OLD.state = 'pending' AND NEW.state = 'released' AND OLD.consumed_at IS NULL"
                .' AND NEW.consumed_at IS NULL AND '.$this->unchanged($fields)
                .' AND EXISTS (SELECT 1 FROM '.self::TABLE.' x JOIN order_attempts a ON a.id = x.order_attempt_id'
                ." WHERE x.{$resource} = NEW.id AND a.public_id = NEW.attempt_id AND a.order_id = x.order_id"
                ." AND x.mode = 'test' AND x.policy_version = '".self::POLICY."')";
            if (DB::getDriverName() === 'mysql') {
                $release = preg_replace('/(?<![\w$.])(?:NEW\.state|OLD\.state)(?![\w$])/', 'CAST($0 AS BINARY)', $release)
                    ?? throw new LogicException('The resource guard could not be rewritten.');
            }
            $allowed = '('.$this->allowed($definition).") OR ({$release})";
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::RESOURCE_MESSAGE."';";
            $body = "BEGIN IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF; END";
            $sql = DB::getDriverName() === 'sqlite'
                ? "CREATE TRIGGER {$name} BEFORE update ON {$definition['table']} WHEN NOT COALESCE(({$allowed}), 0) BEGIN SELECT RAISE(ABORT, '".self::RESOURCE_MESSAGE."'); END"
                : "CREATE TRIGGER {$name} BEFORE update ON {$definition['table']} FOR EACH ROW {$body}";
            $definitions[$name] = ['table' => $definition['table'], 'operation' => 'update', 'body' => $body, 'sql' => $sql];
        }

        return $definitions;
    }

    /** Recover the exact predicate of a previous guard from its own generated statement. */
    private function allowed(array $definition): string
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $text = $sqlite ? $definition['sql'] : $definition['body'];
        $prefix = $sqlite ? "CREATE TRIGGER {$this->name($definition)} BEFORE update ON {$definition['table']} WHEN NOT COALESCE((" : 'BEGIN IF NOT COALESCE((';
        $suffix = $sqlite ? "), 0) BEGIN SELECT RAISE(ABORT, '".self::RESOURCE_MESSAGE."'); END"
            : "), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::RESOURCE_MESSAGE."'; END IF; END";
        if (! str_starts_with($text, $prefix) || ! str_ends_with($text, $suffix)) {
            throw new LogicException('Unexpected resource guard shape.');
        }

        return substr($text, strlen($prefix), -strlen($suffix));
    }

    private function name(array $definition): string
    {
        return $definition['table'] === 'inventory_reservations' ? 'inventory_reservations_transition' : 'promotion_uses_guard_update';
    }

    private function installResourceGuards(array $definitions): void
    {
        foreach ($definitions as $name => $guard) {
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($guard['sql']);
        }
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function exact(string $value): string
    {
        return DB::getDriverName() === 'sqlite' ? $value : "CAST({$value} AS BINARY)";
    }

    private function unchanged(array $fields): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return implode(' AND ', array_map(fn ($field) => "NEW.{$field} IS OLD.{$field}", $fields));
        }

        return implode(' AND ', array_map(fn ($field) => "CAST(NEW.{$field} AS BINARY) <=> CAST(OLD.{$field} AS BINARY)", $fields));
    }

    /** Check table, guard and replaced-guard ownership by exact bytes before any implicit-commit DDL. */
    private function preflight(bool $installed): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Unsent releases require guarded SQLite or MySQL.');
        }
        $sqlite = DB::getDriverName() === 'sqlite';
        $owned = $this->guards();
        $resources = $this->resourceDefinitions($installed);
        $tables = [self::TABLE, 'checkout_intents', 'inventory_reservations', 'promotion_uses'];
        if ($sqlite) {
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), [...$tables, ...array_keys($owned), ...array_keys($resources)])
                ->orWhereIn(DB::raw('tbl_name COLLATE NOCASE'), $tables)->exists()) {
                throw new LogicException('Temporary unsent release objects prevent migration.');
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
                    throw new LogicException('Temporary unsent release tables prevent migration.');
                }
            }
        }
        $objects = $sqlite ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [self::TABLE])->get()
            : DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [self::TABLE])->get();
        $object = $objects->first();
        $valid = ! $installed ? $objects->isEmpty() : $objects->count() === 1 && ($sqlite
            ? $object->name === self::TABLE && $object->type === 'table'
            : $object->TABLE_NAME === self::TABLE && $object->TABLE_TYPE === 'BASE TABLE' && $object->ENGINE === 'InnoDB');
        if (! $valid) {
            throw new LogicException('Unexpected unsent release table ownership.');
        }
        foreach ($owned + $resources as $name => $guard) {
            $objects = $sqlite ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
            $object = $objects->first();
            $mustExist = $installed || isset($resources[$name]);
            $valid = ! $mustExist ? $objects->isEmpty() : $objects->count() === 1 && ($sqlite
                ? $object->type === 'trigger' && $object->name === $name && $object->tbl_name === $guard['table'] && $object->sql === $guard['sql']
                : $object->TRIGGER_NAME === $name && $object->EVENT_OBJECT_TABLE === $guard['table'] && $object->ACTION_TIMING === 'BEFORE'
                    && $object->EVENT_MANIPULATION === strtoupper($guard['operation']) && $object->ACTION_STATEMENT === $guard['body']);
            if (! $valid) {
                throw new LogicException('Unexpected unsent release guard ownership: '.$name);
            }
        }
        if ($installed) {
            if (array_column(Schema::getColumns(self::TABLE), 'name') !== self::COLUMNS) {
                throw new LogicException('Unexpected unsent release table columns.');
            }
            $count = $sqlite ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::TABLE)->count()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', self::TABLE)->count();
            if ($count !== count(array_filter($owned, fn ($guard) => $guard['table'] === self::TABLE))) {
                throw new LogicException('Foreign unsent release guards prevent rollback.');
            }
        }
    }
};
