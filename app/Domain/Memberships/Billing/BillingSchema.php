<?php

namespace App\Domain\Memberships\Billing;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipSchema;
use App\Domain\Memberships\Production\MembershipSchemaCatalog;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/**
 * Billing259 evidence tables: immutable approved subscription bindings, one row per provider invoice
 * identity, append-only hash-linked retrieval observations, deduplicated webhook hints and the
 * database-issued positions that order retrievals and hints. Same atomic DDL, owned-prefix recovery and
 * schema-global namespace algorithm as MembershipSchema. No foreign key or trigger reaches into any
 * production_identity_* child; buyer values are bound by runtime proof. No row here awards, reserves or
 * consumes a credit.
 *
 * Positions (Codex P1 on PR #54, review L2-3 and `BillingReconciliation.php:42`): one AUTO_INCREMENT (MySQL) /
 * AUTOINCREMENT (SQLite) row is inserted when a retrieval begins, before any provider read, another when its
 * provider reads have ended, and one when a webhook hint is received. The database allocates the ids in
 * strictly increasing order, so "began after" and "ended before" are decided by one counter on one server,
 * never by comparing application clocks read on different hosts. The guards admit a position only as a
 * database-allocated id and an observation or hint only with unused positions of its own kind.
 */
final class BillingSchema
{
    public const TABLES = ['production_membership_billing_subscriptions', 'production_membership_billing_invoices', 'production_membership_billing_observations', 'production_membership_billing_events',
        'production_membership_billing_positions', 'production_membership_billing_attempts'];

    public const POSITION_KINDS = ['retrieval', 'hint'];

    public const OUTCOMES = ['settled', 'not_settled', 'unknown', 'refused', 'reversed'];

    public const DISPOSITIONS = ['retrieval_hint', 'no_invoice_hint', 'ignored_type'];

    public function table(string $logical): string
    {
        BillingException::require(in_array($logical, self::TABLES, true), 'schema');
        $name = DB::connection()->getTablePrefix().$logical;
        BillingException::require(strlen($name) <= 52 && preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) === 1, 'schema');

