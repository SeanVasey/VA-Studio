<?php

namespace App\Domain\Customers\ProductionFeatures;

use App\Domain\Customers\Preferences\ConsentMigrationAdmission;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Support\MigrationDefinitions;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use ReflectionMethod;

final class ProductionFeatureSchema
{
    public const TABLES = ['production_account_feature_bindings', 'production_listening_libraries', 'production_consent_policies', 'production_consent_events', 'production_consent_states'];

    public function up(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $database = $connection->getDatabaseName();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $connection->getTablePrefix() !== ''
            || ($driver === 'mysql' && $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database)) {
            throw new LogicException('Production feature storage requires its supported unprefixed database.');
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
                throw new LogicException('Unexpected production feature trigger requires inspection; nothing was changed.');
            }
        }
        // Prove every retained object and a contiguous known installation prefix BEFORE DDL.
        $this->refuseShadow($pdo, $driver, 'migrations');
        $recorded = false;
        if ($this->exists($pdo, $driver, 'table', 'migrations')) {
            $repository = $driver === 'sqlite' ? 'main."migrations"' : '`'.str_replace('`', '``', $database).'`.`migrations`';
            $statement = $pdo->prepare('SELECT COUNT(*) FROM '.$repository.' WHERE migration=?');
            $statement->execute(['2026_10_07_253000_production_account_features']);
            $recorded = (int) $statement->fetchColumn() !== 0;
        }
        $missing = false;
        foreach ($steps as [$type, $name, $sql]) {
            $exists = $this->exists($pdo, $driver, $type, $name);
            if ($exists && $missing) {
                throw new LogicException('Non-prefix production feature installation requires inspection; nothing was changed.');
            }
            if ($exists) {
                $this->owned($pdo, $driver, $type, $name, $sql);
            } else {
                if ($recorded) {
                    throw new LogicException('Recorded production feature schema is incomplete; inspection is required before any DDL.');
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
                throw new LogicException('Production feature installation changed its captured database.');
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
                throw new LogicException('Unexpected production feature guards require inspection.');
            }
        }
    }

    /** Read-only admission: never resume/install missing objects during a private account operation. */
    public function assertComplete(PDO $pdo, string $driver, string $database): void
    {
        $admission = $this->targetNamespace($pdo, $driver, $database);
        $this->dependencies($pdo, $driver, $database, $admission);
        $this->targetObjects($pdo, $driver);
    }

    /** The sealed context has already proved the exact identity floor through its typed lock.
     * Its mandatory original-history/current terminal proofs repeat that same floor after hooks.
     * This method has no raw-source or optional skip entry point.
     */
    public function assertHeld(ProductionFeatureContext $context): void
    {
        [$pdo, $driver, $database] = $context->heldStorage();
        $admission = $this->targetNamespace($pdo, $driver, $database);
        $admission->dependencies();
        $this->legacyDependencies($pdo, $driver, $database, $admission);
        $this->targetObjects($pdo, $driver);
    }

    private function targetNamespace(PDO $pdo, string $driver, string $database): ConsentMigrationAdmission
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver
            || ($driver === 'mysql' && $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database)) {
            throw new LogicException('Production feature storage source changed.');
        }
        $admission = new ConsentMigrationAdmission($pdo, $driver, $database);
        $namespace = ['migrations' => ['table', 'migrations']];
        foreach (self::TABLES as $table) {
            $namespace[$table] = ['table', $table];
            $this->refuseShadow($pdo, $driver, $table);
        }
        $guards = $this->triggers($driver);
        foreach ($guards as $name => $guard) {
            $namespace[$name] = ['trigger', $guard['table']];
        }
        $this->namespace($pdo, $driver, $database, $admission, $namespace);

        return $admission;
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
                throw new LogicException('Production feature storage guards changed.');
            }
        }
        foreach ($guards as $name => $guard) {
            $this->owned($pdo, $driver, 'trigger', $name, $guard['sql']);
        }
    }

    public function down(): void
    {
        throw new LogicException('Production feature rollback is refused; retain its schema, evidence and migration record.');
    }

    private function columns(string $table): array
    {
        $id = ['bigint unsigned', 'integer', false, true];
        $key = ['bigint unsigned', 'integer'];
        $uuid = ['char(36)', 'text'];
        $hash = ['char(64)', 'text'];
        $cipher = ['text', 'text'];
        $time = ['timestamp', 'datetime'];
        $version = ['int unsigned', 'integer'];

        return match ($table) {
            'production_account_feature_bindings' => ['id' => $id, 'public_id' => $uuid, 'account_id' => $key, 'origin_public_id' => $uuid, 'feature' => ['varchar(40)', 'text'], 'feature_schema_version' => ['tinyint unsigned', 'integer'], 'feature_policy_version' => ['varchar(80)', 'text'], 'binding_hash' => $hash, 'binding_ciphertext' => $cipher, 'created_at' => $time],
            'production_listening_libraries' => ['id' => $id, 'binding_id' => $key, 'version' => $version, 'payload' => $cipher, 'created_at' => $time, 'updated_at' => $time],
            'production_consent_policies' => ['id' => $id, 'purpose' => ['varchar(40)', 'text'], 'version' => ['varchar(80)', 'text'], 'notice' => ['text', 'text', false, false, true], 'notice_hash' => $hash, 'review_reference' => ['varchar(200)', 'text', false, false, true], 'policy_hash' => $hash, 'created_at' => $time],
            'production_consent_events' => ['id' => $id, 'public_id' => $uuid, 'binding_id' => $key, 'purpose' => ['varchar(40)', 'text'], 'revision' => $version, 'status' => ['varchar(16)', 'text'], 'policy_id' => ['bigint unsigned', 'integer', true], 'source' => ['varchar(32)', 'text'], 'affirmative' => ['tinyint unsigned', 'integer'], 'recipient_hmac' => $hash, 'recipient_ciphertext' => $cipher, 'created_at' => $time],
            'production_consent_states' => ['id' => $id, 'binding_id' => $key, 'purpose' => ['varchar(40)', 'text'], 'revision' => $version, 'event_id' => $key, 'withdrawal_event_id' => ['bigint unsigned', 'integer', true], 'created_at' => $time, 'updated_at' => $time],
        };
    }

    private function indexes(string $table): array
    {
        return match ($table) {
            'production_account_feature_bindings' => [$table.'_public_unique' => [['public_id'], true], $table.'_account_unique' => [['account_id', 'feature'], true], $table.'_origin_unique' => [['origin_public_id', 'feature'], true]],
            'production_listening_libraries' => [$table.'_binding_unique' => [['binding_id'], true]],
            'production_consent_policies' => [$table.'_version_unique' => [['purpose', 'version'], true]],
            'production_consent_events' => [$table.'_public_unique' => [['public_id'], true], $table.'_revision_unique' => [['binding_id', 'purpose', 'revision'], true], $table.'_policy_index' => [['policy_id'], false]],
            'production_consent_states' => [$table.'_binding_unique' => [['binding_id', 'purpose'], true], $table.'_event_index' => [['event_id'], false], $table.'_withdrawal_index' => [['withdrawal_event_id'], false]],
        };
    }

    private function foreign(string $table): array
    {
        return match ($table) {
            'production_account_feature_bindings' => ['account_id' => 'customer_accounts'],
            'production_listening_libraries' => ['binding_id' => 'production_account_feature_bindings'],
            'production_consent_policies' => [],
            'production_consent_events' => ['binding_id' => 'production_account_feature_bindings', 'policy_id' => 'production_consent_policies'],
            'production_consent_states' => ['binding_id' => 'production_account_feature_bindings', 'event_id' => 'production_consent_events', 'withdrawal_event_id' => 'production_consent_events'],
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
        foreach (self::TABLES as $table) {
            foreach ([...array_keys($this->indexes($table)), ...array_map(fn ($column) => $table.'_'.$column.'_fk', array_keys($this->foreign($table)))] as $name) {
                foreach (['main.sqlite_master', 'sqlite_temp_master'] as $dictionary) {
                    $statement = $pdo->prepare('SELECT name FROM '.$dictionary.' WHERE name COLLATE NOCASE=?');
                    $statement->execute([$name]);
                    if ($statement->fetchAll(PDO::FETCH_ASSOC) !== []) {
                        throw new LogicException('Reserved production feature key collision.');
                    }
                }
            }
        }
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
                        throw new LogicException('Ambiguous native production feature namespace comparison.');
                    }
                    if ((int) $match === 1) {
                        $matched = true;
                        if ($row['schema_name'] !== $database || $row['name'] !== $name || [$row['kind'], $row['owner']] !== $namespace[$name] || isset($seen[$name])) {
                            throw new LogicException('Foreign, aliased or duplicate production feature namespace object.');
                        }
                        $seen[$name] = true;
                    }
                }
                if (! $matched) {
                    throw new LogicException('Unmatched native production feature namespace object.');
                }
            }
        }
        foreach ($names as $name) {
            $this->refuseShadow($pdo, 'mysql', $name);
        }
    }

    private function nativeKeys(PDO $pdo, string $database): void
    {
        $reserved = [];
        foreach (self::TABLES as $table) {
            foreach ([...array_keys($this->indexes($table)), ...array_map(fn ($column) => $table.'_'.$column.'_fk', array_keys($this->foreign($table)))] as $name) {
                $reserved[$name] = $table;
                try {
                    $pdo->query('SHOW CREATE TABLE `'.str_replace('`', '``', $database).'`.`'.$name.'`');
                    throw new LogicException('Temporary production feature key collision.');
                } catch (PDOException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
        }
        $names = array_keys($reserved);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        foreach ([['TABLES', 'TABLE_SCHEMA', 'TABLE_NAME'], ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME'], ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME']] as [$dictionary, $schema, $column]) {
            $statement = $pdo->prepare('SELECT '.$column.' FROM information_schema.'.$dictionary.' WHERE '.$schema.'=DATABASE() AND '.$column.' IN ('.$placeholders.')');
            $statement->execute($names);
            if ($statement->fetchAll(PDO::FETCH_ASSOC) !== []) {
                throw new LogicException('Reserved production feature key collision.');
            }
        }
        foreach ([['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME']] as [$dictionary, $schema, $column]) {
            // Both IN and the match bits compare the actual dictionary column to parameters.
            // No derived-table collation, PHP case folding, or first-row mapping loses aliases.
            $matches = implode(',', array_map(fn ($index) => '('.$column.'=?) key_match_'.$index, array_keys($names)));
            $statement = $pdo->prepare('SELECT TABLE_NAME,'.$column.' name,'.$matches.' FROM information_schema.'.$dictionary.' WHERE '.$schema.'=DATABASE() AND '.$column.' IN ('.$placeholders.')');
            $statement->execute([...$names, ...$names]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $matched = false;
                foreach ($names as $index => $name) {
                    $match = $row['key_match_'.$index];
                    if (! in_array($match, [0, 1, '0', '1'], true)) {
                        throw new LogicException('Ambiguous native production feature key comparison.');
                    }
                    if ((int) $match === 1) {
                        $matched = true;
                        if ($row['TABLE_NAME'] !== $reserved[$name] || $row['name'] !== $name || ! $this->exists($pdo, 'mysql', 'table', $reserved[$name])) {
                            throw new LogicException('Foreign or aliased production feature key requires inspection.');
                        }
                    }
                }
                if (! $matched) {
                    throw new LogicException('Unmatched native production feature key requires inspection.');
                }
            }
        }
    }

    private function dependencies(PDO $pdo, string $driver, string $database, ConsentMigrationAdmission $admission): void
    {
        $admission->dependencies();
        [$present] = (new IdentityMigrationOwnership)->inspect($pdo, $driver);
        if (in_array(false, $present, true)) {
            throw new LogicException('Complete approved identity storage is required before production feature DDL.');
        }
        $this->legacyDependencies($pdo, $driver, $database, $admission);
    }

    private function legacyDependencies(PDO $pdo, string $driver, string $database, ConsentMigrationAdmission $admission): void
    {
        $prior = MigrationDefinitions::load('2026_10_07_250000_customer_consent.php');
        $triggers = (new ReflectionMethod($prior, 'triggers'))->invoke($prior, $driver);
        $namespace = [];
        foreach (['customer_consent_policies', 'customer_consent_events', 'customer_consent_states', 'customer_saved_tracks'] as $table) {
            $namespace[$table] = ['table', $table];
        }
        foreach ($triggers as $name => $guard) {
            $namespace[$name] = ['trigger', $guard['table']];
        }
        if ($driver === 'mysql') {
            $this->nativeNamespace($pdo, $database, $namespace);
        } else {
            $admission->namespace($namespace);
        }
        foreach ($namespace as $name => [$type,$owner]) {
            if ($name === 'customer_saved_tracks') {
                continue;
            }
            $sql = $type === 'table' ? (new ReflectionMethod($prior, 'definition'))->invoke($prior, $driver, $name) : $triggers[$name]['sql'];
            (new ReflectionMethod($prior, 'owned'))->invoke($prior, $pdo, $driver, $type, $name, $sql);
        }
        $legacy = MigrationDefinitions::load('2026_10_07_242000_customer_saved_tracks.php');
        (new ReflectionMethod($legacy, 'owned'))->invoke($legacy, $pdo, $driver);
        foreach (['customer_consent_policies', 'customer_consent_events', 'customer_consent_states'] as $table) {
            $statement = $pdo->prepare($driver === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name" : 'SELECT TRIGGER_NAME name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME');
            $statement->execute([$table]);
            $expected = array_keys(array_filter($triggers, fn ($guard) => $guard['table'] === $table));
            sort($expected);
            if ($statement->fetchAll(PDO::FETCH_COLUMN) !== $expected) {
                throw new LogicException('Unexpected legacy consent dependency guards.');
            }
        }
        if ($driver === 'mysql') {
            foreach (['customer_saved_tracks', 'customer_consent_policies', 'customer_consent_events', 'customer_consent_states'] as $table) {
                $statement = $pdo->prepare('SELECT CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $statement->execute([$table]);
                if ($statement->fetchAll(PDO::FETCH_ASSOC) !== [['CREATE_OPTIONS' => '']]) {
                    throw new LogicException('Drifted legacy dependency table options.');
                }
                $statement = $pdo->prepare('SELECT SUB_PART,COLLATION,EXPRESSION,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $statement->execute([$table]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ($row !== ['SUB_PART' => null, 'COLLATION' => 'A', 'EXPRESSION' => null, 'IS_VISIBLE' => 'YES']) {
                        throw new LogicException('Drifted legacy dependency indexes.');
                    }
                }
                $statement = $pdo->prepare('SELECT COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $statement->execute([$table]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ($row !== ['COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => '']) {
                        throw new LogicException('Drifted legacy dependency columns.');
                    }
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

        return 'CREATE TABLE '.($driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`').' ('.implode(', ', $parts).')'.($driver === 'mysql' ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VA retained production account feature v1'" : '');
    }

    private function triggers(string $driver): array
    {
        $hex = fn ($c) => $driver === 'sqlite' ? "length(NEW.$c)=64 AND NEW.$c NOT GLOB '*[^a-f0-9]*'" : "CHAR_LENGTH(NEW.$c)=64 AND NEW.$c REGEXP '^[a-f0-9]{64}$'";
        $bytes = fn ($c) => $driver === 'sqlite' ? 'length(CAST(NEW.'.$c.' AS BLOB))' : 'LENGTH(NEW.'.$c.')';
        $length = fn ($c) => ($driver === 'sqlite' ? 'length' : 'CHAR_LENGTH').'(NEW.'.$c.')';
        $binding = "NEW.feature_schema_version=1 AND ((NEW.feature='listening_library' AND NEW.feature_policy_version='production-listening-library-identity-v1') OR (NEW.feature='consent_preferences' AND NEW.feature_policy_version='production-consent-preferences-identity-v1')) AND ".$length('public_id').'=36 AND '.$length('origin_public_id').'=36 AND '.$hex('binding_hash').' AND '.$bytes('binding_ciphertext').' BETWEEN 1 AND 8192'
            .' AND EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id WHERE a.id=NEW.account_id AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)'
            .' AND NOT EXISTS (SELECT 1 FROM production_account_feature_bindings WHERE id=NEW.id OR public_id=NEW.public_id OR (account_id=NEW.account_id AND feature=NEW.feature) OR (origin_public_id=NEW.origin_public_id AND feature=NEW.feature))';
        $library = 'NEW.version=0 AND '.$bytes('payload')." BETWEEN 1 AND 60000 AND NEW.created_at=NEW.updated_at AND EXISTS (SELECT 1 FROM production_account_feature_bindings WHERE id=NEW.binding_id AND feature='listening_library') AND NOT EXISTS (SELECT 1 FROM production_listening_libraries WHERE id=NEW.id OR binding_id=NEW.binding_id)";
        $libraryUpdate = 'NEW.id=OLD.id AND NEW.binding_id=OLD.binding_id AND NEW.created_at=OLD.created_at AND NEW.version=OLD.version+1 AND NEW.version<=2147483646 AND NEW.updated_at>=OLD.updated_at AND '.$bytes('payload').' BETWEEN 1 AND 60000';
        $policy = "NEW.purpose='email_marketing' AND ".$length('version').' BETWEEN 1 AND 80 AND '.$length('notice').' BETWEEN 1 AND 2000 AND '.$bytes('notice').'<=8000 AND '.$length('review_reference').' BETWEEN 1 AND 200 AND '.$bytes('review_reference').'<=800 AND '.$hex('notice_hash').' AND '.$hex('policy_hash').' AND NOT EXISTS (SELECT 1 FROM production_consent_policies WHERE id=NEW.id OR (purpose=NEW.purpose AND version=NEW.version))';
        $event = "NEW.purpose='email_marketing' AND NEW.source='first_party_customer' AND NEW.revision BETWEEN 1 AND 2147483646 AND ".$length('public_id').'=36 AND '.$hex('recipient_hmac').' AND '.$bytes('recipient_ciphertext').' BETWEEN 1 AND 8192'
            ." AND ((NEW.status='granted' AND NEW.affirmative=1 AND NEW.policy_id IS NOT NULL) OR (NEW.status='withdrawn' AND NEW.affirmative=0)) AND EXISTS (SELECT 1 FROM production_account_feature_bindings WHERE id=NEW.binding_id AND feature='consent_preferences')"
            .' AND (NEW.policy_id IS NULL OR EXISTS (SELECT 1 FROM production_consent_policies WHERE id=NEW.policy_id AND purpose=NEW.purpose))'
            .' AND NEW.revision=COALESCE((SELECT revision FROM production_consent_states WHERE binding_id=NEW.binding_id AND purpose=NEW.purpose),0)+1'
            .' AND NOT EXISTS (SELECT 1 FROM production_consent_events WHERE id=NEW.id OR public_id=NEW.public_id OR (binding_id=NEW.binding_id AND purpose=NEW.purpose AND revision=NEW.revision))';
        $pointer = 'EXISTS (SELECT 1 FROM production_consent_events e WHERE e.id=NEW.event_id AND e.binding_id=NEW.binding_id AND e.purpose=NEW.purpose AND e.revision=NEW.revision AND e.created_at=NEW.updated_at';
        $withdraw = " AND ((e.status='withdrawn' AND NEW.withdrawal_event_id=e.id) OR (e.status='granted' AND NEW.withdrawal_event_id IS NULL)))";
        $insert = "NEW.purpose='email_marketing' AND NEW.revision=1 AND NEW.created_at=NEW.updated_at AND ".$pointer.$withdraw.' AND NOT EXISTS (SELECT 1 FROM production_consent_states WHERE id=NEW.id OR (binding_id=NEW.binding_id AND purpose=NEW.purpose))';
        $update = 'NEW.id=OLD.id AND NEW.binding_id=OLD.binding_id AND NEW.purpose=OLD.purpose AND NEW.created_at=OLD.created_at AND NEW.revision=OLD.revision+1 AND NEW.revision<=2147483646 AND NEW.updated_at>=OLD.updated_at AND '.$pointer." AND ((e.status='withdrawn' AND NEW.withdrawal_event_id=e.id) OR (e.status='granted' AND (NEW.withdrawal_event_id=OLD.withdrawal_event_id OR (NEW.withdrawal_event_id IS NULL AND OLD.withdrawal_event_id IS NULL)))))";
        $result = [];
        foreach (['production_account_feature_bindings' => ['insert' => $binding, 'update' => '0=1', 'delete' => '0=1'], 'production_listening_libraries' => ['insert' => $library, 'update' => $libraryUpdate, 'delete' => '0=1'], 'production_consent_policies' => ['insert' => $policy, 'update' => '0=1', 'delete' => '0=1'], 'production_consent_events' => ['insert' => $event, 'update' => '0=1', 'delete' => '0=1'], 'production_consent_states' => ['insert' => $insert, 'update' => $update, 'delete' => '0=1']] as $table => $operations) {
            foreach ($operations as $event => $condition) {
                $name = $table.'_retain_'.$event;
                // Nullable withdrawal pointers must not turn a failed condition into SQL UNKNOWN.
                $body = $driver === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Retained production feature refused') WHERE COALESCE(($condition),0)<>1; END" : "BEGIN IF COALESCE(($condition),0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained production feature refused'; END IF; END";
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
                throw new LogicException('Unowned or drifted production feature object requires inspection; nothing was changed.');
            }
            if ($type === 'table') {
                $indexes = $pdo->query("SELECT type,name FROM main.sqlite_master WHERE tbl_name='$name' AND type='index' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                $count = count(array_filter($this->indexes($name), fn ($index) => $index[1]));
                $expected = array_map(fn ($number) => ['type' => 'index', 'name' => 'sqlite_autoindex_'.$name.'_'.$number], range(1, $count));
                if ($indexes !== $expected) {
                    throw new LogicException('Drifted production feature indexes require inspection; nothing was changed.');
                }
            }

            return;
        }
        if ($type === 'trigger') {
            $statement = $pdo->prepare('SELECT EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
            $statement->execute([$name]);
            $actual = $statement->fetchAll(PDO::FETCH_ASSOC);
            $trigger = $this->triggers('mysql')[$name];
            if ($actual !== [['EVENT_OBJECT_TABLE' => $trigger['table'], 'EVENT_MANIPULATION' => $trigger['event'], 'ACTION_TIMING' => 'BEFORE', 'ACTION_STATEMENT' => $trigger['body']]]) {
                throw new LogicException('Drifted production feature trigger requires inspection; nothing was changed.');
            }

            return;
        }
        $statement = $pdo->prepare('SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== [['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => 'VA retained production account feature v1', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '']]) {
            throw new LogicException('Drifted production feature table requires inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $statement->execute([$name]);
        $expected = [];
        foreach ($this->columns($name) as $column => $definition) {
            $text = str_starts_with($definition[0], 'varchar') || str_starts_with($definition[0], 'char') || $definition[0] === 'text';
            $expected[] = ['COLUMN_NAME' => $column, 'COLUMN_TYPE' => $definition[0], 'IS_NULLABLE' => ($definition[2] ?? false) ? 'YES' : 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ($definition[3] ?? false) ? 'auto_increment' : '', 'COLLATION_NAME' => $text ? (($definition[4] ?? false) ? 'utf8mb4_unicode_ci' : 'ascii_bin') : null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        }
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== $expected) {
            throw new LogicException('Drifted production feature columns require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE,SUB_PART,COLLATION,EXPRESSION,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute([$name]);
        $expectedIndexes = ['PRIMARY' => [['id'], true]] + $this->indexes($name);
        $actualIndexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['SUB_PART'] !== null || $index['COLLATION'] !== 'A' || $index['EXPRESSION'] !== null || $index['IS_VISIBLE'] !== 'YES' || $index['INDEX_TYPE'] !== 'BTREE' || (int) $index['SEQ_IN_INDEX'] !== count($actualIndexes[$index['INDEX_NAME']][0] ?? []) + 1) {
                throw new LogicException('Drifted production feature index requires inspection; nothing was changed.');
            }
            $actualIndexes[$index['INDEX_NAME']][0][] = $index['COLUMN_NAME'];
            $actualIndexes[$index['INDEX_NAME']][1] = (int) $index['NON_UNIQUE'] === 0;
        }
        ksort($actualIndexes);
        ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new LogicException('Drifted production feature indexes require inspection; nothing was changed.');
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
            throw new LogicException('Drifted production feature foreign keys require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1 + count(array_filter($this->indexes($name), fn ($index) => $index[1])) + count($foreign)) {
            throw new LogicException('Unexpected production feature constraints require inspection; nothing was changed.');
        }
    }

    private function refuseShadow(PDO $pdo, string $driver, string $table): void
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)=?');
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new LogicException('Temporary production feature storage requires inspection.');
            }

            return;
        }
        try {
            $row = (array) $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($row['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new LogicException('Temporary production feature storage requires inspection.');
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                throw $error;
            }
        }
    }
}
