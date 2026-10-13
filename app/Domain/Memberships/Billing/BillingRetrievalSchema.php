<?php

namespace App\Domain\Memberships\Billing;

use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/** Forward263 format. Only the upgrader may admit a recognized intermediate DDL prefix. */
final class BillingRetrievalSchema
{
    public const COLUMNS = [
        'production_membership_billing_positions' => ['allocation_token' => 64, 'retrieval_token' => 64, 'phase' => 5, 'database_at' => 26],
        'production_membership_billing_observations' => ['retrieval_token' => 64, 'start_allocation_token' => 64, 'end_allocation_token' => 64],
        'production_membership_billing_attempts' => ['retrieval_token' => 64, 'start_allocation_token' => 64, 'end_allocation_token' => 64],
    ];

    public const INDEXES = ['allocation' => ['allocation_token'], 'read_phase' => ['retrieval_token', 'phase']];

    public const GUARDS = [
        ['production_membership_billing_positions', 'insert'],
        ['production_membership_billing_observations', 'insert'],
        ['production_membership_billing_observations', 'interval'],
        ['production_membership_billing_attempts', 'interval'],
    ];

    public const COMPLETE = 20;

    public function assertOwned(PDO $pdo): void
    {
        (new BillingSchema)->assertRetrievalOwned($pdo);
    }

    public function upgrade(): void
    {
        (new BillingSchema)->upgradeRetrievalIdentity();
    }

    public function down(): never
    {
        throw new LogicException('Billing retrieval identity and time require an explicit retention migration.');
    }

    /** Fixed native UTC, shared by allocation SQL and its insert guard; never a caller clock. */
    public static function clock(string $driver): string
    {
        BillingException::require(in_array($driver, ['mysql', 'sqlite'], true), 'schema');

        return $driver === 'mysql' ? 'UTC_TIMESTAMP(6)' : "strftime('%Y-%m-%d %H:%M:%f','now') || '000'";
    }