        return DB::getDriverName() === 'sqlite' ? 'main."'.$name.'"' : '`'.$name.'`';
    }

    public function assertOwned(PDO $pdo): void
    {
        BillingException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        $this->up(false);
        (new BillingAwardFence)->assertOwned($pdo);
    }

    /** Migration 262 must recheck the historical graph while its native table locks are held. */
    public function assertRetainedGraph(PDO $pdo, bool $tablesLocked = false): void
    {
        BillingException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        $this->inspect(false, false, $tablesLocked);
    }

    public function up(bool $ddl = true): void
    {
        $this->inspect($ddl, false);
    }

    /** Only the explicit successor may admit the exact old six-table graph without the new interval guards. */
    private function inspect(bool $ddl, bool $intervalUpgrade, bool $billingTablesLocked = false): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        BillingException::require(in_array($driver, ['sqlite', 'mysql'], true), 'schema');
        if (! $billingTablesLocked) {
            // Membership ownership includes the complete identity parent floor, once.
            // Plan versions are the 257 parent of every subscription binding.
            try {
                (new MembershipSchema)->assertOwned($pdo);
            } catch (MembershipException $exception) {
                if ($exception->reason === 'parent_floor') {
                    throw new BillingException('parent_floor');
                }

                throw $exception;
            }
        }
        if ($driver === 'mysql' && ! $ddl) {
            // Retained graphs must already contain every table. Pin them before batching;
            // actual transaction ownership and all current SHOW/shadow checks remain separate.
            foreach (self::TABLES as $logical) {
                try {
                    $pdo->query('SELECT * FROM '.$this->table($logical).' LIMIT 0')->fetchAll(PDO::FETCH_ASSOC);
                } catch (\PDOException) {
                    throw new BillingException('schema');
                }
            }
        }
        $catalog = $driver === 'mysql' ? new MembershipSchemaCatalog($pdo) : null;
        $this->assertNamespace($pdo, $driver, $billingTablesLocked, $catalog);
        // The later fence is optional for historical migrations, but never an unowned extra trigger.
        (new BillingAwardFence)->assertEventGuardIfPresent($pdo, $billingTablesLocked);
        $plans = [];
        $absent = false;
        $prefixEnded = false;
        foreach (self::TABLES as $logical) {
            $table = $this->table($logical);
            $name = DB::connection()->getTablePrefix().$logical;
            $definition = $this->definition($logical, $driver);
            $create = 'CREATE TABLE '.$table.' ('.$definition['sql'].')'.($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '');
            $guards = $this->guards($logical, $driver, $name, $table);
            $allowedGuards = array_keys($guards);
            if ($logical === self::TABLES[3]) {
                $allowedGuards[] = (new BillingAwardFence)->eventGuardName();
            }
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare('SELECT name, tbl_name FROM sqlite_temp_master');
                $statement->execute();
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $object) {
                    BillingException::require(! in_array(strtolower($object['name']), array_map(strtolower(...), [$name, ...array_keys($guards)]), true) && strtolower($object['tbl_name']) !== strtolower($name), 'shadowed_schema');
                }
                $statement = $pdo->prepare('SELECT name, type, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    BillingException::require($actual['type'] === 'table' && $this->sql($actual['sql']) === $this->sql(str_replace('main.', '', $create)), 'schema');
                    $indexes = $pdo->query('PRAGMA main.index_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
                    BillingException::require(count($indexes) === $this->autoindexes($logical, $driver), 'schema');
                }
                $statement = $pdo->prepare("SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND tbl_name = ?");
                $statement->execute([$name]);
                BillingException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), $allowedGuards) === [], 'schema');
            } else {
                $objects = $catalog->named('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)', $this->mysqlNames(), $name);
                BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TABLE_NAME'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    unset($actual['TABLE_NAME']);
                }
                // SHOW also resolves a local temporary table when no durable table exists.
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
                    BillingException::require(! str_contains(strtoupper($shown[1]), 'TEMPORARY'), 'shadowed_schema');
                } catch (\PDOException $error) {
                    if ($actual !== false || $error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
                if ($actual !== false) {
                    BillingException::require($actual === ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin'], 'schema');
                    $this->mysqlDefinition($pdo, $name, $definition, $catalog);
                }
                $actualGuards = $catalog->named('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND EVENT_OBJECT_TABLE = ?', $this->mysqlNames(), $name);
                BillingException::require(array_diff(array_column($actualGuards, 'TRIGGER_NAME'), $allowedGuards) === [], 'schema');
            }
            BillingException::require(! (($absent || $prefixEnded) && $actual !== false), 'schema_prefix');
            $absent = $absent || $actual === false;
            $prefixEnded = $prefixEnded || $actual === false;
            $missing = [];
            foreach ($guards as $guard => $expected) {
                if ($driver === 'sqlite') {
                    $statement = $pdo->prepare('SELECT name, type, tbl_name, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        BillingException::require($row['type'] === 'trigger' && $row['tbl_name'] === $name && $this->sql($row['sql']) === $this->sql($expected['sql']), 'schema');
                    }
                } else {
                    $objects = $catalog->named('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND lower(TRIGGER_NAME) = lower(?)', $this->mysqlGuardNames(), $guard);
                    BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TRIGGER_NAME'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        BillingException::require($row['EVENT_OBJECT_TABLE'] === $name && $row['EVENT_MANIPULATION'] === $expected['event'] && $row['ACTION_TIMING'] === 'BEFORE' && $this->sql($row['ACTION_STATEMENT']) === $this->sql($expected['body']), 'schema');
                    }
                }
                $legacyInterval = $intervalUpgrade && $actual !== false && $guard === $name.'_interval';
                BillingException::require(! ($prefixEnded && $row !== false), 'schema_prefix');
                $prefixEnded = $prefixEnded || ($row === false && ! $legacyInterval);
                if ($row === false) {
                    $missing[] = $expected['sql'];
                }
            }
            $onlyInterval = $intervalUpgrade && isset($guards[$name.'_interval']) && $missing === [$guards[$name.'_interval']['sql']];
            if ($actual !== false && $missing !== [] && ! $onlyInterval) {
                BillingException::require($pdo->query('SELECT 1 FROM '.$table.' LIMIT 1')->fetchColumn() === false, 'retained_unguarded_schema');
            }
            BillingException::require($ddl || ($actual !== false && ($missing === [] || $onlyInterval)), 'schema');
            BillingException::require($actual !== false || count($missing) === count($guards), 'schema');
            $plans[] = ['create' => $actual === false ? $create : null, 'guards' => $missing];
        }
        // Inspect the entire existing graph before the first write, including later drift/foreign names.
        if ($ddl) {
            foreach ($plans as $plan) {
                if ($plan['create'] !== null) {
                    $pdo->exec($plan['create']);
                }
                foreach ($plan['guards'] as $sql) {
                    $pdo->exec($sql);
                }
            }
            $this->up(false);

        }
    }

    public function down(): never
    {
        throw new LogicException('Production membership billing evidence requires an explicit retention migration.');
    }

    private function physical(string $logical): string
    {
        if (in_array($logical, [...self::TABLES, 'production_membership_plan_versions'], true)) {
            return DB::connection()->getTablePrefix().$logical;
        }
        BillingException::require(in_array($logical, ['users', 'customer_accounts'], true), 'schema');

        return $logical;
    }

    private function definition(string $logical, string $driver): array
    {
        $name = $this->physical($logical);
        $ascii = $driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : ' COLLATE BINARY';
        $text = $driver === 'mysql' ? 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'TEXT';
        $integer = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
        $id = 'VARCHAR(36)'.$ascii.' NOT NULL';
        $hash = 'VARCHAR(64)'.$ascii.' NOT NULL';
        $utc = 'VARCHAR(19)'.$ascii.' NOT NULL';
        $columns = ['id' => $id];
        $primary = ['id'];
        $unique = [];
        $foreign = [];
        $check = 'length(id) = 36 AND length(seal) = 64';
        $mode = 'VARCHAR(8)'.$ascii.' NOT NULL';
        $position = $integer.' NOT NULL';
        if ($logical === self::TABLES[4]) {
            // The id is the position. SQLite admits AUTOINCREMENT only on an inline INTEGER PRIMARY KEY (a rowid alias, so no
            // autoindex); MySQL CHECK constraints cannot name an AUTO_INCREMENT column, and UNSIGNED keeps it positive.
            $columns = ['id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'kind' => 'VARCHAR(16)'.$ascii.' NOT NULL', 'created_at' => $utc];
            $primary = $driver === 'mysql' ? ['id'] : [];
            $check = "kind IN ('retrieval','hint') AND length(created_at) = 19";
        } elseif ($logical === self::TABLES[0]) {
            $columns += ['account_id' => $integer.' NOT NULL', 'user_id' => $integer.' NOT NULL', 'identity_origin_id' => $integer.' NOT NULL',
                'provider_account_hash' => $hash, 'mode' => $mode, 'customer_ref_hash' => $hash, 'subscription_ref_hash' => $hash,
                'price_ref_hash' => $hash, 'plan_version_id' => $id, 'approval_binding_hash' => $hash, 'provenance' => 'VARCHAR(32)'.$ascii.' NOT NULL'];
            $unique = [['subscription_ref_hash']];
            $foreign = ['account_id' => 'customer_accounts', 'user_id' => 'users', 'plan_version_id' => 'production_membership_plan_versions'];
            $check .= " AND account_id > 0 AND user_id > 0 AND identity_origin_id > 0 AND length(provider_account_hash) = 64 AND length(customer_ref_hash) = 64 AND length(subscription_ref_hash) = 64 AND length(price_ref_hash) = 64 AND length(approval_binding_hash) = 64 AND ((mode = 'test' AND provenance = 'synthetic_rehearsal') OR (mode = 'live' AND provenance = 'verified_production'))";
        } elseif ($logical === self::TABLES[1]) {
            $columns += ['subscription_binding_id' => $id, 'invoice_ref_hash' => $hash, 'source_invoice_hash' => $hash,
                'provider_account_hash' => $hash, 'mode' => $mode];
            $unique = [['invoice_ref_hash'], ['source_invoice_hash']];
            $foreign = ['subscription_binding_id' => self::TABLES[0]];
            $check .= " AND length(invoice_ref_hash) = 64 AND length(source_invoice_hash) = 64 AND length(provider_account_hash) = 64 AND mode IN ('test','live')";
        } elseif ($logical === self::TABLES[2]) {
            $columns += ['invoice_id' => $id, 'sequence' => 'INTEGER NOT NULL', 'outcome' => 'VARCHAR(16)'.$ascii.' NOT NULL', 'facts_hash' => $hash,
                'line_period_start' => 'VARCHAR(19)'.$ascii.' NULL', 'line_period_end' => 'VARCHAR(19)'.$ascii.' NULL',
                'amount_minor' => 'INTEGER NULL', 'currency' => 'VARCHAR(3)'.$ascii.' NULL', 'retrieved_at' => $utc,
                'retrieval_started_at' => 'VARCHAR(26)'.$ascii.' NOT NULL', 'retrieval_position' => $position, 'retrieval_end_position' => $position,
                'freshness_deadline' => $utc,
                'api_version' => 'VARCHAR(32)'.$ascii.' NOT NULL', 'sdk_reference' => 'VARCHAR(40)'.$ascii.' NOT NULL', 'prior_seal' => $hash];
            // One retrieval appends at most one observation, so neither of its positions is ever reused.
            $unique = [['invoice_id', 'sequence'], ['retrieval_position'], ['retrieval_end_position']];
            $foreign = ['invoice_id' => self::TABLES[1]];
            $check .= " AND sequence BETWEEN 1 AND 10000 AND outcome IN ('settled','not_settled','unknown','refused','reversed') AND length(facts_hash) = 64 AND length(prior_seal) = 64 AND retrieved_at < freshness_deadline AND length(retrieval_started_at) = 26 AND retrieval_position > 0 AND retrieval_end_position > 0 AND retrieval_end_position > retrieval_position AND length(api_version) BETWEEN 1 AND 32 AND length(sdk_reference) = 40 AND (outcome <> 'settled' OR (line_period_start IS NOT NULL AND line_period_end IS NOT NULL AND line_period_start < line_period_end AND amount_minor IS NOT NULL AND amount_minor > 0 AND currency IS NOT NULL AND length(currency) = 3))";
        } elseif ($logical === self::TABLES[5]) {
            // Diagnostic attempts are deliberately not invoice identities or settlement observations.
            // Repeated attempts and different bindings may name the same invoice hash.
            $columns += ['subscription_binding_id' => $id, 'invoice_ref_hash' => $hash,
                'provider_account_hash' => $hash, 'mode' => $mode, 'provenance' => 'VARCHAR(32)'.$ascii.' NOT NULL',
                'hint_event_id' => 'VARCHAR(36)'.$ascii.' NULL', 'retrieval_position' => $position, 'retrieval_end_position' => $position,
                'outcome' => 'VARCHAR(16)'.$ascii.' NOT NULL', 'reason' => 'VARCHAR(64)'.$ascii.' NOT NULL'];
            $unique = [['retrieval_position'], ['retrieval_end_position']];
            $foreign = ['subscription_binding_id' => self::TABLES[0], 'hint_event_id' => self::TABLES[3]];
            $check .= ' AND length(invoice_ref_hash) = 64 AND length(provider_account_hash) = 64 AND (hint_event_id IS NULL OR length(hint_event_id) = 36)'
                .' AND retrieval_position > 0 AND retrieval_end_position > retrieval_position'
                ." AND ((mode = 'test' AND provenance = 'synthetic_rehearsal') OR (mode = 'live' AND provenance = 'verified_production'))"
                ." AND ((outcome = 'unknown' AND reason IN ('provider_unavailable','provider_inconsistent'))"
                ." OR (outcome = 'refused' AND reason IN ('provider_incomplete','binding_refused_account','binding_refused_invoice_identity','binding_refused_mode','binding_refused_customer','binding_refused_subscription')))";
        } else {
            $columns += ['provider_event_ref_hash' => $hash, 'type' => 'VARCHAR(64)'.$ascii.' NOT NULL', 'mode' => $mode,
                'provider_account_hash' => $hash, 'invoice_ref_hash' => 'VARCHAR(64)'.$ascii.' NULL', 'received_at' => $utc, 'hint_position' => $position,
                'payload_hash' => $hash, 'disposition' => 'VARCHAR(32)'.$ascii.' NOT NULL'];
            $unique = [['provider_event_ref_hash'], ['hint_position']];
            $check .= " AND hint_position > 0 AND length(provider_event_ref_hash) = 64 AND length(type) BETWEEN 1 AND 64 AND mode IN ('test','live') AND length(provider_account_hash) = 64 AND (invoice_ref_hash IS NULL OR length(invoice_ref_hash) = 64) AND length(payload_hash) = 64 AND disposition IN ('retrieval_hint','no_invoice_hint','ignored_type')";
        }
        if ($logical !== self::TABLES[4]) {
            $columns += ['payload_ciphertext' => $text.' NOT NULL', 'seal' => $hash, 'created_at' => $utc];
        }
        $quote = $driver === 'mysql' ? '`' : '"';
        $sql = implode(', ', array_map(fn ($column, $type) => $quote.$column.$quote.' '.$type, array_keys($columns), $columns));
        $sql .= ($primary === [] ? '' : ', PRIMARY KEY ('.implode(', ', $primary).')').', CONSTRAINT '.$quote.$name.'_bounds'.$quote.' CHECK ('.$check.')';
        foreach ($unique as $index => $parts) {
            $sql .= ', CONSTRAINT '.$quote.$name.'_u'.$index.$quote.' UNIQUE ('.implode(', ', $parts).')';
        }
        foreach (array_keys($foreign) as $index => $column) {
            $sql .= ', CONSTRAINT '.$quote.$name.'_f'.$index.$quote.' FOREIGN KEY ('.$column.') REFERENCES '.$quote.$this->physical($foreign[$column]).$quote.' (id) ON UPDATE RESTRICT ON DELETE RESTRICT';
        }

        return compact('sql', 'columns', 'primary', 'unique', 'check', 'foreign');
    }

    private function guards(string $logical, string $driver, string $name, string $table): array
    {
        $subscriptions = $this->table(self::TABLES[0]);
        $invoices = $this->table(self::TABLES[1]);
        $observations = $this->table(self::TABLES[2]);
        $positions = $this->table(self::TABLES[4]);
        $plans = $this->physical('production_membership_plan_versions');
        $insert = 'NOT EXISTS (SELECT 1 FROM '.$table.' WHERE id = NEW.id)';
        if ($logical === self::TABLES[0]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE subscription_ref_hash = NEW.subscription_ref_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$plans.' WHERE id = NEW.plan_version_id AND provenance = NEW.provenance)'
                .' AND EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id = a.user_id WHERE a.id = NEW.account_id AND u.id = NEW.user_id AND a.active = 1 AND u.is_admin = 0 AND u.email_verified_at IS NOT NULL)';
        } elseif ($logical === self::TABLES[1]) {
            // One point lookup per unique index (Codex P2 on PR #54, `BillingLedger.php:173`). A trigger subquery inside an INSERT is
            // a locking read on MySQL; a single `invoice_ref_hash = ? OR source_invoice_hash = ?` predicate read across the rows,
            // including an identity row another invoice's append holds FOR UPDATE, so an unrelated first claim waited for that lock.
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE invoice_ref_hash = NEW.invoice_ref_hash)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE source_invoice_hash = NEW.source_invoice_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$subscriptions.' WHERE id = NEW.subscription_binding_id AND provider_account_hash = NEW.provider_account_hash AND mode = NEW.mode)';
        } elseif ($logical === self::TABLES[2]) {
            // A retrieval is bracketed by two database-issued positions: its start, committed before the first provider read, and
            // its end, committed after the last (Codex P1 on PR #54, `BillingReconciliation.php:42`). Both must exist with kind
            // `retrieval`, be ordered, and back no other observation as either its start or its end.
            $insert .= ' AND EXISTS (SELECT 1 FROM '.$invoices.' WHERE id = NEW.invoice_id)'
                .' AND EXISTS (SELECT 1 FROM '.$positions." WHERE id = NEW.retrieval_position AND kind = 'retrieval')"
                .' AND EXISTS (SELECT 1 FROM '.$positions." WHERE id = NEW.retrieval_end_position AND kind = 'retrieval')"
                .' AND NEW.retrieval_end_position > NEW.retrieval_position'
                // One equality per unique index and value, never an IN list or OR: each is a point lookup, so the guard's locking reads
                // stay on the keys it checks (Codex P2 on PR #54, `BillingLedger.php:173`).
                .' AND NOT EXISTS (SELECT 1 FROM '.$observations.' WHERE retrieval_position = NEW.retrieval_position)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$observations.' WHERE retrieval_position = NEW.retrieval_end_position)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$observations.' WHERE retrieval_end_position = NEW.retrieval_position)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$observations.' WHERE retrieval_end_position = NEW.retrieval_end_position)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$observations.' WHERE invoice_id = NEW.invoice_id AND sequence = NEW.sequence)'
                .' AND NEW.sequence = COALESCE((SELECT MAX(sequence) FROM '.$observations.' WHERE invoice_id = NEW.invoice_id), 0) + 1'
                ." AND ((NEW.sequence = 1 AND NEW.prior_seal = '".str_repeat('0', 64)."')"
                .' OR EXISTS (SELECT 1 FROM '.$observations.' o WHERE o.invoice_id = NEW.invoice_id AND o.sequence = NEW.sequence - 1 AND o.seal = NEW.prior_seal AND o.created_at <= NEW.created_at'
                // Only a read that began after the tail's read ended can become the tail (review R-6). A start position allocated before
                // a read does not order the reads themselves, so the new start is compared with the tail's END: an overlapping read may
                // have seen older provider state than the tail. Positions, never a worker clock (Codex P1 on PR #54, L2-3 and :42).
                .' AND o.retrieval_end_position < NEW.retrieval_position))';
        } elseif ($logical === self::TABLES[3]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE provider_event_ref_hash = NEW.provider_event_ref_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$positions." WHERE id = NEW.hint_position AND kind = 'hint')"
                .' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE hint_position = NEW.hint_position)';
        } elseif ($logical === self::TABLES[5]) {
            $events = $this->table(self::TABLES[3]);
            $insert .= ' AND EXISTS (SELECT 1 FROM '.$subscriptions.' WHERE id = NEW.subscription_binding_id AND provider_account_hash = NEW.provider_account_hash AND mode = NEW.mode AND provenance = NEW.provenance)'
                .' AND EXISTS (SELECT 1 FROM '.$positions." WHERE id = NEW.retrieval_position AND kind = 'retrieval')"
                .' AND EXISTS (SELECT 1 FROM '.$positions." WHERE id = NEW.retrieval_end_position AND kind = 'retrieval')"
                .' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE retrieval_position = NEW.retrieval_position)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE retrieval_position = NEW.retrieval_end_position)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE retrieval_end_position = NEW.retrieval_position)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE retrieval_end_position = NEW.retrieval_end_position)'
                .' AND (NEW.hint_event_id IS NULL OR EXISTS (SELECT 1 FROM '.$events." WHERE id = NEW.hint_event_id AND disposition = 'retrieval_hint'"
                .' AND provider_account_hash = NEW.provider_account_hash AND mode = NEW.mode AND invoice_ref_hash = NEW.invoice_ref_hash AND hint_position < NEW.retrieval_position))';
        } else {
            // Only the database allocates a position: before the insert an unassigned AUTO_INCREMENT id reads 0 on MySQL and -1 on
            // SQLite, while an explicit id (which could back-date a position into a gap) reads as itself and is refused.
            $insert = 'NEW.id < 1';
        }
        $result = [];
        foreach (['insert' => $insert, 'update' => '0 = 1', 'delete' => '0 = 1'] as $event => $condition) {
            $guard = $name.'_'.$event;
            $condition = $driver === 'sqlite' ? str_replace('main.', '', $condition) : $condition;
            $body = "BEGIN IF NOT COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain production membership billing evidence'; END IF; END";
            $sql = $driver === 'mysql' ? 'CREATE TRIGGER `'.$guard.'` BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW '.$body
                : 'CREATE TRIGGER "'.$guard.'" BEFORE '.strtoupper($event).' ON '.$table.' WHEN NOT COALESCE(('.$condition."),0) BEGIN SELECT RAISE(ABORT, 'Retain production membership billing evidence'); END";
            $result[$guard] = ['sql' => $sql, 'event' => strtoupper($event), 'body' => $body];
        }

        if (in_array($logical, [self::TABLES[2], self::TABLES[5]], true)) {
            $guard = $name.'_interval';
            $result[$guard] = $this->intervalGuard($driver, $guard, $table);
        }

        return $result;
    }

    /** Both raw insert paths lock the same positions, then current-read the opposite ledger. */
    private function intervalGuard(string $driver, string $guard, string $table): array
    {
        $positions = $this->table(self::TABLES[4]);
        $queries = [];
        foreach ([self::TABLES[2], self::TABLES[5]] as $logical) {
            $queries[] = 'SELECT COUNT(*) FROM '.$this->table($logical).' WHERE retrieval_position IN (NEW.retrieval_position, NEW.retrieval_end_position) OR retrieval_end_position IN (NEW.retrieval_position, NEW.retrieval_end_position)';
        }
        $valid = 'NEW.retrieval_position > 0 AND NEW.retrieval_end_position > NEW.retrieval_position';
        if ($driver === 'mysql') {
            $body = "BEGIN DECLARE found_kind VARCHAR(16) DEFAULT NULL; DECLARE used_positions BIGINT DEFAULT 0; IF NOT COALESCE(({$valid}),0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain billing retrieval interval'; END IF; "
                .'SELECT kind INTO found_kind FROM '.$positions.' WHERE id = NEW.retrieval_position FOR UPDATE; '
                ."IF found_kind IS NULL OR found_kind <> 'retrieval' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain billing retrieval interval'; END IF; SET found_kind = NULL; "
                .'SELECT kind INTO found_kind FROM '.$positions.' WHERE id = NEW.retrieval_end_position FOR UPDATE; '
                ."IF found_kind IS NULL OR found_kind <> 'retrieval' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain billing retrieval interval'; END IF; ";
            $ownIndex = $table === $this->table(self::TABLES[2]) ? 0 : 1;
            foreach ($queries as $index => $query) {
                // MySQL forbids a trigger's locking read of its own subject table (1442).
                // Existing same-ledger guards/unique keys remain; the new current read
                // closes cross-ledger reuse, serialized by the shared position locks.
                if ($index === $ownIndex) {
                    continue;
                }
                $body .= str_replace('COUNT(*) FROM', 'COUNT(*) INTO used_positions FROM', $query).' FOR UPDATE; '
                    ."IF used_positions <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain billing retrieval interval'; END IF; ";
            }
            $body .= 'END';
            $sql = 'CREATE TRIGGER `'.$guard.'` BEFORE INSERT ON '.$table.' FOR EACH ROW '.$body;
        } else {
            $condition = $valid.' AND ('.$queries[0].') = 0 AND ('.$queries[1].') = 0';
            $condition = str_replace('main.', '', $condition);
            $body = '';
            $sql = 'CREATE TRIGGER "'.$guard.'" BEFORE INSERT ON '.$table.' WHEN NOT COALESCE(('.$condition."),0) BEGIN SELECT RAISE(ABORT, 'Retain billing retrieval interval'); END";
        }

        return ['sql' => $sql, 'event' => 'INSERT', 'body' => $body];
    }

    /** Offline forward upgrade: no row is deleted, adopted or resealed; exact existing guards are required. */
    public function upgradeIntervals(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        BillingException::require($connection->transactionLevel() === 0 && ! $pdo->inTransaction(), 'migration_transaction');
        $this->inspect(false, true);
        // SQLite DDL is transactional. Native DDL is not: both table write locks stay held
        // while validating retained intervals and installing the two supplementary guards.
        if ($driver === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->exec('LOCK TABLES '.implode(', ', array_map(fn ($logical) => $this->table($logical).' WRITE', self::TABLES)));
        }
        try {
            // Reinspect after acquiring locks, closing the pre-lock metadata window.
            $this->inspect(false, true, $driver === 'mysql');
            $this->assertRetainedIntervals($pdo);
            foreach ([self::TABLES[2], self::TABLES[5]] as $logical) {
                $table = $this->table($logical);
                $name = $connection->getTablePrefix().$logical.'_interval';
                $statement = $pdo->prepare($driver === 'sqlite'
                    ? "SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND name = ?"
                    : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND BINARY TRIGGER_NAME = BINARY ?');
                $statement->execute([$name]);
                if ($statement->fetchColumn() === false) {
                    $pdo->exec($this->intervalGuard($driver, $name, $table)['sql']);
                }
            }
            $this->assertRetainedIntervals($pdo);
            if ($driver === 'sqlite') {
                $pdo->exec('COMMIT');
            }
        } finally {
            if ($driver === 'sqlite') {
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK');
                }
            } else {
                $pdo->exec('UNLOCK TABLES');
            }
        }
        $this->up(false);
    }

    private function assertRetainedIntervals(PDO $pdo): void
    {
        $parts = [];
        foreach ([self::TABLES[2], self::TABLES[5]] as $logical) {
            foreach (['retrieval_position', 'retrieval_end_position'] as $column) {
                $parts[] = 'SELECT '.$column.' AS position_id FROM '.$this->table($logical);
            }
        }
        $query = 'SELECT position_id FROM ('.implode(' UNION ALL ', $parts).') retained_intervals GROUP BY position_id HAVING COUNT(*) > 1 LIMIT 1';
        BillingException::require($pdo->query($query)->fetchColumn() === false, 'retained_interval_conflict');
    }

    private function assertNamespace(PDO $pdo, string $driver, bool $billingTablesLocked = false, ?MembershipSchemaCatalog $catalog = null): void
    {
        $reserved = [];
        foreach (self::TABLES as $logical) {
            $name = DB::connection()->getTablePrefix().$logical;
            $this->table($logical);
            $reserved[strtolower($name)] = ['name' => $name, 'type' => 'table'];
            $events = in_array($logical, [self::TABLES[2], self::TABLES[5]], true) ? ['insert', 'update', 'delete', 'interval'] : ['insert', 'update', 'delete'];
            foreach ($events as $event) {
                $guard = $name.'_'.$event;
                $reserved[strtolower($guard)] = ['name' => $guard, 'type' => 'trigger'];
            }
        }
        if ($driver === 'sqlite') {
            // Inline non-integer primary keys create schema-global autoindexes. Their
            // identity belongs to the owned table, unlike SQLite's named CHECK/FK clauses.
            $indexes = [];
            foreach (self::TABLES as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                for ($index = 1; $index <= $this->autoindexes($logical, $driver); $index++) {
                    $symbol = 'sqlite_autoindex_'.$name.'_'.$index;
                    $indexes[strtolower($symbol)] = ['name' => $symbol, 'type' => 'index', 'tbl_name' => $name, 'sql' => null];
                }
            }
            $objects = $pdo->query('SELECT name, type, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            $seen = [];
            foreach ($objects as $object) {
                $key = strtolower($object['name']);
                if (isset($indexes[$key])) {
                    BillingException::require(! isset($seen[$key]) && $indexes[$key] === $object, 'schema_namespace');
                    $seen[$key] = true;
                }
                $rows[] = ['name' => $object['name'], 'type' => $object['type']];
            }
        } else {
            // CHECK and FK symbols are schema-global in distinct native namespaces.
            // Query with the dictionary's name collation, then require exact bytes and
            // ownership. A foreign table-local index/unique name is allowed to coexist.
            $namespaceParameters = $this->mysqlNamespaceParameters();
            foreach (self::TABLES as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                $definition = $this->definition($logical, $driver);
                $constraints = [$name.'_bounds' => 'CHECK'];
                foreach (array_keys($definition['foreign']) as $index => $column) {
                    $constraints[$name.'_f'.$index] = 'FOREIGN KEY';
                }
                foreach ($constraints as $symbol => $type) {
                    $objects = $catalog->rows('SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?', $namespaceParameters, $symbol);
                    BillingException::require($objects === [] || $objects === [['CONSTRAINT_NAME' => $symbol, 'TABLE_NAME' => $name, 'CONSTRAINT_TYPE' => $type]], 'schema_namespace');
                }
            }
            $rows = $pdo->query("SELECT TABLE_NAME AS name, 'table' AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_SCHEMA = BINARY DATABASE() UNION ALL SELECT TRIGGER_NAME AS name, 'trigger' AS type FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND BINARY TRIGGER_SCHEMA = BINARY DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reserved as $expected) {
                // The same connection was checked before LOCK TABLES. Probing a nonexistent
                // table named after a trigger is illegal while table locks are held; no
                // callback runs between these checks, so session-local shadows cannot appear.
                if ($expected['type'] === 'trigger' && ! $billingTablesLocked) {
                    try {
                        $pdo->query('SHOW CREATE TABLE `'.$expected['name'].'`')->fetchAll();
                        throw new BillingException('schema_namespace');
                    } catch (\PDOException $error) {
                        if ($error->getCode() !== '42S02') {
                            throw $error;
                        }
                    }
                }
            }
        }
        $seen = [];
        foreach ($rows as $row) {
            $key = strtolower($row['name']);
            if (isset($reserved[$key])) {
                BillingException::require(! isset($seen[$key]) && $reserved[$key] === $row, 'schema_namespace');
                $seen[$key] = true;
            }
        }
    }

    /** SQLite autoindexes of one owned table: one per UNIQUE, plus one for a non-rowid primary key. */
    private function autoindexes(string $logical, string $driver): int
    {
        $definition = $this->definition($logical, $driver);

        return ($definition['primary'] === [] ? 0 : 1) + count($definition['unique']);
    }

    private function mysqlNames(): array
    {
        return array_map($this->physical(...), self::TABLES);
    }

    private function mysqlGuardNames(): array
    {
        $names = [];
        foreach (self::TABLES as $logical) {
            $events = in_array($logical, [self::TABLES[2], self::TABLES[5]], true) ? ['insert', 'update', 'delete', 'interval'] : ['insert', 'update', 'delete'];
            foreach ($events as $event) {
                $names[] = $this->physical($logical).'_'.$event;
            }
        }

        return $names;
    }

    private function mysqlForeignParameters(): array
    {
        $parameters = [];
        foreach (self::TABLES as $logical) {
            $name = $this->physical($logical);
            foreach (array_keys($this->definition($logical, 'mysql')['foreign']) as $index => $column) {
                $parameters[$name.'_f'.$index] = [$name, $name.'_f'.$index];
            }
        }

        return $parameters;
    }

    private function mysqlNamespaceParameters(): array
    {
        $parameters = [];
        foreach ($this->mysqlNames() as $name) {
            $parameters[$name.'_bounds'] = ['CHECK', $name.'_bounds'];
        }
        foreach (array_keys($this->mysqlForeignParameters()) as $name) {
            $parameters[$name] = ['FOREIGN KEY', $name];
        }

        return $parameters;
    }

    private function mysqlDefinition(PDO $pdo, string $name, array $definition, MembershipSchemaCatalog $catalog): void
    {
        $columns = $catalog->named('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', $this->mysqlNames(), $name);
        BillingException::require(array_column($columns, 'COLUMN_NAME') === array_keys($definition['columns']), 'schema');
        foreach ($columns as $column) {
            $expected = $definition['columns'][$column['COLUMN_NAME']];
            BillingException::require($column['COLUMN_TYPE'] === (str_starts_with($expected, 'INTEGER') ? 'int' : (str_starts_with($expected, 'BIGINT UNSIGNED') ? 'bigint unsigned' : strtolower(explode(' ', $expected)[0]))) && $column['COLUMN_DEFAULT'] === null && $column['EXTRA'] === (str_contains($expected, 'AUTO_INCREMENT') ? 'auto_increment' : '')
                && $column['IS_NULLABLE'] === (str_contains($expected, 'NOT NULL') ? 'NO' : 'YES')
                && $column['COLLATION_NAME'] === (str_contains($expected, 'ascii_bin') ? 'ascii_bin' : (str_contains($expected, 'utf8mb4_bin') ? 'utf8mb4_bin' : null)), 'schema');
        }
        $indexes = $pdo->query('SHOW INDEX FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($indexes as $index) {
            BillingException::require($index['Index_type'] === 'BTREE' && $index['Sub_part'] === null && $index['Non_unique'] == ($index['Key_name'] === 'PRIMARY' || preg_match('/_u[0-9]+\z/D', $index['Key_name']) === 1 ? 0 : 1), 'schema');
            $actual[$index['Key_name']][] = $index['Column_name'];
        }
        $wanted = ['PRIMARY' => $definition['primary']];
        foreach ($definition['unique'] as $index => $parts) {
            $wanted[$name.'_u'.$index] = $parts;
        }
        foreach (array_keys($definition['foreign']) as $index => $column) {
            if (! array_any($wanted, fn ($parts): bool => $parts[0] === $column)) {
                $wanted[$name.'_f'.$index] = [$column];
            }
        }
        ksort($actual);
        ksort($wanted);
        BillingException::require($actual === $wanted, 'schema');
        $constraints = $catalog->named('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ?', $this->mysqlNames(), $name);
        $actual = array_column($constraints, 'CONSTRAINT_TYPE', 'CONSTRAINT_NAME');
        $wanted = ['PRIMARY' => 'PRIMARY KEY', $name.'_bounds' => 'CHECK'];
        foreach ($definition['unique'] as $index => $parts) {
            $wanted[$name.'_u'.$index] = 'UNIQUE';
        }
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $foreignParameters = $this->mysqlForeignParameters();
        foreach (array_keys($definition['foreign']) as $index => $column) {
            $target = $definition['foreign'][$column];
            $wanted[$name.'_f'.$index] = 'FOREIGN KEY';
            $foreign = $catalog->rows('SELECT COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?', $foreignParameters, $name.'_f'.$index);
            BillingException::require($foreign === [['COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $schema, 'REFERENCED_TABLE_NAME' => $this->physical($target), 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1]], 'schema');
            $foreign = $catalog->rows('SELECT UNIQUE_CONSTRAINT_SCHEMA, UNIQUE_CONSTRAINT_NAME, REFERENCED_TABLE_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?', $foreignParameters, $name.'_f'.$index);
            BillingException::require($foreign === [['UNIQUE_CONSTRAINT_SCHEMA' => $schema, 'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY', 'REFERENCED_TABLE_NAME' => $this->physical($target), 'MATCH_OPTION' => 'NONE', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT']], 'schema');
        }
        ksort($actual);
        ksort($wanted);
        BillingException::require($actual === $wanted, 'schema');
        $checks = array_combine($this->mysqlNames(), array_map(fn (string $table): array => [$table, $table.'_bounds'], $this->mysqlNames()));
        $rows = $catalog->rows("SELECT c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME AND BINARY t.CONSTRAINT_SCHEMA = BINARY c.CONSTRAINT_SCHEMA AND BINARY t.CONSTRAINT_NAME = BINARY c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA = DATABASE() AND BINARY t.TABLE_SCHEMA = BINARY DATABASE() AND BINARY t.TABLE_NAME = BINARY ? AND t.CONSTRAINT_TYPE = 'CHECK' AND t.CONSTRAINT_NAME = ?", $checks, $name);
        BillingException::require(count($rows) === 1 && $rows[0]['ENFORCED'] === 'YES' && $this->check($rows[0]['CHECK_CLAUSE']) === $this->check($definition['check']), 'schema');
    }

    private function sql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql), ' ;');
    }

    private function check(string $sql): string
    {
        // Native MySQL dictionaries introduce/escape these exact ASCII enum literals.
        // Admit only the owned literal spellings, never arbitrary charset or SQL rewriting.
        foreach (['synthetic_rehearsal', 'verified_production', 'test', 'live', 'settled', 'not_settled', 'unknown', 'refused', 'reversed',
            'retrieval_hint', 'no_invoice_hint', 'ignored_type', 'retrieval', 'hint',
            'provider_unavailable', 'provider_inconsistent', 'provider_incomplete', 'binding_refused_account',
            'binding_refused_invoice_identity', 'binding_refused_mode', 'binding_refused_customer', 'binding_refused_subscription'] as $literal) {
            $sql = str_replace("_utf8mb4\\'".$literal."\\'", "'".$literal."'", $sql);
        }

        return strtolower(preg_replace('/[\s`()]+/', '', $sql));
    }
}
