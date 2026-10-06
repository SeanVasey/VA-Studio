<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['customer_identity_addresses', 'customer_identity_challenges'];

    public function up(): void
    {
        $this->preflight();
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                throw new LogicException('Existing customer challenge schema requires investigation.');
            }
        }
        foreach ($this->guards() as $name => $guard) {
            if ($this->trigger($name) !== null) {
                throw new LogicException('Existing customer challenge guard requires investigation.');
            }
        }
        // SQLite index names and MySQL foreign-key names are schema-wide. Check all before the first CREATE.
        foreach (self::TABLES as $table) {
            foreach ($this->tableStatements($table) as $statement) {
                if (DB::getDriverName() === 'sqlite' && preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match)
                    && DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$match[1]])->exists()) {
                    throw new LogicException('Foreign customer challenge index name refused.');
                }
                if (DB::getDriverName() === 'mysql' && preg_match('/\bconstraint `([^`]+)`/', $statement, $match)
                    && DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(CONSTRAINT_NAME) = ?', [$match[1]])->exists()) {
                    throw new LogicException('Foreign customer challenge constraint name refused.');
                }
            }
        }
        foreach (self::TABLES as $table) {
            foreach ($this->tableStatements($table) as $statement) {
                DB::statement($statement);
            }
        }
        foreach ($this->guards() as $guard) {
            DB::unprepared($guard['sql']);
        }
    }

    private function tableStatements(string $name): array
    {
        $table = new Blueprint(DB::connection(), $name);
        $table->create();
        if ($name === 'customer_identity_addresses') {
            $table->char('address_key', 64)->primary();
        } else {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('address_key', 64);
            $table->foreign('address_key')->references('address_key')->on('customer_identity_addresses')->restrictOnDelete();
            $table->char('request_hash', 64)->unique();
            $table->string('purpose', 8);
            $table->string('policy_version', 64);
            $table->text('email');
            $table->char('proof_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->unsignedInteger('access_version')->nullable();
            $table->char('credential_stamp', 64)->nullable();
            $table->string('state', 12);
            $table->dateTime('created_at');
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('result_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('result_account_id')->nullable()->constrained('customer_accounts')->restrictOnDelete();
            $table->unsignedInteger('result_access_version')->nullable();
            $table->char('result_stamp', 64)->nullable();
            $table->char('completion_hash', 64)->nullable();
            $table->index(['address_key', 'created_at']);
        }

        return $table->toSql();
    }

    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $fields = ['id', 'public_id', 'address_key', 'request_hash', 'purpose', 'policy_version', 'email', 'proof_hash', 'user_id', 'account_id', 'access_version', 'credential_stamp', 'created_at', 'expires_at'];
        $changed = implode(' OR ', array_map(fn ($field) => $sqlite ? "NEW.$field IS NOT OLD.$field" : "NOT (BINARY NEW.$field <=> BINARY OLD.$field)", $fields));
        $state = $sqlite ? 'NEW.state' : 'BINARY NEW.state';
        $oldState = $sqlite ? 'OLD.state' : 'BINARY OLD.state';
        $purpose = $sqlite ? 'NEW.purpose' : 'BINARY NEW.purpose';
        $hex = fn (string $field): string => $sqlite
            ? "length(NEW.$field) != 64 OR length(CAST(NEW.$field AS BLOB)) != 64 OR NEW.$field GLOB '*[^0-9a-f]*'"
            : "OCTET_LENGTH(NEW.$field) != 64 OR NOT REGEXP_LIKE(NEW.$field, '^[0-9a-f]{64}$', 'c')";
        $uuid = $sqlite
            ? "length(NEW.public_id) != 36 OR length(CAST(NEW.public_id AS BLOB)) != 36 OR NEW.public_id GLOB '*[^0-9a-f-]*' OR substr(NEW.public_id,9,1) != '-' OR substr(NEW.public_id,14,2) != '-4' OR substr(NEW.public_id,19,1) != '-' OR substr(NEW.public_id,20,1) NOT IN ('8','9','a','b') OR substr(NEW.public_id,24,1) != '-' OR length(replace(NEW.public_id,'-','')) != 32"
            : "NOT REGEXP_LIKE(NEW.public_id, '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')";
        $originAbsent = 'NEW.user_id IS NULL AND NEW.account_id IS NULL AND NEW.access_version IS NULL AND NEW.credential_stamp IS NULL';
        $originValid = 'NEW.user_id IS NOT NULL AND NEW.account_id IS NOT NULL AND NEW.access_version BETWEEN 1 AND 4294967295 AND NEW.credential_stamp IS NOT NULL';
        $account = 'EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id WHERE a.id=NEW.account_id AND a.user_id=NEW.user_id AND a.access_version=NEW.access_version AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)';
        $resultAccount = 'EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id WHERE a.id=NEW.result_account_id AND a.user_id=NEW.result_user_id AND a.access_version=NEW.result_access_version AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)';
        $invalidUpdate = "$changed OR $oldState != 'pending' OR $state != 'completed' OR NEW.completed_at IS NULL OR NEW.completed_at < NEW.created_at OR NEW.completed_at >= NEW.expires_at OR NEW.result_user_id IS NULL OR NEW.result_account_id IS NULL OR NEW.result_access_version IS NULL OR NEW.result_access_version NOT BETWEEN 1 AND 4294967295 OR NEW.result_stamp IS NULL OR NEW.completion_hash IS NULL OR ".$hex('result_stamp').' OR '.$hex('completion_hash')." OR NOT $resultAccount OR ($purpose = 'recover' AND (NEW.result_user_id != NEW.user_id OR NEW.result_account_id != NEW.account_id OR NEW.result_access_version != NEW.access_version))";
        // REPLACE must be stopped before its implicit DELETE, including SQLite with recursive triggers off.
        $collision = 'EXISTS (SELECT 1 FROM customer_identity_challenges WHERE id=NEW.id OR public_id=NEW.public_id OR request_hash=NEW.request_hash)';
        $invalidInsert = "$collision OR $uuid OR ".implode(' OR ', array_map($hex, ['address_key', 'request_hash', 'proof_hash']))." OR $state NOT IN ('pending','unavailable') OR $purpose NOT IN ('enroll','recover') OR NEW.expires_at <= NEW.created_at OR (($state = 'unavailable' OR $purpose = 'enroll') AND NOT ($originAbsent)) OR ($state = 'pending' AND $purpose = 'recover' AND (NOT ($originValid) OR NEW.access_version IS NULL OR ".$hex('credential_stamp')." OR NOT $account)) OR NEW.completed_at IS NOT NULL OR NEW.result_user_id IS NOT NULL OR NEW.result_account_id IS NOT NULL OR NEW.result_access_version IS NOT NULL OR NEW.result_stamp IS NOT NULL OR NEW.completion_hash IS NOT NULL";
        $guards = [];
        foreach (['insert' => $invalidInsert, 'update' => $invalidUpdate, 'delete' => '1=1'] as $operation => $condition) {
            $name = 'customer_identity_challenges_'.$operation;
            $body = "BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer challenge identity is retained'; END IF; END";
            $sql = $sqlite
                ? "CREATE TRIGGER $name BEFORE $operation ON customer_identity_challenges WHEN $condition BEGIN SELECT RAISE(ABORT, 'Customer challenge identity is retained'); END"
                : "CREATE TRIGGER $name BEFORE $operation ON customer_identity_challenges FOR EACH ROW $body";
            $guards[$name] = ['sql' => $sql, 'body' => $body, 'operation' => strtoupper($operation)];
        }

        return $guards;
    }

    private function trigger(string $name): ?object
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->first()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->first();
    }

    private function preflight(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Customer challenges require SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (DB::getDriverName() === 'sqlite') {
                if (DB::table('sqlite_temp_master')->whereRaw('tbl_name COLLATE NOCASE = ?', [$table])->orWhereIn(DB::raw('name COLLATE NOCASE'), [$table, ...array_keys($this->guards())])->exists()) {
                    throw new LogicException('Temporary customer challenge objects refused.');
                }
                $object = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->first();
                if ($object && ($object->type !== 'table' || $object->name !== $table)) {
                    throw new LogicException('Unexpected customer challenge object.');
                }
            } else {
                $objects = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
                if ($objects->count() > 1 || ($objects->isNotEmpty() && ($objects[0]->TABLE_NAME !== $table || $objects[0]->TABLE_TYPE !== 'BASE TABLE' || $objects[0]->ENGINE !== 'InnoDB'))) {
                    throw new LogicException('Unexpected customer challenge table identity.');
                }
                try {
                    $object = DB::selectOne('SHOW CREATE TABLE '.$table);
                    if (str_starts_with($object->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                        throw new LogicException('Temporary customer challenge table refused.');
                    }
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $this->preflight();
        $exists = Schema::hasTable('customer_identity_challenges');
        $addresses = Schema::hasTable('customer_identity_addresses');
        if ($exists !== $addresses) {
            throw new LogicException('Incomplete customer challenge schema requires investigation.');
        }
        if ($exists) {
            $this->ownedSchema();
            $this->refuseExternalReferences();
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException('Customer challenge evidence requires an approved retention workflow.');
            }
        }
        foreach ($this->guards() as $name => $guard) {
            $trigger = $this->trigger($name);
            $matches = $exists && $trigger && (DB::getDriverName() === 'sqlite'
                ? $trigger->type === 'trigger' && $trigger->name === $name && $trigger->tbl_name === 'customer_identity_challenges' && $trigger->sql === $guard['sql']
                : $trigger->TRIGGER_NAME === $name && $trigger->EVENT_OBJECT_TABLE === 'customer_identity_challenges' && $trigger->ACTION_TIMING === 'BEFORE' && $trigger->EVENT_MANIPULATION === $guard['operation'] && $trigger->ACTION_STATEMENT === $guard['body']);
            if (($exists || $trigger) && ! $matches) {
                throw new LogicException('Unexpected customer challenge guards; rollback refused.');
            }
        }
        if ($exists) {
            foreach (array_keys($this->guards()) as $name) {
                DB::unprepared('DROP TRIGGER '.$name);
            }
            Schema::drop('customer_identity_challenges');
        }
        Schema::dropIfExists('customer_identity_addresses');
    }

    /** Refuse collateral DDL on a same-named empty foreign table or altered key. */
    private function ownedSchema(): void
    {
        $types = ['id' => 'bigint unsigned', 'public_id' => 'char(36)', 'address_key' => 'char(64)', 'request_hash' => 'char(64)',
            'purpose' => 'varchar(8)', 'policy_version' => 'varchar(64)', 'email' => 'text', 'proof_hash' => 'char(64)',
            'user_id' => 'bigint unsigned', 'account_id' => 'bigint unsigned', 'access_version' => 'int unsigned', 'credential_stamp' => 'char(64)',
            'state' => 'varchar(12)', 'created_at' => 'datetime', 'expires_at' => 'datetime', 'completed_at' => 'datetime',
            'result_user_id' => 'bigint unsigned', 'result_account_id' => 'bigint unsigned', 'result_access_version' => 'int unsigned',
            'result_stamp' => 'char(64)', 'completion_hash' => 'char(64)'];
        $nullable = ['user_id', 'account_id', 'access_version', 'credential_stamp', 'completed_at', 'result_user_id', 'result_account_id', 'result_access_version', 'result_stamp', 'completion_hash'];
        foreach (['customer_identity_addresses' => ['address_key' => 'char(64)'], 'customer_identity_challenges' => $types] as $table => $expected) {
            if (DB::getDriverName() === 'sqlite') {
                $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
                if ($sql !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $this->tableStatements($table)[0])) {
                    throw new LogicException('Unexpected customer challenge table definition.');
                }
            }
            $columns = Schema::getColumns($table);
            if (array_column($columns, 'name') !== array_keys($expected)) {
                throw new LogicException('Unexpected customer challenge columns.');
            }
            foreach ($columns as $column) {
                if ($column['nullable'] !== in_array($column['name'], $nullable, true) || $column['default'] !== null
                    || (DB::getDriverName() === 'mysql' && $column['type'] !== $expected[$column['name']])) {
                    throw new LogicException('Unexpected customer challenge column definition.');
                }
            }
            if (DB::getDriverName() === 'mysql') {
                $collation = DB::connection()->getConfig('collation');
                $definition = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->sole();
                if ($definition->TABLE_COLLATION !== $collation || $definition->CREATE_OPTIONS !== '' || $definition->TABLE_COMMENT !== '') {
                    throw new LogicException('Unexpected customer challenge table options.');
                }
                foreach (DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $column) {
                    $text = preg_match('/^(?:char|varchar|text)/', $expected[$column->COLUMN_NAME]);
                    if ($column->COLLATION_NAME !== ($text ? $collation : null) || $column->GENERATION_EXPRESSION !== '' || $column->COLUMN_COMMENT !== ''
                        || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                        throw new LogicException('Unexpected customer challenge column attributes.');
                    }
                }
                if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
                    throw new LogicException('Unexpected customer challenge check constraint.');
                }
            }
            $this->ownedIndexes($table);
            $actualForeign = Schema::getForeignKeys($table);
            $foreign = $table === 'customer_identity_addresses' ? [] : ['address_key' => ['customer_identity_addresses', 'address_key'],
                'user_id' => ['users', 'id'], 'account_id' => ['customer_accounts', 'id'], 'result_user_id' => ['users', 'id'], 'result_account_id' => ['customer_accounts', 'id']];
            if (count($actualForeign) !== count($foreign)) {
                throw new LogicException('Unexpected customer challenge foreign keys.');
            }
            foreach ($actualForeign as $key) {
                $column = $key['columns'][0] ?? '';
                if (! isset($foreign[$column]) || $key['columns'] !== [$column] || [$key['foreign_table'], $key['foreign_columns'][0] ?? ''] !== $foreign[$column]
                    || $key['foreign_schema'] !== (DB::getDriverName() === 'sqlite' ? 'main' : DB::getDatabaseName()) || count($key['foreign_columns']) !== 1 || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action') {
                    throw new LogicException('Unexpected customer challenge foreign key definition.');
                }
                unset($foreign[$column]);
            }
            $triggers = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->count()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', $table)->count();
            if ($triggers !== ($table === 'customer_identity_addresses' ? 0 : 3)) {
                throw new LogicException('Unexpected customer challenge table guards.');
            }
        }
    }

    /** An unrelated child must not make DROP fail after the permanent guards have been removed. */
    private function refuseExternalReferences(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
                ->whereIn('REFERENCED_TABLE_NAME', self::TABLES)
                ->where(fn ($query) => $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())->orWhereNotIn('TABLE_NAME', self::TABLES))->exists()) {
                throw new LogicException('Foreign customer challenge references prevent rollback.');
            }

            return;
        }
        foreach (DB::table('sqlite_master')->where('type', 'table')->whereNotIn('name', self::TABLES)->pluck('name') as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array(strtolower($key['foreign_table']), self::TABLES, true)) {
                    throw new LogicException('Foreign customer challenge references prevent rollback.');
                }
            }
        }
    }

    private function ownedIndexes(string $table): void
    {
        $expected = $table === 'customer_identity_addresses' ? ['primary' => [['address_key'], true]] : [
            'primary' => [['id'], true], 'customer_identity_challenges_public_id_unique' => [['public_id'], true],
            'customer_identity_challenges_request_hash_unique' => [['request_hash'], true],
            'customer_identity_challenges_address_key_created_at_index' => [['address_key', 'created_at'], false],
        ];
        if ($table === 'customer_identity_challenges' && DB::getDriverName() === 'mysql') {
            foreach (['user_id', 'account_id', 'result_user_id', 'result_account_id'] as $column) {
                $expected[$table.'_'.$column.'_foreign'] = [[$column], false];
            }
        }
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['primary'] ? 'primary' : $index['name'];
            if (! isset($expected[$name]) || [$index['columns'], $index['unique']] !== $expected[$name]
                || $index['type'] !== (DB::getDriverName() === 'mysql' ? 'btree' : null)) {
                throw new LogicException('Unexpected customer challenge index definition.');
            }
            unset($expected[$name]);
        }
        if ($expected !== []) {
            throw new LogicException('Missing customer challenge index.');
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $part) {
                if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                    throw new LogicException('Unexpected customer challenge index attributes.');
                }
            }
        } else {
            foreach (DB::select('PRAGMA index_list('.$table.')') as $index) {
                if ($index->partial !== 0) {
                    throw new LogicException('Unexpected partial customer challenge index.');
                }
                foreach (DB::select('PRAGMA index_xinfo('.DB::connection()->getPdo()->quote($index->name).')') as $part) {
                    if ($part->key === 1 && ($part->cid < 0 || $part->coll !== 'BINARY' || $part->desc !== 0)) {
                        throw new LogicException('Unexpected customer challenge index expression.');
                    }
                }
            }
        }
    }
};
