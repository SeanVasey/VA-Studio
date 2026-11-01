<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Customers\Preferences\ConsentMigrationAdmission;
use Illuminate\Database\Connection;
use LogicException;
use PDO;

/** Additive, same-connection trigger replacement; never touches retained rows or migration ledger. */
final class AccountAdmissionUpgrade
{
    public function up(Connection $connection): void
    {
        $this->change($connection, true);
    }

    public function down(Connection $connection): void
    {
        $this->change($connection, false);
    }

    private function change(Connection $connection, bool $up): void
    {
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver
            || $connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new LogicException('Account guard upgrade requires its own supported connection without an active transaction.');
        }
        if ($driver === 'sqlite') {
            // Deliberate verified V1 no-op in both directions, including repeated down.
            $this->inspect($pdo, $driver, $connection->getDatabaseName());

            return;
        }
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (! is_string($database) || $database === '' || $database !== $connection->getDatabaseName() || $connection->getTablePrefix() !== '') {
            throw new LogicException('Exact account database required.');
        }
        $lock = 'account-guard-v2:'.substr(hash('sha256', $database), 0, 40);
        $identity = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $statement = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new LogicException('Account guard migrator lock unavailable.');
        }
        $oldTimeout = null;
        try {
            $oldTimeout = (int) $pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
            // DDL metadata waits are bounded; never kill the transaction holding metadata.
            $pdo->exec('SET SESSION lock_wait_timeout=5');
            $this->lockHeld($connection, $pdo, $database, $lock, $identity);
            $state = $this->inspect($pdo, $driver, $database);
            $target = $up ? AccountAdmissionGuards::V2 : AccountAdmissionGuards::V1;
            if ($state['version'] === $target) {
                return;
            }
            if ($state['version'] !== AccountAdmissionGuards::OVERLAP) {
                $this->lockHeld($connection, $pdo, $database, $lock, $identity);
                $state = $this->inspect($pdo, $driver, $database);
                if ($state['version'] !== ($up ? AccountAdmissionGuards::V1 : AccountAdmissionGuards::V2)) {
                    throw new LogicException('Account guard upgrade source moved before CREATE.');
                }
                $this->creationSession($pdo, $state);
                $name = $up ? AccountAdmissionGuards::NEW_INSERT : AccountAdmissionGuards::OLD_INSERT;
                $body = AccountAdmissionGuards::definitions('mysql', $target)[$name]['body'];
                $order = $up ? 'FOLLOWS '.AccountAdmissionGuards::OLD_INSERT : 'PRECEDES '.AccountAdmissionGuards::NEW_INSERT;
                $pdo->exec('CREATE TRIGGER `'.$name.'` BEFORE INSERT ON `customer_accounts` FOR EACH ROW '.$order.' '.$body);
            }
            $this->lockHeld($connection, $pdo, $database, $lock, $identity);
            $state = $this->inspect($pdo, $driver, $database);
            if ($state['version'] !== AccountAdmissionGuards::OVERLAP) {
                throw new LogicException('Exact account guard overlap required before DROP.');
            }
            // The verified complete other trigger already protects inserts before the old one is removed.
            $pdo->exec('DROP TRIGGER `'.($up ? AccountAdmissionGuards::OLD_INSERT : AccountAdmissionGuards::NEW_INSERT).'`');
            $this->lockHeld($connection, $pdo, $database, $lock, $identity);
            if ($this->inspect($pdo, $driver, $database)['version'] !== $target) {
                throw new LogicException('Account guard target was not verified.');
            }
        } finally {
            try {
                if ($oldTimeout !== null) {
                    $pdo->exec('SET SESSION lock_wait_timeout='.$oldTimeout);
                }
            } finally {
                $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$lock]);
                if ((int) $statement->fetchColumn() !== 1) {
                    throw new LogicException('Account guard migration lost its advisory lock.');
                }
            }
        }
    }

    private function lockHeld(Connection $connection, PDO $pdo, string $database, string $lock, int $identity): void
    {
        $statement = $pdo->prepare('SELECT CONNECTION_ID(),DATABASE(),IS_USED_LOCK(?)');
        $statement->execute([$lock]);
        $state = $statement->fetch(PDO::FETCH_NUM);
        if ($connection->getRawPdo() !== $pdo || $connection->getDatabaseName() !== $database || $connection->transactionLevel() !== 0 || $pdo->inTransaction()
            || $state === false || (int) $state[0] !== $identity || $state[1] !== $database || (int) $state[2] !== $identity) {
            throw new LogicException('Account migration connection or advisory ownership moved.');
        }
    }

    /** Both existing independent foundation inspectors retain their complete metadata/dependent proofs. */
    private function inspect(PDO $pdo, string $driver, string $database): array
    {
        $before = AccountAdmissionGuards::migrationState($pdo, $driver);
        $identity = new IdentityMigrationOwnership;
        $identity->inspectForAccountUpgrade($pdo, $driver);
        $identity->assertAccountUpgradeDependents($pdo, $driver);
        (new ConsentMigrationAdmission($pdo, $driver, $database))->dependenciesForAccountUpgrade();
        $after = AccountAdmissionGuards::migrationState($pdo, $driver);
        if ($before['fingerprint'] !== $after['fingerprint']) {
            throw new LogicException('Account admission metadata changed while inspecting.');
        }

        return $after;
    }

    private function creationSession(PDO $pdo, array $state): void
    {
        $actual = $pdo->query('SELECT @@SESSION.sql_mode,@@SESSION.character_set_client,@@SESSION.collation_connection,CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        if ($actual !== $state['creation']) {
            throw new LogicException('Account guard creation session differs from retained guard environment.');
        }
    }
}
