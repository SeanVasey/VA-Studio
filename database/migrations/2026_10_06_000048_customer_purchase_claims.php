<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['customer_purchase_challenges', 'customer_purchase_claims'];

    public function up(): void
    {
        $this->preflight();
        foreach (self::TABLES as $name) {
            foreach ($this->statements($name) as $statement) {
                DB::statement($statement);
            }
        }
        foreach ($this->guards() as $statement) {
            DB::unprepared($statement);
        }
    }

    private function statements(string $name): array
    {
        $table = new Blueprint(DB::connection(), $name);
        $table->create();
        $table->id();
        $table->uuid('public_id')->unique();
        $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
        if ($name === 'customer_purchase_challenges') {
            $table->char('owner_key', 64);
            $table->char('order_hash', 64);
            $table->char('proof_hash', 64)->unique();
            $table->string('policy_version', 64);
            $table->dateTime('created_at');
            $table->dateTime('expires_at');
            $table->index(['order_id', 'created_at']);
        } else {
            $table->unique('order_id');
            $table->foreignId('challenge_id')->unique()->constrained('customer_purchase_challenges')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->text('evidence_ciphertext');
            $table->char('evidence_hash', 64);
            $table->dateTime('claimed_at');
            $table->index(['account_id', 'order_id']);
        }

        return $table->toSql();
    }

    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $hex = fn ($field) => $sqlite ? "length(NEW.$field) != 64 OR length(CAST(NEW.$field AS BLOB)) != 64 OR NEW.$field GLOB '*[^0-9a-f]*'"
            : "OCTET_LENGTH(NEW.$field) != 64 OR NOT REGEXP_LIKE(NEW.$field, '^[0-9a-f]{64}$', 'c')";
        $uuid = $sqlite ? "length(NEW.public_id) != 36 OR NEW.public_id GLOB '*[^0-9a-f-]*' OR substr(NEW.public_id,9,1) != '-' OR substr(NEW.public_id,14,2) != '-4' OR substr(NEW.public_id,19,1) != '-' OR substr(NEW.public_id,20,1) NOT IN ('8','9','a','b') OR substr(NEW.public_id,24,1) != '-' OR length(replace(NEW.public_id,'-','')) != 32"
            : "NOT REGEXP_LIKE(NEW.public_id, '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')";
        $same = fn ($a, $b) => $sqlite ? "$a = $b COLLATE BINARY" : "BINARY $a = BINARY $b";
        $ttl = $sqlite ? "NEW.expires_at != datetime(NEW.created_at, '+600 seconds')" : 'TIMESTAMPDIFF(SECOND, NEW.created_at, NEW.expires_at) != 600';
        $date = fn ($field) => $sqlite ? "NEW.$field IS NULL OR datetime(NEW.$field, '+0 seconds') IS NULL OR NEW.$field != strftime('%Y-%m-%d %H:%M:%S', NEW.$field, '+0 seconds')" : "NEW.$field IS NULL";
        $conditions = [
            'customer_purchase_challenges' => "$uuid OR ".$hex('owner_key').' OR '.$hex('order_hash').' OR '.$hex('proof_hash')
                .' OR NOT ('.$same('NEW.policy_version', "'guest-test-purchase-v1'").") OR $ttl OR ".$date('created_at').' OR '.$date('expires_at')
                .' OR EXISTS (SELECT 1 FROM customer_purchase_challenges WHERE id=NEW.id OR public_id=NEW.public_id OR proof_hash=NEW.proof_hash)'
                .' OR NOT EXISTS (SELECT 1 FROM orders o JOIN order_finalizations f ON f.order_id=o.id WHERE o.id=NEW.order_id AND '.$same('o.owner_key', 'NEW.owner_key').' AND '.$same('o.payload_hash', 'NEW.order_hash')." AND f.outcome='paid')"
                .' OR EXISTS (SELECT 1 FROM customer_accounts WHERE '.$same('owner_key', 'NEW.owner_key').')',
            'customer_purchase_claims' => "$uuid OR ".$hex('evidence_hash').' OR length(NEW.evidence_ciphertext) < 1 OR length(NEW.evidence_ciphertext) > 16384 OR '.$date('claimed_at')
                .' OR EXISTS (SELECT 1 FROM customer_purchase_claims WHERE id=NEW.id OR public_id=NEW.public_id OR order_id=NEW.order_id OR challenge_id=NEW.challenge_id)'
                .' OR NOT EXISTS (SELECT 1 FROM customer_purchase_challenges c JOIN orders o ON o.id=c.order_id WHERE c.id=NEW.challenge_id AND c.order_id=NEW.order_id AND '.$same('c.owner_key', 'o.owner_key').' AND '.$same('c.order_hash', 'o.payload_hash').' AND NEW.claimed_at >= c.created_at AND NEW.claimed_at < c.expires_at)'
                .' OR NOT EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id=a.user_id WHERE a.id=NEW.account_id AND a.active=1 AND u.is_admin=0 AND u.email_verified_at IS NOT NULL)',
        ];
        $guards = [];
        foreach ($conditions as $table => $insert) {
            foreach (['insert' => $insert, 'update' => '1=1', 'delete' => '1=1'] as $operation => $when) {
                $name = $table.'_'.$operation;
                $guards[$name] = $sqlite
                    ? "CREATE TRIGGER $name BEFORE $operation ON $table WHEN $when BEGIN SELECT RAISE(ABORT, 'Purchase access evidence is retained'); END"
                    : "CREATE TRIGGER $name BEFORE $operation ON $table FOR EACH ROW BEGIN IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase access evidence is retained'; END IF; END";
            }
        }

        return $guards;
    }

    /** Refuse partial/foreign/temporary objects before the first DDL statement. Never adopt existing evidence. */
    private function preflight(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Purchase claims require MySQL or SQLite.');
        }
        foreach (self::TABLES as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['sqlite_master', 'sqlite_temp_master'] as $schema) {
                    if (DB::table($schema)->whereRaw('name COLLATE NOCASE = ?', [$table])->orWhereRaw('tbl_name COLLATE NOCASE = ?', [$table])->exists()) {
                        throw new LogicException('Existing purchase claim objects require investigation.');
                    }
                }
            } else {
                if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->exists()) {
                    throw new LogicException('Existing purchase claim objects require investigation.');
                }
                try {
                    DB::selectOne('SHOW CREATE TABLE '.$table);
                    throw new LogicException('Existing or temporary purchase claim objects refused.');
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
            foreach ($this->statements($table) as $statement) {
                if (DB::getDriverName() === 'sqlite' && preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match)) {
                    foreach (['sqlite_master', 'sqlite_temp_master'] as $schema) {
                        if (DB::table($schema)->whereRaw('name COLLATE NOCASE = ?', [$match[1]])->exists()) {
                            throw new LogicException('Foreign purchase claim index refused.');
                        }
                    }
                }
                if (DB::getDriverName() === 'mysql' && preg_match('/\bconstraint `([^`]+)`/', $statement, $match)
                    && DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(CONSTRAINT_NAME) = ?', [$match[1]])->exists()) {
                    throw new LogicException('Foreign purchase claim constraint refused.');
                }
            }
        }
        foreach (array_keys($this->guards()) as $name) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['sqlite_master', 'sqlite_temp_master'] as $schema) {
                    if (DB::table($schema)->whereRaw('name COLLATE NOCASE = ?', [$name])->exists()) {
                        throw new LogicException('Foreign purchase claim guard refused.');
                    }
                }
            } elseif (DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->exists()) {
                throw new LogicException('Foreign purchase claim guard refused.');
            }
        }
    }

    public function down(): void
    {
        $this->rollbackPreflight();
        $present = array_map(fn ($table) => Schema::hasTable($table), self::TABLES);
        if (count(array_unique($present)) !== 1) {
            throw new LogicException('Partial purchase claim schema prevents rollback.');
        }
        if ($present[0]) {
            $this->ownedSchema();
            $this->refuseExternalReferences();
            foreach (self::TABLES as $table) {
                if (DB::table($table)->exists()) {
                    throw new LogicException('Retained purchase evidence prevents rollback.');
                }
            }
        }
        foreach ($this->guards() as $name => $sql) {
            $table = substr($name, 0, strrpos($name, '_'));
            $operation = strtoupper(substr($name, strrpos($name, '_') + 1));
            $trigger = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->first()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->first();
            $matches = $present[0] && $trigger && (DB::getDriverName() === 'sqlite'
                ? $trigger->type === 'trigger' && $trigger->name === $name && $trigger->tbl_name === $table && $trigger->sql === $sql
                : $trigger->TRIGGER_NAME === $name && $trigger->EVENT_OBJECT_TABLE === $table && $trigger->ACTION_TIMING === 'BEFORE'
                    && $trigger->EVENT_MANIPULATION === $operation && $trigger->ACTION_STATEMENT === explode(' FOR EACH ROW ', $sql, 2)[1]);
            if (($present[0] || $trigger) && ! $matches) {
                throw new LogicException('Unexpected purchase guard ownership prevents rollback.');
            }
        }
        if ($present[0]) {
            foreach (array_keys($this->guards()) as $name) {
                DB::unprepared('DROP TRIGGER '.$name);
            }
            foreach (array_reverse(self::TABLES) as $table) {
                Schema::drop($table);
            }
        }
    }

    private function rollbackPreflight(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Purchase claims require SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (DB::getDriverName() === 'sqlite') {
                if (DB::table('sqlite_temp_master')->whereRaw('tbl_name COLLATE NOCASE = ?', [$table])->orWhereIn(DB::raw('name COLLATE NOCASE'), [$table, ...array_keys($this->guards())])->exists()) {
                    throw new LogicException('Temporary purchase claim objects refused.');
                }
                $object = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->first();
                if ($object && ($object->type !== 'table' || $object->name !== $table)) {
                    throw new LogicException('Unexpected purchase claim object.');
                }
            } else {
                $objects = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
                if ($objects->count() > 1 || ($objects->isNotEmpty() && ($objects[0]->TABLE_NAME !== $table || $objects[0]->TABLE_TYPE !== 'BASE TABLE' || $objects[0]->ENGINE !== 'InnoDB'))) {
                    throw new LogicException('Unexpected purchase claim table identity.');
                }
                try {
                    $object = DB::selectOne('SHOW CREATE TABLE '.$table);
                    if (str_starts_with($object->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                        throw new LogicException('Temporary purchase claim table refused.');
                    }
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
        }
    }

    /** Refuse collateral DDL on a same-named empty foreign table or altered key. */
    private function ownedSchema(): void
    {
        $challengeTypes = ['id' => 'bigint unsigned', 'public_id' => 'char(36)', 'order_id' => 'bigint unsigned',
            'owner_key' => 'char(64)', 'order_hash' => 'char(64)', 'proof_hash' => 'char(64)', 'policy_version' => 'varchar(64)',
            'created_at' => 'datetime', 'expires_at' => 'datetime'];
        $claimTypes = ['id' => 'bigint unsigned', 'public_id' => 'char(36)', 'order_id' => 'bigint unsigned',
            'challenge_id' => 'bigint unsigned', 'account_id' => 'bigint unsigned', 'evidence_ciphertext' => 'text',
            'evidence_hash' => 'char(64)', 'claimed_at' => 'datetime'];
        $nullable = [];
        foreach (['customer_purchase_challenges' => $challengeTypes, 'customer_purchase_claims' => $claimTypes] as $table => $expected) {
            if (DB::getDriverName() === 'sqlite') {
                $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
                if ($sql !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $this->statements($table)[0])) {
                    throw new LogicException('Unexpected purchase claim table definition.');
                }
            }
            $columns = Schema::getColumns($table);
            if (array_column($columns, 'name') !== array_keys($expected)) {
                throw new LogicException('Unexpected purchase claim columns.');
            }
            foreach ($columns as $column) {
                if ($column['nullable'] !== in_array($column['name'], $nullable, true) || $column['default'] !== null
                    || (DB::getDriverName() === 'mysql' && $column['type'] !== $expected[$column['name']])) {
                    throw new LogicException('Unexpected purchase claim column definition.');
                }
            }
            if (DB::getDriverName() === 'mysql') {
                $collation = DB::connection()->getConfig('collation');
                $definition = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->sole();
                if ($definition->TABLE_COLLATION !== $collation || $definition->CREATE_OPTIONS !== '' || $definition->TABLE_COMMENT !== '') {
                    throw new LogicException('Unexpected purchase claim table options.');
                }
                foreach (DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $column) {
                    $text = preg_match('/^(?:char|varchar|text)/', $expected[$column->COLUMN_NAME]);
                    if ($column->COLLATION_NAME !== ($text ? $collation : null) || $column->GENERATION_EXPRESSION !== '' || $column->COLUMN_COMMENT !== ''
                        || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                        throw new LogicException('Unexpected purchase claim column attributes.');
                    }
                }
                if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
                    throw new LogicException('Unexpected purchase claim check constraint.');
                }
            }
            $this->ownedIndexes($table);
            $actualForeign = Schema::getForeignKeys($table);
            $foreign = $table === 'customer_purchase_challenges' ? ['order_id' => ['orders', 'id']]
                : ['order_id' => ['orders', 'id'], 'challenge_id' => ['customer_purchase_challenges', 'id'], 'account_id' => ['customer_accounts', 'id']];
            if (count($actualForeign) !== count($foreign)) {
                throw new LogicException('Unexpected purchase claim foreign keys.');
            }
            foreach ($actualForeign as $key) {
                $column = $key['columns'][0] ?? '';
                if (! isset($foreign[$column]) || $key['columns'] !== [$column] || [$key['foreign_table'], $key['foreign_columns'][0] ?? ''] !== $foreign[$column]
                    || $key['foreign_schema'] !== (DB::getDriverName() === 'sqlite' ? 'main' : DB::getDatabaseName()) || count($key['foreign_columns']) !== 1 || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action') {
                    throw new LogicException('Unexpected purchase claim foreign key definition.');
                }
                unset($foreign[$column]);
            }
            $triggers = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->count()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', $table)->count();
            if ($triggers !== 3) {
                throw new LogicException('Unexpected purchase claim table guards.');
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
                throw new LogicException('Foreign purchase claim references prevent rollback.');
            }

            return;
        }
        foreach (DB::table('sqlite_master')->where('type', 'table')->whereNotIn('name', self::TABLES)->pluck('name') as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array(strtolower($key['foreign_table']), self::TABLES, true)) {
                    throw new LogicException('Foreign purchase claim references prevent rollback.');
                }
            }
        }
    }

    private function ownedIndexes(string $table): void
    {
        $expected = ['primary' => [['id'], true], $table.'_public_id_unique' => [['public_id'], true]];
        $expected += $table === 'customer_purchase_challenges' ? [
            $table.'_proof_hash_unique' => [['proof_hash'], true],
            $table.'_order_id_created_at_index' => [['order_id', 'created_at'], false],
        ] : [
            $table.'_order_id_unique' => [['order_id'], true],
            $table.'_challenge_id_unique' => [['challenge_id'], true],
            $table.'_account_id_order_id_index' => [['account_id', 'order_id'], false],
        ];
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['primary'] ? 'primary' : $index['name'];
            if (! isset($expected[$name]) || [$index['columns'], $index['unique']] !== $expected[$name]
                || $index['type'] !== (DB::getDriverName() === 'mysql' ? 'btree' : null)) {
                throw new LogicException('Unexpected purchase claim index definition.');
            }
            unset($expected[$name]);
        }
        if ($expected !== []) {
            throw new LogicException('Missing purchase claim index.');
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $part) {
                if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                    throw new LogicException('Unexpected purchase claim index attributes.');
                }
            }
        } else {
            foreach (DB::select('PRAGMA index_list('.$table.')') as $index) {
                if ($index->partial !== 0) {
                    throw new LogicException('Unexpected partial purchase claim index.');
                }
                foreach (DB::select('PRAGMA index_xinfo('.DB::connection()->getPdo()->quote($index->name).')') as $part) {
                    if ($part->key === 1 && ($part->cid < 0 || $part->coll !== 'BINARY' || $part->desc !== 0)) {
                        throw new LogicException('Unexpected purchase claim index expression.');
                    }
                }
            }
        }
    }
};
