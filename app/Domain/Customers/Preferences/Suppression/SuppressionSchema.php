<?php

namespace App\Domain\Customers\Preferences\Suppression;

use App\Domain\Customers\Preferences\ConsentMigrationAdmission;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use ReflectionMethod;

final class SuppressionSchema
{
    public const TABLES = ['customer_suppression_targets', 'customer_suppression_intents', 'customer_suppression_attempts', 'customer_suppression_confirmations'];

    public function up(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $database = $connection->getDatabaseName();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $connection->getTablePrefix() !== ''
            || ($driver === 'mysql' && $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database)) {
            throw new LogicException('Suppression storage requires its supported unprefixed database.');
        }
        $steps = [];
        foreach (self::TABLES as $table) {
            $this->refuseShadow($pdo, $driver, $table);
            $steps[] = ['table', $table, $this->definition($driver, $table)];
        }
        foreach ($this->triggers($driver) as $name => $trigger) {
            $steps[] = ['trigger', $name, $trigger['sql']];
        }
        $namespace = [];
        foreach (self::TABLES as $table) {
            $namespace[$table] = ['table', $table];
        }
        foreach ($this->triggers($driver) as $name => $trigger) {
            $namespace[$name] = ['trigger', $trigger['table']];
        }
        $admission = new ConsentMigrationAdmission($pdo, $driver, $database);
        $this->namespace($pdo, $driver, $database, $admission, $namespace + ['migrations' => ['table', 'migrations']]);
        $this->dependencies($pdo, $driver, $database, $admission);
        $known = array_keys($this->triggers($driver));
        foreach (self::TABLES as $table) {
            $statement = $pdo->prepare($driver === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=?" : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');
            $statement->execute([$table]);
            if (array_diff($statement->fetchAll(PDO::FETCH_COLUMN), $known) !== []) {
                throw new LogicException('Unexpected suppression trigger requires inspection; nothing was changed.');
            }
        }
        // Prove every retained object and a contiguous known installation prefix BEFORE DDL.
        $this->refuseShadow($pdo, $driver, 'migrations');
        $recorded = false;
        if ($this->exists($pdo, $driver, 'table', 'migrations')) {
            $repository = $driver === 'sqlite' ? 'main."migrations"' : '`'.str_replace('`', '``', $database).'`.`migrations`';
            $statement = $pdo->prepare('SELECT COUNT(*) FROM '.$repository.' WHERE migration=?');
            $statement->execute(['2026_10_07_251000_customer_suppression']);
            $recorded = (int) $statement->fetchColumn() !== 0;
        }
        $missing = false;
        foreach ($steps as [$type, $name, $sql]) {
            $exists = $this->exists($pdo, $driver, $type, $name);
            if ($exists && $missing) {
                throw new LogicException('Non-prefix suppression installation requires inspection; nothing was changed.');
            }
            if ($exists) {
                $this->owned($pdo, $driver, $type, $name, $sql);
            } else {
                if ($recorded) {
                    throw new LogicException('Recorded suppression schema is incomplete; inspection is required before any DDL.');
                }
                $missing = true;
            }
        }
        foreach ($steps as [$type, $name, $sql]) {
            if (! $this->exists($pdo, $driver, $type, $name)) {
                DB::unprepared($sql);
            }
            if (DB::connection() !== $connection || $connection->getPdo() !== $pdo || $connection->getDatabaseName() !== $database
                || ($driver === 'mysql' && $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database)) {
                throw new LogicException('Suppression installation changed its captured database.');
            }
            foreach (self::TABLES as $table) {
                $this->refuseShadow($pdo, $driver, $table);
            }
            $this->owned($pdo, $driver, $type, $name, $sql);
        }
        // The last framework DDL callback can alter an earlier object or dependency.
        // Reassert the complete captured schema with raw PDO before allowing bookkeeping.
        $this->namespace($pdo, $driver, $database, $admission, $namespace + ['migrations' => ['table', 'migrations']]);
        $this->dependencies($pdo, $driver, $database, $admission);
        foreach ($steps as [$type, $name, $sql]) {
            $this->owned($pdo, $driver, $type, $name, $sql);
        }
        foreach (self::TABLES as $table) {
            $statement = $pdo->prepare($driver === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name" : 'SELECT TRIGGER_NAME name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME');
            $statement->execute([$table]);
            $expected = array_keys(array_filter($this->triggers($driver), fn ($guard) => $guard['table'] === $table));
            sort($expected);
            if ($statement->fetchAll(PDO::FETCH_COLUMN) !== $expected) {
                throw new LogicException('Unexpected suppression guards require inspection.');
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Suppression rollback is refused; retain its schema, evidence and migration record.');
    }

    private function columns(string $table): array
    {
        $id = ['bigint unsigned', 'integer', false, true];
        $key = ['bigint unsigned', 'integer'];
        $uuid = ['char(36)', 'text'];
        $hash = ['char(64)', 'text'];
        $cipher = ['text', 'text'];
        $time = ['timestamp', 'datetime'];

        return match ($table) {
            'customer_suppression_targets' => ['id' => $id, 'public_id' => $uuid, 'customer_account_id' => $key, 'purpose' => ['varchar(40)', 'text'], 'recipient_hmac' => $hash, 'recipient_ciphertext' => $cipher, 'created_at' => $time],
            'customer_suppression_intents' => ['id' => $id, 'public_id' => $uuid, 'consent_event_id' => $key, 'target_id' => $key, 'created_at' => $time],
            'customer_suppression_attempts' => ['id' => $id, 'public_id' => $uuid, 'target_id' => $key, 'account_access_version' => ['int unsigned', 'integer'], 'binding_hash' => $hash, 'binding_ciphertext' => $cipher, 'request_hash' => $hash, 'created_at' => $time],
            'customer_suppression_confirmations' => ['id' => $id, 'attempt_id' => $key, 'request_hash' => $hash, 'receipt_hash' => $hash, 'receipt_ciphertext' => $cipher, 'created_at' => $time],
        };
    }

    private function indexes(string $table): array
    {
        return match ($table) {
            'customer_suppression_targets' => [$table.'_public_unique' => [['public_id'], true], $table.'_recipient_unique' => [['customer_account_id', 'purpose', 'recipient_hmac'], true]],
            'customer_suppression_intents' => [$table.'_public_unique' => [['public_id'], true], $table.'_event_unique' => [['consent_event_id'], true], $table.'_target_index' => [['target_id'], false]],
            'customer_suppression_attempts' => [$table.'_public_unique' => [['public_id'], true], $table.'_target_unique' => [['target_id'], true]],
            'customer_suppression_confirmations' => [$table.'_attempt_unique' => [['attempt_id'], true]],
        };
    }

    private function foreign(string $table): array
    {
        return match ($table) {
            'customer_suppression_targets' => ['customer_account_id' => 'customer_accounts'],
            'customer_suppression_intents' => ['consent_event_id' => 'customer_consent_events', 'target_id' => 'customer_suppression_targets'],
            'customer_suppression_attempts' => ['target_id' => 'customer_suppression_targets'],
            'customer_suppression_confirmations' => ['attempt_id' => 'customer_suppression_attempts'],
        };
    }

    private function namespace(PDO $pdo, string $driver, string $database, ConsentMigrationAdmission $admission, array $namespace): void
    {
        $admission->namespace($namespace);
        foreach (self::TABLES as $table) {
            foreach ([...array_keys($this->indexes($table)), ...array_map(fn ($column) => $table.'_'.$column.'_fk', array_keys($this->foreign($table)))] as $name) {
                if ($driver === 'sqlite') {
                    foreach (['main.sqlite_master', 'sqlite_temp_master'] as $dictionary) {
                        $statement = $pdo->prepare('SELECT name FROM '.$dictionary.' WHERE name COLLATE NOCASE=?');
                        $statement->execute([$name]);
                        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== []) {
                            throw new LogicException('Reserved suppression key collision.');
                        }
                    }

                    continue;
                }
                foreach ([['TABLES', 'TABLE_SCHEMA', 'TABLE_NAME'], ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME'], ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME']] as [$dictionary,$schema,$column]) {
                    $statement = $pdo->prepare('SELECT '.$column.' FROM information_schema.'.$dictionary.' WHERE '.$schema.'=DATABASE() AND '.$column.'=?');
                    $statement->execute([$name]);
                    if ($statement->fetchAll(PDO::FETCH_ASSOC) !== []) {
                        throw new LogicException('Reserved suppression key collision.');
                    }
                }
                try {
                    $pdo->query('SHOW CREATE TABLE `'.str_replace('`', '``', $database).'`.`'.$name.'`');
                    throw new LogicException('Temporary suppression key collision.');
                } catch (PDOException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
                foreach ([['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME']] as [$dictionary,$schema,$column]) {
                    // Compare native dictionary names/aliases directly, preserving every row and owner.
                    $statement = $pdo->prepare('SELECT TABLE_NAME,'.$column.' name FROM information_schema.'.$dictionary.' WHERE '.$schema.'=DATABASE() AND '.$column.'=?');
                    $statement->execute([$name]);
                    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        if ($row !== ['TABLE_NAME' => $table, 'name' => $name] || ! $this->exists($pdo, $driver, 'table', $table)) {
                            throw new LogicException('Foreign or aliased suppression key requires inspection.');
                        }
                    }
                }
            }
        }
    }

    private function dependencies(PDO $pdo, string $driver, string $database, ConsentMigrationAdmission $admission): void
    {
        $admission->dependencies();
        // Read-only exact ownership proof of the approved predecessor; never invoke its DDL.
        $prior = require database_path('migrations/2026_10_07_250000_customer_consent.php');
        $triggers = (new ReflectionMethod($prior, 'triggers'))->invoke($prior, $driver);
        $namespace = [];
        foreach (['customer_consent_policies', 'customer_consent_events', 'customer_consent_states'] as $table) {
            $namespace[$table] = ['table', $table];
        }
        foreach ($triggers as $name => $guard) {
            $namespace[$name] = ['trigger', $guard['table']];
        }
        $admission->namespace($namespace);
        foreach ($namespace as $name => [$type,$owner]) {
            $sql = $type === 'table' ? (new ReflectionMethod($prior, 'definition'))->invoke($prior, $driver, $name) : $triggers[$name]['sql'];
            (new ReflectionMethod($prior, 'owned'))->invoke($prior, $pdo, $driver, $type, $name, $sql);
        }
        foreach (['customer_consent_policies', 'customer_consent_events', 'customer_consent_states'] as $table) {
            $statement = $pdo->prepare($driver === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name" : 'SELECT TRIGGER_NAME name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME');
            $statement->execute([$table]);
            $expected = array_keys(array_filter($triggers, fn ($guard) => $guard['table'] === $table));
            sort($expected);
            if ($statement->fetchAll(PDO::FETCH_COLUMN) !== $expected) {
                throw new LogicException('Suppression dependency guards require inspection.');
            }
        }
    }

    private function definition(string $driver, string $table): string
    {
        $parts = [];
        foreach ($this->columns($table) as $name => $definition) {
            [$mysql, $sqlite] = $definition;
            $nullable = $definition[2] ?? false;
            $primary = $definition[3] ?? false;
            if ($driver === 'sqlite') {
                $parts[] = '"'.$name.'" '.$sqlite.($primary ? ' primary key autoincrement' : '').($nullable ? ' null' : ' not null');
            } else {
                $text = str_starts_with($mysql, 'varchar') || str_starts_with($mysql, 'char') || $mysql === 'text';
                $collation = $text ? (($definition[4] ?? false) ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : ' CHARACTER SET ascii COLLATE ascii_bin') : '';
                $parts[] = '`'.$name.'` '.$mysql.$collation.($nullable ? ' NULL DEFAULT NULL' : ' NOT NULL').($primary ? ' AUTO_INCREMENT' : '');
            }
        }
        if ($driver === 'mysql') {
            $parts[] = 'PRIMARY KEY (`id`)';
        }
        foreach ($this->indexes($table) as $name => [$columns, $unique]) {
            $quoted = implode(', ', array_map(fn ($column) => '`'.$column.'`', $columns));
            if ($driver === 'sqlite' && ! $unique) {
                // A redundant nonunique FK index is unnecessary on SQLite; exact ownership reflects that.
                continue;
            }
            $parts[] = $driver === 'mysql' ? ($unique ? 'UNIQUE KEY ' : 'KEY ').'`'.$name.'` ('.$quoted.')'
                : 'CONSTRAINT "'.$name.'" UNIQUE ('.$quoted.')';
        }
        foreach ($this->foreign($table) as $column => $target) {
            $parts[] = 'CONSTRAINT `'.$table.'_'.$column.'_fk` FOREIGN KEY (`'.$column.'`) REFERENCES `'.$target.'` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT';
        }

        return 'CREATE TABLE '.($driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`').' ('.implode(', ', $parts).')'.($driver === 'mysql' ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VA retained customer suppression v1'" : '');
    }

    private function triggers(string $driver): array
    {
        $hex = fn ($c) => $driver === 'sqlite' ? "length(NEW.$c)=64 AND NEW.$c NOT GLOB '*[^a-f0-9]*'" : "CHAR_LENGTH(NEW.$c)=64 AND NEW.$c REGEXP '^[a-f0-9]{64}$'";
        $bytes = fn ($c) => ($driver === 'sqlite' ? 'length(CAST(NEW.'.$c.' AS BLOB))' : 'LENGTH(NEW.'.$c.')');
        $uuid = fn ($c) => ($driver === 'sqlite' ? 'length' : 'CHAR_LENGTH').'(NEW.'.$c.')=36';
        $active = 'EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id WHERE a.id=NEW.customer_account_id AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)';
        $target = "NEW.purpose='email_marketing' AND ".$uuid('public_id').' AND '.$hex('recipient_hmac').' AND '.$bytes('recipient_ciphertext').' BETWEEN 1 AND 4096 AND '.$active
            .' AND NOT EXISTS (SELECT 1 FROM customer_suppression_targets WHERE id=NEW.id OR public_id=NEW.public_id OR (customer_account_id=NEW.customer_account_id AND purpose=NEW.purpose AND recipient_hmac=NEW.recipient_hmac))';
        $intent = $uuid('public_id')." AND EXISTS (SELECT 1 FROM customer_suppression_targets t JOIN customer_consent_events e ON e.id=NEW.consent_event_id JOIN customer_consent_states s ON s.customer_account_id=e.customer_account_id AND s.purpose=e.purpose WHERE t.id=NEW.target_id AND e.customer_account_id=t.customer_account_id AND e.purpose=t.purpose AND e.recipient_hmac=t.recipient_hmac AND e.status='withdrawn' AND e.affirmative=0 AND e.created_at=NEW.created_at AND e.created_at>=t.created_at AND s.event_id=e.id AND s.revision=e.revision)"
            .' AND NOT EXISTS (SELECT 1 FROM customer_suppression_intents WHERE id=NEW.id OR public_id=NEW.public_id OR consent_event_id=NEW.consent_event_id)';
        $attempt = $uuid('public_id').' AND '.$hex('binding_hash').' AND '.$hex('request_hash').' AND '.$bytes('binding_ciphertext').' BETWEEN 1 AND 4096 AND NEW.account_access_version BETWEEN 1 AND 4294967294'
            .' AND EXISTS (SELECT 1 FROM customer_suppression_targets t JOIN customer_accounts a ON a.id=t.customer_account_id JOIN users u ON u.id=a.user_id WHERE t.id=NEW.target_id AND a.active=1 AND a.access_version=NEW.account_access_version AND u.is_admin=0 AND u.email_verified_at IS NOT NULL AND NEW.created_at>=t.created_at)'
            .' AND EXISTS (SELECT 1 FROM customer_suppression_intents WHERE target_id=NEW.target_id)'
            .' AND NOT EXISTS (SELECT 1 FROM customer_suppression_attempts WHERE id=NEW.id OR public_id=NEW.public_id OR target_id=NEW.target_id)';
        $confirmation = $hex('request_hash').' AND '.$hex('receipt_hash').' AND '.$bytes('receipt_ciphertext').' BETWEEN 1 AND 4096'
            .' AND EXISTS (SELECT 1 FROM customer_suppression_attempts WHERE id=NEW.attempt_id AND request_hash=NEW.request_hash AND NEW.created_at>=created_at)'
            .' AND NOT EXISTS (SELECT 1 FROM customer_suppression_confirmations WHERE id=NEW.id OR attempt_id=NEW.attempt_id)';
        $result = [];
        foreach (['customer_suppression_targets' => $target, 'customer_suppression_intents' => $intent, 'customer_suppression_attempts' => $attempt, 'customer_suppression_confirmations' => $confirmation] as $table => $insert) {
            foreach (['insert' => $insert, 'update' => '0=1', 'delete' => '0=1'] as $event => $condition) {
                $name = $table.'_retain_'.$event;
                $body = $driver === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Retained suppression graph refused') WHERE NOT ($condition); END" : "BEGIN IF NOT ($condition) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained suppression graph refused'; END IF; END";
                $result[$name] = ['table' => $table, 'event' => strtoupper($event), 'body' => $body, 'sql' => 'CREATE TRIGGER `'.$name.'` BEFORE '.strtoupper($event).' ON `'.$table.'` FOR EACH ROW '.$body];
            }
        }

        return $result;
    }

    private function exists(PDO $pdo, string $driver, string $type, string $name): bool
    {
        $sql = $driver === 'sqlite' ? 'SELECT COUNT(*) FROM main.sqlite_master WHERE type=\''.$type.'\' AND name COLLATE NOCASE=?'
            : ($type === 'table' ? 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?' : 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
        $statement = $pdo->prepare($sql);
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() !== 0;
    }

    private function owned(PDO $pdo, string $driver, string $type, string $name, string $sql): void
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT type,sql FROM main.sqlite_master WHERE name COLLATE NOCASE=?');
            $statement->execute([$name]);
            if ($statement->fetchAll(PDO::FETCH_ASSOC) !== [['type' => $type, 'sql' => $sql]]) {
                throw new LogicException('Unowned or drifted suppression object requires inspection; nothing was changed.');
            }
            if ($type === 'table') {
                $indexes = $pdo->query("SELECT type,name FROM main.sqlite_master WHERE tbl_name='$name' AND type='index' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                $count = count(array_filter($this->indexes($name), fn ($index) => $index[1]));
                $expected = array_map(fn ($number) => ['type' => 'index', 'name' => 'sqlite_autoindex_'.$name.'_'.$number], range(1, $count));
                if ($indexes !== $expected) {
                    throw new LogicException('Drifted suppression indexes require inspection; nothing was changed.');
                }
            }

            return;
        }
        if ($type === 'trigger') {
            $statement = $pdo->prepare('SELECT EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
            $statement->execute([$name]);
            $actual = $statement->fetch(PDO::FETCH_ASSOC);
            $trigger = $this->triggers('mysql')[$name];
            if ($actual !== ['EVENT_OBJECT_TABLE' => $trigger['table'], 'EVENT_MANIPULATION' => $trigger['event'], 'ACTION_TIMING' => 'BEFORE', 'ACTION_STATEMENT' => $trigger['body']]) {
                throw new LogicException('Drifted suppression trigger requires inspection; nothing was changed.');
            }

            return;
        }
        $statement = $pdo->prepare('SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ($statement->fetch(PDO::FETCH_ASSOC) !== ['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => 'VA retained customer suppression v1', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '']) {
            throw new LogicException('Drifted suppression table requires inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $statement->execute([$name]);
        $expected = [];
        foreach ($this->columns($name) as $column => $definition) {
            $text = str_starts_with($definition[0], 'varchar') || str_starts_with($definition[0], 'char') || $definition[0] === 'text';
            $expected[] = ['COLUMN_NAME' => $column, 'COLUMN_TYPE' => $definition[0], 'IS_NULLABLE' => ($definition[2] ?? false) ? 'YES' : 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ($definition[3] ?? false) ? 'auto_increment' : '', 'COLLATION_NAME' => $text ? (($definition[4] ?? false) ? 'utf8mb4_unicode_ci' : 'ascii_bin') : null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        }
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== $expected) {
            throw new LogicException('Drifted suppression columns require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE,SUB_PART,COLLATION,EXPRESSION,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute([$name]);
        $expectedIndexes = ['PRIMARY' => [['id'], true]] + $this->indexes($name);
        $actualIndexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['SUB_PART'] !== null || $index['COLLATION'] !== 'A' || $index['EXPRESSION'] !== null || $index['IS_VISIBLE'] !== 'YES' || $index['INDEX_TYPE'] !== 'BTREE' || (int) $index['SEQ_IN_INDEX'] !== count($actualIndexes[$index['INDEX_NAME']][0] ?? []) + 1) {
                throw new LogicException('Drifted suppression index requires inspection; nothing was changed.');
            }
            $actualIndexes[$index['INDEX_NAME']][0][] = $index['COLUMN_NAME'];
            $actualIndexes[$index['INDEX_NAME']][1] = (int) $index['NON_UNIQUE'] === 0;
        }
        ksort($actualIndexes);
        ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new LogicException('Drifted suppression indexes require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME');
        $statement->execute([$name]);
        $expectedForeign = [];
        $foreign = $this->foreign($name);
        ksort($foreign);
        foreach ($foreign as $column => $target) {
            $expectedForeign[] = ['CONSTRAINT_NAME' => $name.'_'.$column.'_fk', 'COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => 'id', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT'];
        }
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== $expectedForeign) {
            throw new LogicException('Drifted suppression foreign keys require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1 + count(array_filter($this->indexes($name), fn ($index) => $index[1])) + count($foreign)) {
            throw new LogicException('Unexpected suppression constraints require inspection; nothing was changed.');
        }
    }

    private function refuseShadow(PDO $pdo, string $driver, string $table): void
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)=?');
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new LogicException('Temporary suppression storage requires inspection.');
            }

            return;
        }
        try {
            $row = (array) $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($row['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new LogicException('Temporary suppression storage requires inspection.');
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                throw $error;
            }
        }
    }
}
