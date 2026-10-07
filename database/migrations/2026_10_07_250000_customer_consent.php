<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['customer_consent_policies', 'customer_consent_events', 'customer_consent_states'];

    public function up(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $database = $connection->getDatabaseName();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $connection->getTablePrefix() !== '') {
            throw new LogicException('Consent storage requires its supported unprefixed database.');
        }
        $steps = [];
        foreach (self::TABLES as $table) {
            $this->refuseShadow($pdo, $driver, $table);
            $steps[] = ['table', $table, $this->definition($driver, $table)];
        }
        foreach ($this->triggers($driver) as $name => $trigger) {
            $steps[] = ['trigger', $name, $trigger['sql']];
        }
        $known = array_keys($this->triggers($driver));
        foreach (self::TABLES as $table) {
            $statement = $pdo->prepare($driver === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=?" : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');
            $statement->execute([$table]);
            if (array_diff($statement->fetchAll(PDO::FETCH_COLUMN), $known) !== []) {
                throw new LogicException('Unexpected consent trigger requires inspection; nothing was changed.');
            }
        }
        // Prove every retained object and a contiguous known installation prefix BEFORE DDL.
        $this->refuseShadow($pdo, $driver, 'migrations');
        $recorded = false;
        if ($this->exists($pdo, $driver, 'table', 'migrations')) {
            $repository = $driver === 'sqlite' ? 'main."migrations"' : '`'.str_replace('`', '``', $database).'`.`migrations`';
            $statement = $pdo->prepare('SELECT COUNT(*) FROM '.$repository.' WHERE migration=?');
            $statement->execute(['2026_10_07_250000_customer_consent']);
            $recorded = (int) $statement->fetchColumn() !== 0;
        }
        $missing = false;
        foreach ($steps as [$type, $name, $sql]) {
            $exists = $this->exists($pdo, $driver, $type, $name);
            if ($exists && $missing) {
                throw new LogicException('Non-prefix consent installation requires inspection; nothing was changed.');
            }
            if ($exists) {
                $this->owned($pdo, $driver, $type, $name, $sql);
            } else {
                if ($recorded) {
                    throw new LogicException('Recorded consent schema is incomplete; inspection is required before any DDL.');
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
                throw new LogicException('Consent installation changed its captured database.');
            }
            foreach (self::TABLES as $table) {
                $this->refuseShadow($pdo, $driver, $table);
            }
            $this->owned($pdo, $driver, $type, $name, $sql);
        }
    }

    public function down(): void
    {
        throw new LogicException('Consent rollback is refused; retain its schema, evidence and migration record.');
    }

    private function columns(string $table): array
    {
        return match ($table) {
            'customer_consent_policies' => [
                'id' => ['bigint unsigned', 'integer', false, true], 'purpose' => ['varchar(40)', 'text'],
                'version' => ['varchar(80)', 'text'], 'notice' => ['text', 'text', false, false, true],
                'notice_hash' => ['char(64)', 'text'], 'review_reference' => ['varchar(200)', 'text', false, false, true],
                'policy_hash' => ['char(64)', 'text'], 'created_at' => ['timestamp', 'datetime'],
            ],
            'customer_consent_events' => [
                'id' => ['bigint unsigned', 'integer', false, true], 'public_id' => ['char(36)', 'text'],
                'customer_account_id' => ['bigint unsigned', 'integer'], 'purpose' => ['varchar(40)', 'text'],
                'revision' => ['int unsigned', 'integer'], 'status' => ['varchar(16)', 'text'],
                'policy_id' => ['bigint unsigned', 'integer', true], 'source' => ['varchar(32)', 'text'],
                'affirmative' => ['tinyint unsigned', 'integer'], 'recipient_hmac' => ['char(64)', 'text'],
                'recipient_ciphertext' => ['text', 'text'], 'created_at' => ['timestamp', 'datetime'],
            ],
            'customer_consent_states' => [
                'id' => ['bigint unsigned', 'integer', false, true], 'customer_account_id' => ['bigint unsigned', 'integer'],
                'purpose' => ['varchar(40)', 'text'], 'revision' => ['int unsigned', 'integer'],
                'event_id' => ['bigint unsigned', 'integer'], 'created_at' => ['timestamp', 'datetime'], 'updated_at' => ['timestamp', 'datetime'],
            ],
        };
    }

    private function indexes(string $table): array
    {
        return match ($table) {
            'customer_consent_policies' => [$table.'_purpose_version_unique' => [['purpose', 'version'], true]],
            'customer_consent_events' => [$table.'_public_unique' => [['public_id'], true], $table.'_account_revision_unique' => [['customer_account_id', 'purpose', 'revision'], true], $table.'_policy_index' => [['policy_id'], false]],
            'customer_consent_states' => [$table.'_account_unique' => [['customer_account_id', 'purpose'], true], $table.'_event_index' => [['event_id'], false]],
        };
    }

    private function foreign(string $table): array
    {
        return match ($table) {
            'customer_consent_policies' => [],
            'customer_consent_events' => ['customer_account_id' => 'customer_accounts', 'policy_id' => 'customer_consent_policies'],
            'customer_consent_states' => ['customer_account_id' => 'customer_accounts', 'event_id' => 'customer_consent_events'],
        };
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

        return 'CREATE TABLE '.($driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`').' ('.implode(', ', $parts).')'.($driver === 'mysql' ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VA retained customer consent v1'" : '');
    }

    private function triggers(string $driver): array
    {
        $hex = fn ($column) => $driver === 'sqlite' ? "length(NEW.$column)=64 AND NEW.$column NOT GLOB '*[^a-f0-9]*'" : "CHAR_LENGTH(NEW.$column)=64 AND NEW.$column REGEXP '^[a-f0-9]{64}$'";
        $bytes = fn ($column) => $driver === 'sqlite' ? 'length(CAST(NEW.'.$column.' AS BLOB))' : 'LENGTH(NEW.'.$column.')';
        $length = fn ($column) => ($driver === 'sqlite' ? 'length' : 'CHAR_LENGTH').'(NEW.'.$column.')';
        $policy = "NEW.purpose='email_marketing' AND ".$length('version').' BETWEEN 1 AND 80 AND '.$length('notice').' BETWEEN 1 AND 2000 AND '.$bytes('notice').'<=8000 AND '.$length('review_reference').' BETWEEN 1 AND 200 AND '.$bytes('review_reference').'<=800 AND '.$hex('notice_hash').' AND '.$hex('policy_hash')
            .' AND NOT EXISTS (SELECT 1 FROM customer_consent_policies WHERE id=NEW.id OR (purpose=NEW.purpose AND version=NEW.version))';
        $event = "NEW.purpose='email_marketing' AND NEW.source='first_party_customer' AND NEW.revision BETWEEN 1 AND 2147483646 AND ".$hex('recipient_hmac').' AND '.$bytes('recipient_ciphertext').' BETWEEN 1 AND 4096 AND '.$length('public_id').'=36'
            ." AND ((NEW.status='granted' AND NEW.affirmative=1 AND NEW.policy_id IS NOT NULL) OR (NEW.status='withdrawn' AND NEW.affirmative=0))"
            .' AND (NEW.policy_id IS NULL OR EXISTS (SELECT 1 FROM customer_consent_policies WHERE id=NEW.policy_id AND purpose=NEW.purpose))'
            .' AND NEW.revision=COALESCE((SELECT revision FROM customer_consent_states WHERE customer_account_id=NEW.customer_account_id AND purpose=NEW.purpose),0)+1'
            .' AND EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id WHERE a.id=NEW.customer_account_id AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)'
            .' AND NOT EXISTS (SELECT 1 FROM customer_consent_events WHERE id=NEW.id OR public_id=NEW.public_id OR (customer_account_id=NEW.customer_account_id AND purpose=NEW.purpose AND revision=NEW.revision))';
        $pointer = 'EXISTS (SELECT 1 FROM customer_consent_events WHERE id=NEW.event_id AND customer_account_id=NEW.customer_account_id AND purpose=NEW.purpose AND revision=NEW.revision)';
        $stateInsert = "NEW.purpose='email_marketing' AND NEW.revision=1 AND ".$pointer.' AND NOT EXISTS (SELECT 1 FROM customer_consent_states WHERE id=NEW.id OR (customer_account_id=NEW.customer_account_id AND purpose=NEW.purpose))';
        $stateUpdate = 'NEW.id=OLD.id AND NEW.customer_account_id=OLD.customer_account_id AND NEW.purpose=OLD.purpose AND NEW.created_at=OLD.created_at AND NEW.revision=OLD.revision+1 AND NEW.revision<=2147483646 AND NEW.updated_at>=OLD.updated_at AND '.$pointer;
        $result = [];
        foreach (['customer_consent_policies' => ['insert' => $policy, 'update' => '0=1', 'delete' => '0=1'], 'customer_consent_events' => ['insert' => $event, 'update' => '0=1', 'delete' => '0=1'], 'customer_consent_states' => ['insert' => $stateInsert, 'update' => $stateUpdate, 'delete' => '0=1']] as $table => $operations) {
            foreach ($operations as $event => $condition) {
                $name = $table.'_retain_'.$event;
                $body = $driver === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Retained consent graph refused') WHERE NOT ($condition); END"
                    : "BEGIN IF NOT ($condition) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained consent graph refused'; END IF; END";
                $result[$name] = ['table' => $table, 'event' => strtoupper($event), 'body' => $body, 'sql' => 'CREATE TRIGGER `'.$name.'` BEFORE '.strtoupper($event).' ON `'.$table.'` FOR EACH ROW '.$body];
            }
        }

        return $result;
    }

    private function exists(PDO $pdo, string $driver, string $type, string $name): bool
    {
        $sql = $driver === 'sqlite' ? 'SELECT COUNT(*) FROM main.sqlite_master WHERE lower(name)=?'
            : ($type === 'table' ? 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)=?' : 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND LOWER(TRIGGER_NAME)=?');
        $statement = $pdo->prepare($sql);
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() !== 0;
    }

    private function owned(PDO $pdo, string $driver, string $type, string $name, string $sql): void
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT type,sql FROM main.sqlite_master WHERE name=?');
            $statement->execute([$name]);
            if ($statement->fetch(PDO::FETCH_ASSOC) !== ['type' => $type, 'sql' => $sql]) {
                throw new LogicException('Unowned or drifted consent object requires inspection; nothing was changed.');
            }
            if ($type === 'table') {
                $indexes = $pdo->query("SELECT type,name FROM main.sqlite_master WHERE tbl_name='$name' AND type='index' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                $count = count(array_filter($this->indexes($name), fn ($index) => $index[1]));
                $expected = array_map(fn ($number) => ['type' => 'index', 'name' => 'sqlite_autoindex_'.$name.'_'.$number], range(1, $count));
                if ($indexes !== $expected) {
                    throw new LogicException('Drifted consent indexes require inspection; nothing was changed.');
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
                throw new LogicException('Drifted consent trigger requires inspection; nothing was changed.');
            }

            return;
        }
        $statement = $pdo->prepare('SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ($statement->fetch(PDO::FETCH_ASSOC) !== ['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => 'VA retained customer consent v1', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci']) {
            throw new LogicException('Drifted consent table requires inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $statement->execute([$name]);
        $expected = [];
        foreach ($this->columns($name) as $column => $definition) {
            $text = str_starts_with($definition[0], 'varchar') || str_starts_with($definition[0], 'char') || $definition[0] === 'text';
            $expected[] = ['COLUMN_NAME' => $column, 'COLUMN_TYPE' => $definition[0], 'IS_NULLABLE' => ($definition[2] ?? false) ? 'YES' : 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ($definition[3] ?? false) ? 'auto_increment' : '', 'COLLATION_NAME' => $text ? (($definition[4] ?? false) ? 'utf8mb4_unicode_ci' : 'ascii_bin') : null];
        }
        if ($statement->fetchAll(PDO::FETCH_ASSOC) !== $expected) {
            throw new LogicException('Drifted consent columns require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute([$name]);
        $expectedIndexes = ['PRIMARY' => [['id'], true]] + $this->indexes($name);
        $actualIndexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['INDEX_TYPE'] !== 'BTREE' || (int) $index['SEQ_IN_INDEX'] !== count($actualIndexes[$index['INDEX_NAME']][0] ?? []) + 1) {
                throw new LogicException('Drifted consent index requires inspection; nothing was changed.');
            }
            $actualIndexes[$index['INDEX_NAME']][0][] = $index['COLUMN_NAME'];
            $actualIndexes[$index['INDEX_NAME']][1] = (int) $index['NON_UNIQUE'] === 0;
        }
        ksort($actualIndexes);
        ksort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new LogicException('Drifted consent indexes require inspection; nothing was changed.');
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
            throw new LogicException('Drifted consent foreign keys require inspection; nothing was changed.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1 + count(array_filter($this->indexes($name), fn ($index) => $index[1])) + count($foreign)) {
            throw new LogicException('Unexpected consent constraints require inspection; nothing was changed.');
        }
    }

    private function refuseShadow(PDO $pdo, string $driver, string $table): void
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)=?');
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new LogicException('Temporary consent storage requires inspection.');
            }

            return;
        }
        try {
            $row = (array) $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($row['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new LogicException('Temporary consent storage requires inspection.');
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                throw $error;
            }
        }
    }
};
