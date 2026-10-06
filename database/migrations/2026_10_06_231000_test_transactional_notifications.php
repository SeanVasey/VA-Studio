<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['transactional_notices', 'transactional_notice_attempts'];

    public function up(): void
    {
        $this->preflightAbsent();
        foreach (self::TABLES as $table) {
            foreach ($this->statements($table) as $sql) {
                DB::statement($sql);
            }
        }
        foreach ($this->guards() as $sql) {
            DB::unprepared($sql);
        }
    }

    private function statements(string $name): array
    {
        $table = new Blueprint(DB::connection(), $name);
        if (DB::getDriverName() === 'mysql') {
            $table->engine = 'InnoDB';
        }
        $table->create();
        $table->id();
        // Keep one excess character visible to guards instead of allowing MySQL to trim it first.
        $this->identity($table, 'public_id', 37)->unique();
        if ($name === 'transactional_notices') {
            $this->identity($table, 'event_key', 128)->unique();
            $this->identity($table, 'notification_type', 32);
            foreach ($this->foreign($name) as $field => $parent) {
                $column = $table->foreignId($field)->index();
                if ($field === 'claim_id') {
                    $column->nullable();
                }
                $column->constrained($parent)->restrictOnDelete();
                if ($field === 'user_id') {
                    $table->unsignedInteger('access_version');
                }
            }
            $this->identity($table, 'policy_version', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $table->text('capture_ciphertext');
            foreach (['capture_hash', 'recipient_hmac', 'payload_hash', 'request_hmac'] as $field) {
                $this->identity($table, $field, 65);
            }
            $table->dateTime('created_at');
        } else {
            $table->foreignId('notice_id')->index()->constrained('transactional_notices')->restrictOnDelete();
            $table->unsignedInteger('number');
            $this->identity($table, 'token_hash', 65)->unique();
            $table->dateTime('started_at');
            $table->dateTime('lease_expires_at');
            $this->identity($table, 'state', 16);
            $this->identity($table, 'reason', 32)->nullable();
            $this->identity($table, 'receipt_hash', 65)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unique(['notice_id', 'number']);
        }

        return $table->toSql();
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function foreign(string $table): array
    {
        return $table === 'transactional_notices'
            ? ['account_id' => 'customer_accounts', 'user_id' => 'users', 'order_id' => 'orders',
                'activation_id' => 'test_fulfillment_activations', 'claim_id' => 'customer_purchase_claims']
            : ['notice_id' => 'transactional_notices'];
    }

    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $same = fn ($a, $b) => $sqlite ? "{$a} IS {$b} COLLATE BINARY" : "(BINARY {$a} <=> BINARY {$b})";
        $hex = fn ($field) => $sqlite
            ? "length(NEW.{$field}) = 64 AND length(CAST(NEW.{$field} AS BLOB)) = 64 AND NEW.{$field} NOT GLOB '*[^0-9a-f]*'"
            : "OCTET_LENGTH(NEW.{$field}) = 64 AND REGEXP_LIKE(NEW.{$field}, '^[0-9a-f]{64}$', 'c')";
        $uuid = $sqlite
            ? "length(NEW.public_id) = 36 AND length(CAST(NEW.public_id AS BLOB)) = 36 AND NEW.public_id NOT GLOB '*[^0-9a-f-]*'"
                ." AND substr(NEW.public_id,9,1) = '-' AND substr(NEW.public_id,14,2) = '-4' AND substr(NEW.public_id,19,1) = '-'"
                ." AND substr(NEW.public_id,20,1) IN ('8','9','a','b') AND substr(NEW.public_id,24,1) = '-' AND length(replace(NEW.public_id,'-','')) = 32"
            : "OCTET_LENGTH(NEW.public_id) = 36 AND REGEXP_LIKE(NEW.public_id, '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')";
        $date = fn ($field) => $sqlite
            ? "NEW.{$field} IS NOT NULL AND NEW.{$field} = strftime('%Y-%m-%d %H:%M:%S', NEW.{$field}, '+0 seconds')"
            : "NEW.{$field} IS NOT NULL AND YEAR(NEW.{$field}) > 0 AND MONTH(NEW.{$field}) > 0 AND DAY(NEW.{$field}) > 0";
        $event = $sqlite ? "'test_order_ready:' || f.public_id || ':' || a.public_id" : "CONCAT('test_order_ready:', f.public_id, ':', a.public_id)";
        $noticeValid = $uuid.' AND '.$hex('capture_hash').' AND '.$hex('recipient_hmac').' AND '.$hex('payload_hash').' AND '.$hex('request_hmac')
            .' AND '.$same('NEW.notification_type', "'test_order_ready'").' AND '.$same('NEW.policy_version', "'test-transactional-notification-v1'")
            .' AND '.$same('NEW.canonicalization_version', "'vasey-json-v1'").' AND '.$date('created_at')
            .' AND length(NEW.capture_ciphertext) BETWEEN 1 AND 16384'
            .' AND NOT EXISTS (SELECT 1 FROM transactional_notices WHERE id=NEW.id OR public_id=NEW.public_id OR event_key=NEW.event_key)'
            .' AND EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id JOIN orders o ON o.id=NEW.order_id'
            .' JOIN test_fulfillment_activations f ON f.order_id=o.id WHERE a.id=NEW.account_id AND u.id=NEW.user_id'
            .' AND a.active=1 AND a.access_version=NEW.access_version AND u.is_admin=0 AND u.email_verified_at IS NOT NULL'
            .' AND f.id=NEW.activation_id AND NEW.created_at>=f.activated_at AND '.$same('NEW.event_key', $event)
            .' AND (('.$same('a.owner_key', 'o.owner_key').' AND NEW.claim_id IS NULL) OR EXISTS'
            .' (SELECT 1 FROM customer_purchase_claims c WHERE c.id=NEW.claim_id AND c.order_id=o.id AND c.account_id=a.id)))';
        $ttl = $sqlite ? "NEW.lease_expires_at = datetime(NEW.started_at, '+30 seconds')"
            : 'TIMESTAMPDIFF(SECOND, NEW.started_at, NEW.lease_expires_at) = 30';
        $attemptValid = $uuid.' AND '.$hex('token_hash').' AND '.$date('started_at').' AND '.$date('lease_expires_at')." AND {$ttl}"
            ." AND NEW.number BETWEEN 1 AND 3 AND NEW.state='leased' AND NEW.reason IS NULL AND NEW.receipt_hash IS NULL AND NEW.finished_at IS NULL"
            .' AND NOT EXISTS (SELECT 1 FROM transactional_notice_attempts WHERE id=NEW.id OR public_id=NEW.public_id OR token_hash=NEW.token_hash OR (notice_id=NEW.notice_id AND number=NEW.number))'
            .' AND NEW.number=(SELECT COALESCE(MAX(number),0)+1 FROM transactional_notice_attempts WHERE notice_id=NEW.notice_id)'
            ." AND NOT EXISTS (SELECT 1 FROM transactional_notice_attempts WHERE notice_id=NEW.notice_id AND state<>'failed')"
            .' AND NOT EXISTS (SELECT 1 FROM transactional_notice_attempts WHERE notice_id=NEW.notice_id AND finished_at>NEW.started_at)'
            .' AND EXISTS (SELECT 1 FROM transactional_notices n JOIN customer_accounts a ON a.id=n.account_id JOIN users u ON u.id=n.user_id'
            .' WHERE n.id=NEW.notice_id AND NEW.started_at>=n.created_at AND a.user_id=u.id AND a.active=1 AND a.access_version=n.access_version AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)';
        $identity = implode(' AND ', array_map(fn ($field) => $same('NEW.'.$field, 'OLD.'.$field),
            ['id', 'public_id', 'notice_id', 'number', 'token_hash', 'started_at', 'lease_expires_at']));
        $finish = $date('finished_at').' AND NEW.finished_at>=OLD.started_at';
        $accepted = "NEW.state='accepted' AND ".$hex('receipt_hash').' AND NEW.receipt_hash IS NOT NULL';
        $transition = $identity.' AND '.$finish.' AND (('
            ."OLD.state='leased' AND (({$accepted} AND NEW.reason IS NULL AND NEW.finished_at<OLD.lease_expires_at)"
            ." OR (NEW.state='failed' AND NEW.reason='private_storage_refused' AND NEW.receipt_hash IS NULL AND NEW.finished_at<OLD.lease_expires_at)"
            ." OR (NEW.state='uncertain' AND NEW.receipt_hash IS NULL AND (NEW.reason='capture_unknown' OR (NEW.reason='lease_expired' AND NEW.finished_at>=OLD.lease_expires_at)))))"
            ." OR (OLD.state='uncertain' AND {$accepted} AND NEW.reason='capture_reconciled' AND NEW.finished_at>=OLD.finished_at))";
        $valid = ['transactional_notices' => ['insert' => $noticeValid, 'update' => '0=1', 'delete' => '0=1'],
            'transactional_notice_attempts' => ['insert' => $attemptValid, 'update' => $transition, 'delete' => '0=1']];
        $guards = [];
        foreach ($valid as $table => $operations) {
            foreach ($operations as $operation => $condition) {
                $name = $table.'_'.$operation;
                $guards[$name] = $sqlite
                    ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN NOT COALESCE(({$condition}),0) BEGIN SELECT RAISE(ABORT, 'Private notification evidence is retained'); END"
                    : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN IF NOT COALESCE(({$condition}),0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Private notification evidence is retained'; END IF; END";
            }
        }

        foreach ($guards as $name => $sql) {
            // ASCII PAD SPACE must not turn a trailing-space state/reason into a valid transition.
            $guards[$name] = preg_replace_callback('/\b((?:NEW|OLD)\.(?:state|reason)|state)(=|<>)(\'[^\']*\')/',
                fn (array $match): string => ($match[2] === '<>' ? 'NOT ' : '').'('.$same($match[1], $match[3]).')', $sql);
        }

        return $guards;
    }

    /** Refuse every permanent/temporary/aliased object before the first DDL; do not adopt partial evidence. */
    private function preflightAbsent(): void
    {
        $this->preflightObjects();
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                throw new LogicException('Existing notification objects require investigation before migration.');
            }
            foreach ($this->statements($table) as $statement) {
                if (DB::getDriverName() === 'sqlite' && preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match)) {
                    foreach (['sqlite_master', 'sqlite_temp_master'] as $schema) {
                        if (DB::table($schema)->whereRaw('name COLLATE NOCASE = ?', [$match[1]])->exists()) {
                            throw new LogicException('Foreign notification index refused.');
                        }
                    }
                }
                if (DB::getDriverName() === 'mysql' && preg_match('/\bconstraint `([^`]+)`/', $statement, $match)
                    && DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(CONSTRAINT_NAME) = ?', [$match[1]])->exists()) {
                    throw new LogicException('Foreign notification constraint refused.');
                }
            }
        }
        foreach (array_keys($this->guards()) as $name) {
            if ($this->trigger($name) !== null) {
                throw new LogicException('Existing notification guard refused.');
            }
        }
    }

    private function preflightObjects(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Notification evidence requires SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (DB::getDriverName() === 'sqlite') {
                $names = [$table, ...array_keys($this->guards())];
                foreach ($this->statements($table) as $statement) {
                    if (preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match)) {
                        $names[] = $match[1];
                    }
                }
                if (DB::table('sqlite_temp_master')->whereRaw('tbl_name COLLATE NOCASE = ?', [$table])->orWhereIn(DB::raw('name COLLATE NOCASE'), $names)->exists()) {
                    throw new LogicException('Temporary notification objects refused.');
                }
                $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->get();
                if ($objects->count() > 1 || ($objects->isNotEmpty() && ($objects[0]->name !== $table || $objects[0]->type !== 'table'))) {
                    throw new LogicException('Unexpected notification table identity.');
                }
            } else {
                $objects = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
                if ($objects->count() > 1 || ($objects->isNotEmpty() && ($objects[0]->TABLE_NAME !== $table || $objects[0]->TABLE_TYPE !== 'BASE TABLE' || $objects[0]->ENGINE !== 'InnoDB'))) {
                    throw new LogicException('Unexpected notification table identity.');
                }
                try {
                    $definition = DB::selectOne('SHOW CREATE TABLE '.$table);
                    if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                        throw new LogicException('Temporary notification table refused.');
                    }
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
        }
    }

    private function trigger(string $name): ?object
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->first();
        }
        $matches = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
        if ($matches->count() > 1) {
            throw new LogicException('Aliased notification guard refused.');
        }

        return $matches->first();
    }

    public function down(): void
    {
        $this->preflightObjects();
        $present = array_map(fn ($table) => Schema::hasTable($table), self::TABLES);
        if (count(array_unique($present)) !== 1) {
            throw new LogicException('Partial notification schema prevents rollback.');
        }
        foreach ($this->guards() as $name => $sql) {
            $trigger = $this->trigger($name);
            $table = substr($name, 0, strrpos($name, '_'));
            $operation = strtoupper(substr($name, strrpos($name, '_') + 1));
            $matches = $present[0] && $trigger && (DB::getDriverName() === 'sqlite'
                ? $trigger->type === 'trigger' && $trigger->name === $name && $trigger->tbl_name === $table && $trigger->sql === $sql
                : $trigger->TRIGGER_NAME === $name && $trigger->EVENT_OBJECT_TABLE === $table && $trigger->ACTION_TIMING === 'BEFORE'
                    && $trigger->EVENT_MANIPULATION === $operation && $trigger->ACTION_STATEMENT === explode(' FOR EACH ROW ', $sql, 2)[1]);
            if (($present[0] || $trigger !== null) && ! $matches) {
                throw new LogicException('Unexpected notification guard prevents rollback.');
            }
        }
        if (! $present[0]) {
            return;
        }
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Populated notification rollback requires a separately approved retention workflow.');
            }
            $this->ownedTable($table);
        }
        $this->refuseExternalReferences();
        foreach (array_keys($this->guards()) as $name) {
            DB::unprepared('DROP TRIGGER '.$name);
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
    }

    private function types(string $table): array
    {
        return $table === 'transactional_notices' ? [
            'id' => 'bigint unsigned', 'public_id' => 'varchar(37)', 'event_key' => 'varchar(128)', 'notification_type' => 'varchar(32)',
            'account_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'access_version' => 'int unsigned',
            'order_id' => 'bigint unsigned', 'activation_id' => 'bigint unsigned', 'claim_id' => 'bigint unsigned',
            'policy_version' => 'varchar(64)', 'canonicalization_version' => 'varchar(32)', 'capture_ciphertext' => 'text',
            'capture_hash' => 'varchar(65)', 'recipient_hmac' => 'varchar(65)', 'payload_hash' => 'varchar(65)', 'request_hmac' => 'varchar(65)', 'created_at' => 'datetime',
        ] : ['id' => 'bigint unsigned', 'public_id' => 'varchar(37)', 'notice_id' => 'bigint unsigned', 'number' => 'int unsigned',
            'token_hash' => 'varchar(65)', 'started_at' => 'datetime', 'lease_expires_at' => 'datetime', 'state' => 'varchar(16)',
            'reason' => 'varchar(32)', 'receipt_hash' => 'varchar(65)', 'finished_at' => 'datetime'];
    }

    /** Every table, column, key, trigger and option must be owned before any rollback DDL. */
    private function ownedTable(string $table): void
    {
        $types = $this->types($table);
        $nullable = $table === 'transactional_notices' ? ['claim_id'] : ['reason', 'receipt_hash', 'finished_at'];
        if (DB::getDriverName() === 'sqlite' && DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql')
            !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $this->statements($table)[0])) {
            throw new LogicException('Unexpected notification table definition.');
        }
        $columns = Schema::getColumns($table);
        if (array_column($columns, 'name') !== array_keys($types)) {
            throw new LogicException('Unexpected notification columns.');
        }
        foreach ($columns as $column) {
            if ($column['nullable'] !== in_array($column['name'], $nullable, true) || $column['default'] !== null
                || (DB::getDriverName() === 'mysql' && $column['type'] !== $types[$column['name']])) {
                throw new LogicException('Unexpected notification column definition.');
            }
        }
        if (DB::getDriverName() === 'mysql') {
            $storage = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->sole();
            if ($storage->TABLE_COLLATION !== DB::connection()->getConfig('collation') || $storage->CREATE_OPTIONS !== '' || $storage->TABLE_COMMENT !== '') {
                throw new LogicException('Unexpected notification storage options.');
            }
            foreach (DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $column) {
                $expectedCollation = str_starts_with($types[$column->COLUMN_NAME], 'varchar') ? 'ascii_bin'
                    : ($column->COLUMN_NAME === 'capture_ciphertext' ? DB::connection()->getConfig('collation') : null);
                if ($column->COLLATION_NAME !== $expectedCollation || $column->COLUMN_COMMENT !== '' || $column->GENERATION_EXPRESSION !== ''
                    || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                    throw new LogicException('Unexpected notification column attributes.');
                }
            }
            if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
                throw new LogicException('Unexpected notification check constraint.');
            }
        }
        $expectedIndexes = ['primary' => [['id'], true], $table.'_public_id_unique' => [['public_id'], true]];
        foreach (array_keys($this->foreign($table)) as $field) {
            $expectedIndexes[$table.'_'.$field.'_index'] = [[$field], false];
        }
        $expectedIndexes += $table === 'transactional_notices' ? [$table.'_event_key_unique' => [['event_key'], true]]
            : [$table.'_token_hash_unique' => [['token_hash'], true], $table.'_notice_id_number_unique' => [['notice_id', 'number'], true]];
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['primary'] ? 'primary' : $index['name'];
            if (! isset($expectedIndexes[$name]) || [$index['columns'], $index['unique']] !== $expectedIndexes[$name]
                || $index['type'] !== (DB::getDriverName() === 'mysql' ? 'btree' : null)) {
                throw new LogicException('Unexpected notification index.');
            }
            if (DB::getDriverName() === 'sqlite' && ! $index['primary']) {
                $expectedSql = null;
                foreach ($this->statements($table) as $statement) {
                    if (str_contains($statement, 'index "'.$name.'" ')) {
                        $expectedSql = preg_replace_callback('/\Acreate (unique )?index /',
                            fn ($match) => 'CREATE '.(isset($match[1]) ? 'UNIQUE ' : '').'INDEX ', $statement);
                    }
                }
                if ($expectedSql === null || DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->value('sql') !== $expectedSql) {
                    throw new LogicException('Unexpected notification index SQL.');
                }
            }
            unset($expectedIndexes[$name]);
        }
        if ($expectedIndexes !== []) {
            throw new LogicException('Missing notification index.');
        }
        $foreign = $this->foreign($table);
        foreach (Schema::getForeignKeys($table) as $key) {
            $field = $key['columns'][0] ?? '';
            if (! isset($foreign[$field]) || $key['columns'] !== [$field] || $key['foreign_table'] !== $foreign[$field]
                || $key['foreign_columns'] !== ['id'] || $key['foreign_schema'] !== (DB::getDriverName() === 'sqlite' ? 'main' : DB::getDatabaseName())
                || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action'
                || (DB::getDriverName() === 'mysql' && $key['name'] !== $table.'_'.$field.'_foreign')) {
                throw new LogicException('Unexpected notification foreign key.');
            }
            unset($foreign[$field]);
        }
        if ($foreign !== []) {
            throw new LogicException('Missing notification foreign key.');
        }
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->count()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', $table)->count();
        if ($triggers !== 3) {
            throw new LogicException('Additional notification guards prevent rollback.');
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $part) {
                if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                    throw new LogicException('Unexpected notification index attributes.');
                }
            }
        }
    }

    private function refuseExternalReferences(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())->whereIn('REFERENCED_TABLE_NAME', self::TABLES)
                ->where(fn ($query) => $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())->orWhereNotIn('TABLE_NAME', self::TABLES))->exists()) {
                throw new LogicException('Foreign notification references prevent rollback.');
            }

            return;
        }
        foreach (DB::table('sqlite_master')->where('type', 'table')->whereNotIn('name', self::TABLES)->pluck('name') as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array(strtolower($key['foreign_table']), self::TABLES, true)) {
                    throw new LogicException('Foreign notification references prevent rollback.');
                }
            }
        }
    }
};
