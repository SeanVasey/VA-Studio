<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use App\Domain\Customers\Preferences\ConsentMigrationAdmission;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureSchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;

/** Distinct production suppression family. It references only 253 feature bindings and production withdrawal events. */
final class ProductionSuppressionSchema
{
    public const MIGRATION = '2026_10_07_254000_production_suppression';

    public const TABLES = ['production_suppression_targets', 'production_suppression_intents', 'production_suppression_attempts', 'production_suppression_confirmations'];

    /** Exact upstream lineage; never a legacy customer_* consent or suppression table. */
    public const LINEAGE = ['production_account_feature_bindings', 'production_consent_events'];

    public function up(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $database = $connection->getDatabaseName();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $connection->getTablePrefix() !== ''
            || ($driver === 'mysql' && $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database)) {
            throw new LogicException('Production suppression storage requires its supported unprefixed database.');
        }
        $steps = [];
        foreach (self::TABLES as $table) {
            $this->refuseShadow($pdo, $driver, $table);
            $steps[] = ['table', $table, $this->definition($driver, $table)];
        }
        foreach ($this->triggers($driver) as $name => $trigger) {
            $steps[] = ['trigger', $name, $trigger['sql']];
        }
        $namespace = $this->ownedNamespace($driver) + ['migrations' => ['table', 'migrations']];
        $admission = new ConsentMigrationAdmission($pdo, $driver, $database);
        // Before any DDL: owned namespace, reserved keys, then the complete 253/identity/legacy floor.
        $this->namespace($pdo, $driver, $database, $admission, $namespace);
        $this->dependencies($pdo, $driver, $database);
        $known = array_keys($this->triggers($driver));
        foreach (self::TABLES as $table) {
            $statement = $pdo->prepare($driver === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=?" : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');
            $statement->execute([$table]);
            if (array_diff($statement->fetchAll(PDO::FETCH_COLUMN), $known) !== []) {
                throw new LogicException('Unexpected production suppression trigger requires inspection; nothing was changed.');
            }
        }
        // Prove every retained object and a contiguous known installation prefix BEFORE DDL.
        $this->refuseShadow($pdo, $driver, 'migrations');
        $recorded = false;
        if ($this->exists($pdo, $driver, 'table', 'migrations')) {
            $repository = $driver === 'sqlite' ? 'main."migrations"' : '`'.str_replace('`', '``', $database).'`.`migrations`';
            $statement = $pdo->prepare('SELECT COUNT(*) FROM '.$repository.' WHERE migration=?');
            $statement->execute([self::MIGRATION]);
            $recorded = (int) $statement->fetchColumn() !== 0;
        }
        $missing = false;
        foreach ($steps as [$type, $name, $sql]) {
            $exists = $this->exists($pdo, $driver, $type, $name);
            if ($exists && $missing) {
                throw new LogicException('Non-prefix production suppression installation requires inspection; nothing was changed.');
            }
            if ($exists) {
                $this->owned($pdo, $driver, $type, $name, $sql);
            } else {
                if ($recorded) {
                    throw new LogicException('Recorded production suppression schema is incomplete; inspection is required before any DDL.');
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
                throw new LogicException('Production suppression installation changed its captured database.');
            }
            foreach (self::TABLES as $table) {
                $this->refuseShadow($pdo, $driver, $table);
            }
            $this->owned($pdo, $driver, $type, $name, $sql);
        }
        // The last framework DDL callback can alter an earlier object or dependency.
        // Reassert the complete captured schema with raw PDO before allowing bookkeeping.
        $this->namespace($pdo, $driver, $database, $admission, $namespace);
        $this->dependencies($pdo, $driver, $database);
        $this->targetObjects($pdo, $driver);
    }

    /** Read-only admission: never resume/install missing objects during a private operation. */
    public function assertComplete(PDO $pdo, string $driver, string $database): void
    {
        $this->targetNamespace($pdo, $driver, $database);
        $this->dependencies($pdo, $driver, $database);
        $this->targetObjects($pdo, $driver);
    }

    /** Held-frame admission of the owned objects only. The sealed 253 context reproves its own floor. */
    public function assertHeld(PDO $pdo, string $driver, string $database): void
    {
        $this->targetNamespace($pdo, $driver, $database);
        $this->targetObjects($pdo, $driver);
    }

    public function down(): void
    {
        throw new LogicException('Production suppression rollback is refused; retain its schema, evidence and migration record.');
    }

    private function ownedNamespace(string $driver): array
    {
        $namespace = [];
        foreach (self::TABLES as $table) {
            $namespace[$table] = ['table', $table];
        }
        foreach ($this->triggers($driver) as $name => $trigger) {
            $namespace[$name] = ['trigger', $trigger['table']];
        }

        return $namespace;
    }

    private function targetNamespace(PDO $pdo, string $driver, string $database): void
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver
            || ($driver === 'mysql' && $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database)) {
            throw new LogicException('Production suppression storage source changed.');
        }
        foreach (self::TABLES as $table) {
            $this->refuseShadow($pdo, $driver, $table);
        }
        $this->namespace($pdo, $driver, $database, new ConsentMigrationAdmission($pdo, $driver, $database),
            $this->ownedNamespace($driver) + ['migrations' => ['table', 'migrations']]);
    }

    private function dependencies(PDO $pdo, string $driver, string $database): void
    {
        // Complete approved 253 storage, identity floor and its legacy-dependency admission.
        (new ProductionFeatureSchema)->assertComplete($pdo, $driver, $database);
    }

    private function targetObjects(PDO $pdo, string $driver): void
    {
        $guards = $this->triggers($driver);
        foreach (self::TABLES as $table) {
            $this->owned($pdo, $driver, 'table', $table, $this->definition($driver, $table));
            $statement = $pdo->prepare($driver === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name" : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME');
            $statement->execute([$table]);
            $expected = array_keys(array_filter($guards, fn ($guard) => $guard['table'] === $table));
            sort($expected);
            if ($statement->fetchAll(PDO::FETCH_COLUMN) !== $expected) {
                throw new LogicException('Production suppression storage guards changed.');
            }
        }
        foreach ($guards as $name => $guard) {
            $this->owned($pdo, $driver, 'trigger', $name, $guard['sql']);
        }
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
            'production_suppression_targets' => ['id' => $id, 'public_id' => $uuid, 'binding_id' => $key, 'purpose' => ['varchar(40)', 'text'], 'recipient_hmac' => $hash, 'recipient_ciphertext' => $cipher, 'withdrawal_event_id' => $key, 'created_at' => $time],
            'production_suppression_intents' => ['id' => $id, 'public_id' => $uuid, 'target_id' => $key, 'withdrawal_event_id' => $key, 'created_at' => $time],
            'production_suppression_attempts' => ['id' => $id, 'public_id' => $uuid, 'target_id' => $key, 'intent_id' => $key, 'provider_hash' => $hash, 'provider_ciphertext' => $cipher, 'request_hash' => $hash, 'created_at' => $time],
            'production_suppression_confirmations' => ['id' => $id, 'attempt_id' => $key, 'request_hash' => $hash, 'receipt_hash' => $hash, 'receipt_ciphertext' => $cipher, 'created_at' => $time],
        };
    }

    private function indexes(string $table): array
    {
        return match ($table) {
            'production_suppression_targets' => [$table.'_public_unique' => [['public_id'], true], $table.'_recipient_unique' => [['binding_id', 'purpose', 'recipient_hmac'], true], $table.'_withdrawal_index' => [['withdrawal_event_id'], false]],
            'production_suppression_intents' => [$table.'_public_unique' => [['public_id'], true], $table.'_withdrawal_unique' => [['withdrawal_event_id'], true], $table.'_target_index' => [['target_id'], false]],
            'production_suppression_attempts' => [$table.'_public_unique' => [['public_id'], true], $table.'_target_unique' => [['target_id'], true], $table.'_intent_unique' => [['intent_id'], true]],
            'production_suppression_confirmations' => [$table.'_attempt_unique' => [['attempt_id'], true]],
        };
    }

    private function foreign(string $table): array
    {
        return match ($table) {
            'production_suppression_targets' => ['binding_id' => 'production_account_feature_bindings', 'withdrawal_event_id' => 'production_consent_events'],
            'production_suppression_intents' => ['target_id' => 'production_suppression_targets', 'withdrawal_event_id' => 'production_consent_events'],
            'production_suppression_attempts' => ['intent_id' => 'production_suppression_intents', 'target_id' => 'production_suppression_targets'],
            'production_suppression_confirmations' => ['attempt_id' => 'production_suppression_attempts'],
        };
    }

    private function namespace(PDO $pdo, string $driver, string $database, ConsentMigrationAdmission $admission, array $namespace): void
    {
        if ($driver === 'mysql') {
            $this->nativeNamespace($pdo, $database, $namespace);
            $this->nativeKeys($pdo, $database);

            return;
        }
        $admission->namespace($namespace);
        foreach ($this->reservedKeys() as $name => $table) {
            foreach (['main.sqlite_master', 'sqlite_temp_master'] as $dictionary) {
                $statement = $pdo->prepare('SELECT name FROM '.$dictionary.' WHERE name COLLATE NOCASE=?');
                $statement->execute([$name]);
                if ($statement->fetchAll(PDO::FETCH_ASSOC) !== []) {
                    throw new LogicException('Reserved production suppression key collision.');
                }
            }
        }
    }

    private function reservedKeys(): array
    {
        $reserved = [];
        foreach (self::TABLES as $table) {
            foreach ([...array_keys($this->indexes($table)), ...array_map(fn ($column) => $table.'_'.$column.'_fk', array_keys($this->foreign($table)))] as $name) {
                $reserved[$name] = $table;
            }
        }

        return $reserved;
    }

    /** Batched native comparisons keep each dictionary's own collation and every object row. */
    private function nativeNamespace(PDO $pdo, string $database, array $namespace): void
    {
        $names = array_keys($namespace);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $dictionaries = [
            ['TABLES', 'TABLE_SCHEMA', 'TABLE_NAME', 'TABLE_NAME', "CASE WHEN TABLE_TYPE='BASE TABLE' THEN 'table' ELSE 'view' END"],
            ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME', 'EVENT_OBJECT_TABLE', "'trigger'"],
            ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME', 'ROUTINE_NAME', "'routine'"],
            ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME', 'EVENT_NAME', "'event'"],
            ['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME', 'TABLE_NAME', "'index'"],
            ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME', 'TABLE_NAME', "'constraint'"],
        ];
        $seen = [];
        foreach ($dictionaries as [$dictionary, $schema, $column, $owner, $kind]) {
            $matches = implode(',', array_map(fn ($index) => '('.$column.'=?) namespace_match_'.$index, array_keys($names)));
            $statement = $pdo->prepare('SELECT '.$schema.' schema_name,'.$column.' name,'.$owner.' owner,'.$kind.' kind,'.$matches.' FROM information_schema.'.$dictionary.' WHERE '.$schema.'=DATABASE() AND '.$column.' IN ('.$placeholders.')');
            $statement->execute([...$names, ...$names]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $matched = false;
                foreach ($names as $index => $name) {
                    $match = $row['namespace_match_'.$index];
                    if (! in_array($match, [0, 1, '0', '1'], true)) {
                        throw new LogicException('Ambiguous native production suppression namespace comparison.');
                    }
                    if ((int) $match === 1) {
                        $matched = true;
                        if ($row['schema_name'] !== $database || $row['name'] !== $name || [$row['kind'], $row['owner']] !== $namespace[$name] || isset($seen[$name])) {
                            throw new LogicException('Foreign, aliased or duplicate production suppression namespace object.');
                        }
                        $seen[$name] = true;
                    }
                }
                if (! $matched) {
                    throw new LogicException('Unmatched native production suppression namespace object.');
                }
            }
        }
        foreach ($names as $name) {
            $this->refuseShadow($pdo, 'mysql', $name);
        }
    }

    private function nativeKeys(PDO $pdo, string $database): void
    {
        $reserved = $this->reservedKeys();
        foreach (array_keys($reserved) as $name) {
            try {
                $pdo->query('SHOW CREATE TABLE `'.str_replace('`', '``', $database).'`.`'.$name.'`');
                throw new LogicException('Temporary production suppression key collision.');
            } catch (PDOException $error) {
                if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                    throw $error;
                }
            }
        }
        $names = array_keys($reserved);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        foreach ([['TABLES', 'TABLE_SCHEMA', 'TABLE_NAME'], ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME'], ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME']] as [$dictionary, $schema, $column]) {
            $statement = $pdo->prepare('SELECT '.$column.' FROM information_schema.'.$dictionary.' WHERE '.$schema.'=DATABASE() AND '.$column.' IN ('.$placeholders.')');
            $statement->execute($names);
            if ($statement->fetchAll(PDO::FETCH_ASSOC) !== []) {
                throw new LogicException('Reserved production suppression key collision.');
            }
        }
        foreach ([['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME']] as [$dictionary, $schema, $column]) {
            // Both IN and the match bits compare the actual dictionary column to parameters.
            $matches = implode(',', array_map(fn ($index) => '('.$column.'=?) key_match_'.$index, array_keys($names)));
            $statement = $pdo->prepare('SELECT TABLE_NAME,'.$column.' name,'.$matches.' FROM information_schema.'.$dictionary.' WHERE '.$schema.'=DATABASE() AND '.$column.' IN ('.$placeholders.')');
            $statement->execute([...$names, ...$names]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $matched = false;
                foreach ($names as $index => $name) {
                    $match = $row['key_match_'.$index];
                    if (! in_array($match, [0, 1, '0', '1'], true)) {
                        throw new LogicException('Ambiguous native production suppression key comparison.');
                    }
                    if ((int) $match === 1) {
                        $matched = true;
                        if ($row['TABLE_NAME'] !== $reserved[$name] || $row['name'] !== $name || ! $this->exists($pdo, 'mysql', 'table', $reserved[$name])) {
                            throw new LogicException('Foreign or aliased production suppression key requires inspection.');
                        }
                    }
                }
                if (! $matched) {
                    throw new LogicException('Unmatched native production suppression key requires inspection.');
                }
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
                $parts[] = '`'.$name.'` '.$mysql.($text ? ' CHARACTER SET ascii COLLATE ascii_bin' : '').($nullable ? ' NULL DEFAULT NULL' : ' NOT NULL').($primary ? ' AUTO_INCREMENT' : '');
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

        return 'CREATE TABLE '.($driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`').' ('.implode(', ', $parts).')'.($driver === 'mysql' ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VA retained production suppression v1'" : '');
    }

    /** Append-only retention: no update or delete, so a later grant can never reverse a suppression. */
    private function triggers(string $driver): array
    {
        $hex = fn ($c) => $driver === 'sqlite' ? "length(NEW.$c)=64 AND NEW.$c NOT GLOB '*[^a-f0-9]*'" : "CHAR_LENGTH(NEW.$c)=64 AND NEW.$c REGEXP '^[a-f0-9]{64}$'";
        $bytes = fn ($c) => $driver === 'sqlite' ? 'length(CAST(NEW.'.$c.' AS BLOB))' : 'LENGTH(NEW.'.$c.')';
        $length = fn ($c) => ($driver === 'sqlite' ? 'length' : 'CHAR_LENGTH').'(NEW.'.$c.')';
        $target = "NEW.purpose='email_marketing' AND ".$length('public_id').'=36 AND '.$hex('recipient_hmac').' AND '.$bytes('recipient_ciphertext').' BETWEEN 1 AND 8192'
            ." AND EXISTS (SELECT 1 FROM production_account_feature_bindings b WHERE b.id=NEW.binding_id AND b.feature='consent_preferences')"
            ." AND EXISTS (SELECT 1 FROM production_consent_events e WHERE e.id=NEW.withdrawal_event_id AND e.binding_id=NEW.binding_id AND e.purpose=NEW.purpose AND e.status='withdrawn' AND e.affirmative=0 AND e.recipient_hmac=NEW.recipient_hmac AND e.created_at<=NEW.created_at)"
            .' AND NOT EXISTS (SELECT 1 FROM production_suppression_targets WHERE id=NEW.id OR public_id=NEW.public_id OR (binding_id=NEW.binding_id AND purpose=NEW.purpose AND recipient_hmac=NEW.recipient_hmac))';
        $intent = $length('public_id').'=36'
            ." AND EXISTS (SELECT 1 FROM production_suppression_targets t JOIN production_consent_events e ON e.binding_id=t.binding_id AND e.purpose=t.purpose AND e.recipient_hmac=t.recipient_hmac WHERE t.id=NEW.target_id AND e.id=NEW.withdrawal_event_id AND e.status='withdrawn' AND e.affirmative=0 AND t.created_at<=NEW.created_at AND e.created_at<=NEW.created_at)"
            .' AND NOT EXISTS (SELECT 1 FROM production_suppression_intents WHERE id=NEW.id OR public_id=NEW.public_id OR withdrawal_event_id=NEW.withdrawal_event_id)';
        $attempt = $length('public_id').'=36 AND '.$hex('provider_hash').' AND '.$hex('request_hash').' AND '.$bytes('provider_ciphertext').' BETWEEN 1 AND 8192'
            .' AND EXISTS (SELECT 1 FROM production_suppression_intents i WHERE i.id=NEW.intent_id AND i.target_id=NEW.target_id AND i.created_at<=NEW.created_at)'
            .' AND NOT EXISTS (SELECT 1 FROM production_suppression_attempts WHERE id=NEW.id OR public_id=NEW.public_id OR target_id=NEW.target_id OR intent_id=NEW.intent_id)';
        $confirmation = $hex('request_hash').' AND '.$hex('receipt_hash').' AND '.$bytes('receipt_ciphertext').' BETWEEN 1 AND 8192'
            .' AND EXISTS (SELECT 1 FROM production_suppression_attempts a WHERE a.id=NEW.attempt_id AND a.request_hash=NEW.request_hash AND a.created_at<=NEW.created_at)'
            .' AND NOT EXISTS (SELECT 1 FROM production_suppression_confirmations WHERE id=NEW.id OR attempt_id=NEW.attempt_id)';
        $result = [];
        foreach (['production_suppression_targets' => $target, 'production_suppression_intents' => $intent, 'production_suppression_attempts' => $attempt, 'production_suppression_confirmations' => $confirmation] as $table => $insert) {
            foreach (['insert' => $insert, 'update' => '0=1', 'delete' => '0=1'] as $event => $condition) {
                $name = $table.'_retain_'.$event;
                $body = $driver === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Retained production suppression refused') WHERE COALESCE(($condition),0)<>1; END" : "BEGIN IF COALESCE(($condition),0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained production suppression refused'; END IF; END";
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
                throw new LogicException('Unowned or drifted production suppression object requires inspection; nothing was changed.');
            }
            if ($type === 'table') {
                $statement = $pdo->prepare("SELECT type,name FROM main.sqlite_master WHERE tbl_name=? AND type='index' ORDER BY name");
                $statement->execute([$name]);
                $count = count(array_filter($this->indexes($name), fn ($index) => $index[1]));
                $expected = array_map(fn ($number) => ['type' => 'index', 'name' => 'sqlite_autoindex_'.$name.'_'.$number], range(1, $count));
                if ($statement->fetchAll(PDO::FETCH_ASSOC) !== $expected) {
                    throw new LogicException('Drifted production suppression indexes require inspection; nothing was changed.');
                }
            }

            return;
        }
        if ($type === 'trigger') {
            $statement = $pdo->prepare('SELECT EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
            $statement->execute([$name]);
            $trigger = $this->triggers('mysql')[$name];
            if ($statement->fetchAll(PDO::FETCH_ASSOC) !== [['EVENT_OBJECT_TABLE' => $trigger['table'], 'EVENT_MANIPULATION' => $trigger['event'], 'ACTION_TIMING' => 'BEFORE', 'ACTION_STATEMENT' => $trigger['body']]]) {
                throw new LogicException('Drifted production suppression trigger requires inspection; nothing was changed.');
            }

            return;
        }
        $statement = $pdo->prepare('SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== [['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => 'VA retained production suppression v1', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '']]) {
            throw new LogicException('Drifted production suppression table requires inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $statement->execute([$name]);
        $expected = [];
        foreach ($this->columns($name) as $column => $definition) {
            $text = str_starts_with($definition[0], 'varchar') || str_starts_with($definition[0], 'char') || $definition[0] === 'text';
            $expected[] = ['COLUMN_NAME' => $column, 'COLUMN_TYPE' => $definition[0], 'IS_NULLABLE' => ($definition[2] ?? false) ? 'YES' : 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ($definition[3] ?? false) ? 'auto_increment' : '', 'COLLATION_NAME' => $text ? 'ascii_bin' : null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        }
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== $expected) {
            throw new LogicException('Drifted production suppression columns require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE,SUB_PART,COLLATION,EXPRESSION,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute([$name]);
        $expectedIndexes = ['PRIMARY' => [['id'], true]] + $this->indexes($name);
        $actualIndexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['SUB_PART'] !== null || $index['COLLATION'] !== 'A' || $index['EXPRESSION'] !== null || $index['IS_VISIBLE'] !== 'YES' || $index['INDEX_TYPE'] !== 'BTREE' || (int) $index['SEQ_IN_INDEX'] !== count($actualIndexes[$index['INDEX_NAME']][0] ?? []) + 1) {
                throw new LogicException('Drifted production suppression index requires inspection; nothing was changed.');
            }
            $actualIndexes[$index['INDEX_NAME']][0][] = $index['COLUMN_NAME'];
            $actualIndexes[$index['INDEX_NAME']][1] = (int) $index['NON_UNIQUE'] === 0;
        }
        ksort($actualIndexes);
        ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new LogicException('Drifted production suppression indexes require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME');
        $statement->execute([$name]);
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $expectedForeign = [];
        $foreign = $this->foreign($name);
        ksort($foreign);
        foreach ($foreign as $column => $target) {
            $expectedForeign[] = ['CONSTRAINT_NAME' => $name.'_'.$column.'_fk', 'COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $schema, 'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => 'id', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT'];
        }
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== $expectedForeign) {
            throw new LogicException('Drifted production suppression foreign keys require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1 + count(array_filter($this->indexes($name), fn ($index) => $index[1])) + count($foreign)) {
            throw new LogicException('Unexpected production suppression constraints require inspection; nothing was changed.');
        }
    }

    private function refuseShadow(PDO $pdo, string $driver, string $table): void
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)=?');
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new LogicException('Temporary production suppression storage requires inspection.');
            }

            return;
        }
        try {
            $row = (array) $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($row['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new LogicException('Temporary production suppression storage requires inspection.');
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                throw $error;
            }
        }
    }
}
