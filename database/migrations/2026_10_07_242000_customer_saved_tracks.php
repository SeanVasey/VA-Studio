<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'customer_saved_tracks';

    private const MARKER = 'VA private listening library v1';

    public function up(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $connection->getTablePrefix() !== '') {
            throw new LogicException('Saved listening storage requires its supported unprefixed database.');
        }
        $this->refuseShadow($pdo, $driver);
        if ($this->exists($pdo, $driver)) {
            $this->owned($pdo, $driver);

            return; // A completed atomic CREATE can survive interruption before Laravel records it.
        }
        // Exactly one DDL statement contains the table, owner uniqueness and account FK.
        // MySQL cannot leave an unrecorded table/index/foreign-key installation prefix.
        DB::unprepared($this->definition($driver));
        if ($connection->getPdo() !== $pdo) {
            throw new LogicException('Saved listening installation changed its primary connection.');
        }
        $this->refuseShadow($pdo, $driver);
        $this->owned($pdo, $driver);
    }

    public function down(): void
    {
        // Refuse before any schema/data query or mutation, including an empty or drifted
        // table. A no-op success would delete Laravel's repository row while data remains.
        throw new LogicException('Saved listening rollback is refused; retain its schema, history and migration record.');
    }

    private function definition(string $driver): string
    {
        if ($driver === 'sqlite') {
            return 'CREATE TABLE "customer_saved_tracks" ("id" integer primary key autoincrement not null, "customer_account_id" integer not null, "version" integer not null, "payload" text not null, "created_at" datetime null, "updated_at" datetime null, CONSTRAINT "customer_saved_tracks_account_unique" UNIQUE ("customer_account_id"), CONSTRAINT "customer_saved_tracks_account_fk" FOREIGN KEY ("customer_account_id") REFERENCES "customer_accounts" ("id") ON DELETE RESTRICT ON UPDATE RESTRICT)';
        }

        return 'CREATE TABLE `customer_saved_tracks` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `customer_account_id` bigint unsigned NOT NULL, `version` int unsigned NOT NULL, `payload` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `customer_saved_tracks_account_unique` (`customer_account_id`), CONSTRAINT `customer_saved_tracks_account_fk` FOREIGN KEY (`customer_account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\''.self::MARKER.'\'';
    }

    private function exists(PDO $pdo, string $driver): bool
    {
        $sql = $driver === 'sqlite'
            ? 'SELECT COUNT(*) FROM main.sqlite_master WHERE lower(name)=?'
            : 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)=?';
        $statement = $pdo->prepare($sql);
        $statement->execute([self::TABLE]);

        return (int) $statement->fetchColumn() !== 0;
    }

    private function owned(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT type,sql FROM main.sqlite_master WHERE name=?');
            $statement->execute([self::TABLE]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $objects = $pdo->query("SELECT type,name FROM main.sqlite_master WHERE tbl_name='customer_saved_tracks' AND type IN ('index','trigger') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            if ($row !== ['type' => 'table', 'sql' => $this->definition('sqlite')]
                || $objects !== [['type' => 'index', 'name' => 'sqlite_autoindex_customer_saved_tracks_1']]) {
                throw new LogicException('Unowned or drifted saved listening schema requires inspection; nothing was changed.');
            }

            return;
        }
        $table = $pdo->query("SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_saved_tracks'")->fetch(PDO::FETCH_ASSOC);
        $columns = $pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_saved_tracks' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $expected = [];
        foreach ([['id', 'bigint unsigned', 'NO', 'auto_increment'], ['customer_account_id', 'bigint unsigned', 'NO', ''], ['version', 'int unsigned', 'NO', ''], ['payload', 'text', 'NO', ''], ['created_at', 'timestamp', 'YES', ''], ['updated_at', 'timestamp', 'YES', '']] as [$name, $type, $nullable, $extra]) {
            $expected[] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => null, 'EXTRA' => $extra];
        }
        $indexes = $pdo->query("SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_saved_tracks'")->fetchAll(PDO::FETCH_ASSOC);
        $indexShape = [];
        foreach ($indexes as $index) {
            $indexShape[$index['INDEX_NAME']] = [$index['COLUMN_NAME'], (int) $index['NON_UNIQUE'], (int) $index['SEQ_IN_INDEX'], $index['INDEX_TYPE']];
        }
        $foreign = $pdo->query("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='customer_saved_tracks' AND k.REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $constraints = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_saved_tracks'")->fetchColumn();
        $triggers = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='customer_saved_tracks'")->fetchColumn();
        if ($table !== ['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => self::MARKER, 'TABLE_COLLATION' => 'utf8mb4_unicode_ci']
            || $columns !== $expected || count($indexes) !== 2 || count($indexShape) !== 2
            || ($indexShape['PRIMARY'] ?? null) !== ['id', 0, 1, 'BTREE']
            || ($indexShape['customer_saved_tracks_account_unique'] ?? null) !== ['customer_account_id', 0, 1, 'BTREE']
            || $foreign !== [['CONSTRAINT_NAME' => 'customer_saved_tracks_account_fk', 'COLUMN_NAME' => 'customer_account_id', 'REFERENCED_TABLE_SCHEMA' => $database, 'REFERENCED_TABLE_NAME' => 'customer_accounts', 'REFERENCED_COLUMN_NAME' => 'id', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT']]
            || $constraints !== 3 || $triggers !== 0) {
            throw new LogicException('Unowned or drifted saved listening schema requires inspection; nothing was changed.');
        }
    }

    private function refuseShadow(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)='customer_saved_tracks'")->fetchColumn() !== 0) {
                throw new LogicException('Temporary saved listening storage requires inspection.');
            }

            return;
        }
        try {
            $row = (array) $pdo->query('SHOW CREATE TABLE `customer_saved_tracks`')->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($row['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new LogicException('Temporary saved listening storage requires inspection.');
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                throw $error;
            }
        }
    }
};
