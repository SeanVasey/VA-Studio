<?php

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new LogicException('Unsupported discovery database.');
        }
        $names = [DiscoveryEpoch::TABLE, ...array_keys(DiscoveryEpoch::guards($driver))];
        // Preflight every new identity before creating any object. Existing parents are not adopted.
        if ($driver === 'sqlite') {
            foreach ($pdo->query('SELECT name FROM sqlite_temp_master')->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (in_array(strtolower($name), DiscoveryEpoch::DEPENDENCIES, true)) {
                    throw new LogicException('Discovery dependency is shadowed; installation refused before DDL.');
                }
            }
            foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
                foreach ($pdo->query("SELECT name FROM {$catalog}")->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    if (in_array(strtolower($name), $names, true)) {
                        throw new LogicException('Discovery identity collision; installation refused before DDL.');
                    }
                }
            }
            foreach (DiscoveryEpoch::DEPENDENCIES as $table) {
                $query = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
                $query->execute([$table]);
                if ((int) $query->fetchColumn() !== 1) {
                    throw new LogicException('Discovery dependency unavailable.');
                }
            }
        } else {
            try {
                $pdo->query('SHOW CREATE TABLE '.DiscoveryEpoch::TABLE);
                throw new LogicException('Discovery table collision; installation refused before DDL.');
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1146) {
                    throw $error;
                }
            }
            foreach ($pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() UNION SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (in_array(strtolower($name), $names, true)) {
                    throw new LogicException('Discovery identity collision; installation refused before DDL.');
                }
            }
            foreach (DiscoveryEpoch::DEPENDENCIES as $table) {
                $query = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $query->execute([$table]);
                if ($query->fetchColumn() !== 'InnoDB') {
                    throw new LogicException('Discovery requires transactional dependency tables.');
                }
                $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_ASSOC);
                if (str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE')) {
                    throw new LogicException('Discovery dependency is shadowed; installation refused before DDL.');
                }
            }
        }
        $pdo->exec(DiscoveryEpoch::tableSql($driver));
        $pdo->exec('INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)');
        foreach (DiscoveryEpoch::guards($driver) as $guard) {
            $pdo->exec($guard['sql']);
        }
        (new DiscoveryEpoch)->assertInstalled($pdo, $driver);
    }

    public function down(): void
    {
        throw new LogicException('Retain discovery epoch, guards and migration bookkeeping; operational teardown is unsupported.');
    }
};