    /** The precise sequence includes each native DROP/CREATE interruption point. */
    public function steps(): array
    {
        $steps = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $length) {
                $steps[] = ['kind' => 'column', 'table' => $table, 'name' => $column, 'length' => $length];
            }
        }
        foreach (self::INDEXES as $suffix => $columns) {
            $steps[] = ['kind' => 'index', 'table' => BillingSchema::TABLES[4], 'name' => $suffix, 'columns' => $columns];
        }
        foreach (self::GUARDS as [$table, $event]) {
            $steps[] = ['kind' => 'drop', 'table' => $table, 'name' => $event];
            $steps[] = ['kind' => 'guard', 'table' => $table, 'name' => $event];
        }

        return $steps;
    }

    /** Pure expected prefix; this does not inspect or admit a database. */
    public function state(int $stage): array
    {
        BillingException::require($stage >= 0 && $stage <= self::COMPLETE, 'retrieval_schema');
        $state = ['columns' => [], 'indexes' => [], 'guards' => []];
        foreach (self::COLUMNS as $table => $columns) {
            $state['columns'][$table] = [];
        }
        foreach (self::GUARDS as [$table, $event]) {
            $state['guards'][$table][$event] = 'legacy';
        }
        foreach (array_slice($this->steps(), 0, $stage) as $step) {
            if ($step['kind'] === 'column') {
                $state['columns'][$step['table']][$step['name']] = $step['length'];
            } elseif ($step['kind'] === 'index') {
                $state['indexes'][$step['name']] = $step['columns'];
            } else {
                $state['guards'][$step['table']][$step['name']] = $step['kind'] === 'drop' ? 'absent' : 'current';
            }
        }

        return $state;
    }

    public function columnType(int $length, string $driver): string
    {
        return 'VARCHAR('.$length.')'.($driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : ' COLLATE BINARY').' NULL';
    }

    public function definition(array $definition, string $logical, string $driver, int $stage): array
    {
        $state = $this->state($stage);
        $quote = $driver === 'mysql' ? '`' : '"';
        $extra = [];
        foreach ($state['columns'][$logical] ?? [] as $column => $length) {
            $type = $this->columnType($length, $driver);
            $definition['columns'][$column] = $type;
            $extra[] = $quote.$column.$quote.' '.$type;
        }
        if ($extra !== []) {
            $definition['sql'] = preg_replace('/, (PRIMARY KEY|CONSTRAINT) /', ', '.implode(', ', $extra).', $1 ', $definition['sql'], 1);
        }
        $definition['extra_unique'] = $logical === BillingSchema::TABLES[4] ? $state['indexes'] : [];

        return $definition;
    }

    public function indexSql(string $suffix, array $columns, string $driver): string
    {
        $schema = new BillingSchema;
        $name = DB::connection()->getTablePrefix().BillingSchema::TABLES[4].'_'.$suffix;
        $quote = $driver === 'mysql' ? '`' : '"';

        // SQLite's CREATE INDEX schema qualifies the index, not the table expression.
        return 'CREATE UNIQUE INDEX '.($driver === 'sqlite' ? 'main.' : '').$quote.$name.$quote.' ON '
            .str_replace('main.', '', $schema->table(BillingSchema::TABLES[4])).' ('.implode(', ', $columns).')';
    }

    public function positionCondition(string $driver, bool $inserting = true): string
    {
        $condition = $this->hex('NEW.allocation_token', $driver)
            ." AND ((NEW.kind = 'hint' AND NEW.phase = 'hint' AND NEW.retrieval_token IS NULL)"
            ." OR (NEW.kind = 'retrieval' AND NEW.phase IN ('start','end') AND ".$this->hex('NEW.retrieval_token', $driver).'))'
            .' AND length(NEW.database_at) = 26 AND NEW.created_at = substr(NEW.database_at,1,19)';
        if ($inserting) {
            $condition .= ' AND NEW.id < 1 AND NEW.database_at = ('.self::clock($driver).')';
        }

        return $condition;
    }

    public function intervalCondition(string $driver, bool $observation, bool $inserting = true): string
    {
        $positions = (new BillingSchema)->table(BillingSchema::TABLES[4]);
        $condition = $this->hex('NEW.retrieval_token', $driver).' AND '.$this->hex('NEW.start_allocation_token', $driver)
            .' AND '.$this->hex('NEW.end_allocation_token', $driver).' AND NEW.start_allocation_token <> NEW.end_allocation_token'
            .' AND EXISTS (SELECT 1 FROM '.$positions.' s JOIN '.$positions.' e ON e.id = NEW.retrieval_end_position'
            ." WHERE s.id = NEW.retrieval_position AND s.kind = 'retrieval' AND e.kind = 'retrieval' AND s.phase = 'start' AND e.phase = 'end'"
            .' AND s.id < e.id AND s.allocation_token = NEW.start_allocation_token AND e.allocation_token = NEW.end_allocation_token'
            .' AND s.retrieval_token = NEW.retrieval_token AND e.retrieval_token = NEW.retrieval_token'
            .' AND length(s.database_at) = 26 AND length(e.database_at) = 26 AND s.database_at <= e.database_at'
            .' AND NEW.created_at >= substr(e.database_at,1,19)';
        if ($observation) {
            $expiry = $driver === 'mysql' ? 'DATE_ADD(NEW.retrieved_at, INTERVAL 600 SECOND)' : "datetime(NEW.retrieved_at,'+600 seconds')";
            $condition .= ' AND NEW.retrieval_started_at = s.database_at AND NEW.retrieved_at = substr(e.database_at,1,19)'
                .' AND NEW.freshness_deadline = '.$expiry;
        }
        $condition .= ')';
        if ($inserting) {
            $condition .= ' AND NEW.created_at <= substr(('.self::clock($driver).'),1,19)';
        }

        return $condition;
    }

    /** Both native endpoint identities were just compared by SELECT ... FOR UPDATE. No snapshot reread. */
    public function lockedIntervalCondition(string $driver, bool $observation): string
    {
        $condition = $this->hex('NEW.retrieval_token', $driver).' AND '.$this->hex('NEW.start_allocation_token', $driver)
            .' AND '.$this->hex('NEW.end_allocation_token', $driver).' AND NEW.start_allocation_token <> NEW.end_allocation_token'
            .' AND length(start_at) = 26 AND length(end_at) = 26 AND start_at <= end_at'
            .' AND NEW.created_at >= substr(end_at,1,19) AND NEW.created_at <= substr(('.self::clock($driver).'),1,19)';
        if ($observation) {
            $condition .= ' AND NEW.retrieval_started_at = start_at AND NEW.retrieved_at = substr(end_at,1,19)'
                .' AND NEW.freshness_deadline = DATE_ADD(NEW.retrieved_at, INTERVAL 600 SECOND)';
        }

        return $condition;
    }

    /** Exact columns only. Legacy projection/cryptographic verification belongs to each record reader. */
    public function legacyCondition(string $logical, string $alias = 'NEW'): string
    {
        return implode(' AND ', array_map(fn (string $column): string => $alias.'.'.$column.' IS NULL', array_keys(self::COLUMNS[$logical])));
    }

    private function hex(string $column, string $driver): string
    {
        return 'length('.$column.') = 64 AND '.($driver === 'mysql'
            ? $column." REGEXP '^[0-9a-f]{64}$'"
            : $column." NOT GLOB '*[^0-9a-f]*'");
    }
}
