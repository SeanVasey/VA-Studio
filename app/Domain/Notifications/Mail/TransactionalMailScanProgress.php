<?php

namespace App\Domain\Notifications\Mail;

use App\Domain\Notifications\NotificationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use ReflectionClass;
use Throwable;

/** One committed consideration at a time. Never holds a frame while adapter/job work runs. */
final class TransactionalMailScanProgress
{
    public function start(string $purpose): int
    {
        return $this->owned($purpose, function (PDO $pdo, string $driver) use ($purpose): int {
            $row = $this->row($pdo, $driver, $purpose);
            if ($row['after_id'] === $row['high_water_id']) {
                $table = $purpose === 'new_intents' ? 'transactional_notices' : 'transactional_mail_deliveries';
                $where = $purpose === 'new_intents' ? " WHERE notification_type = 'test_order_ready'" : '';
                $high = $this->number($pdo->query('SELECT COALESCE(MAX(id), 0) FROM '.$table.$where)->fetchColumn());
                $this->advance($pdo, $row, $row['cycle'] + 1, 0, $high);

                return $row['cycle'] + 1;
            }

            return $row['cycle'];
        });
    }

    /** Null closes this invocation, including when another command has moved to a different cycle. */
    public function reserve(string $purpose, int $cycle): ?array
    {
        return $this->owned($purpose, function (PDO $pdo, string $driver) use ($purpose, $cycle): ?array {
            $row = $this->row($pdo, $driver, $purpose);
            if ($row['cycle'] !== $cycle || $row['after_id'] === $row['high_water_id']) {
                return null;
            }
            $sql = $purpose === 'new_intents'
                ? 'SELECT n.id, n.public_id FROM transactional_notices n LEFT JOIN transactional_mail_deliveries d ON d.notice_id = n.id'
                    .' LEFT JOIN customer_accounts a ON a.id = n.account_id WHERE d.id IS NULL'
                    ." AND n.notification_type = 'test_order_ready' AND (a.id IS NULL OR a.access_version <= n.access_version)"
                    .' AND n.id > ? AND n.id <= ? ORDER BY n.id LIMIT 1'
                : "SELECT id, public_id FROM transactional_mail_deliveries WHERE state = 'queued' AND id > ? AND id <= ? ORDER BY id LIMIT 1";
            $statement = $pdo->prepare($sql);
            $statement->execute([$row['after_id'], $row['high_water_id']]);
            $next = $statement->fetch(PDO::FETCH_ASSOC);
            $id = $next === false ? $row['high_water_id'] : $this->number($next['id']);
            $this->advance($pdo, $row, $row['cycle'], $id, $row['high_water_id']);

            return $next === false ? null : ['id' => $id, 'public_id' => $next['public_id'], 'cycle' => $cycle];
        });
    }

    private function row(PDO $pdo, string $driver, string $purpose): array
    {
        $q = $pdo->prepare('SELECT * FROM '.TransactionalMailScanSchema::TABLE.' WHERE scan_key = ?'.($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $q->execute([$purpose]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['scan_key'] !== $purpose) {
            throw new LogicException('Mail progress row is unavailable.');
        }
        foreach (['cycle', 'after_id', 'high_water_id', 'version'] as $key) {
            $row[$key] = $this->number($row[$key]);
        }
        if ($row['after_id'] > $row['high_water_id'] || $row['cycle'] === TransactionalMailScanSchema::MAX || $row['version'] === TransactionalMailScanSchema::MAX) {
            throw new LogicException('Mail progress is invalid or exhausted.');
        }

        return $row;
    }

    private function advance(PDO $pdo, array $old, int $cycle, int $after, int $high): void
    {
        $q = $pdo->prepare('UPDATE '.TransactionalMailScanSchema::TABLE.' SET cycle = ?, after_id = ?, high_water_id = ?, version = version + 1'
            .' WHERE scan_key = ? AND version = ? AND cycle = ? AND after_id = ? AND high_water_id = ?');
        $q->execute([$cycle, $after, $high, $old['scan_key'], $old['version'], $old['cycle'], $old['after_id'], $old['high_water_id']]);
        if ($q->rowCount() !== 1) {
            throw new LogicException('Mail progress reservation conflicted.');
        }
    }

    private function number(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1))
            || (string) (int) $value !== (string) $value || (int) $value < 0 || (int) $value > TransactionalMailScanSchema::MAX) {
            throw new LogicException('Mail progress integer is refused.');
        }

        return (int) $value;
    }

    /** One raw owned attempt, no framework callbacks and no retry of an uncertain physical commit. */
    private function owned(string $purpose, callable $operation): mixed
    {
        if (! in_array($purpose, TransactionalMailScanSchema::PURPOSES, true)) {
            throw new NotificationException('scan_progress_unavailable');
        }
        $manager = DB::getFacadeRoot();
        if (! $manager instanceof DatabaseManager || app('db') !== $manager) {
            throw new NotificationException('scan_progress_unavailable');
        }
        $name = $manager->getDefaultConnection();
        $connection = $manager->connection($name);
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $this->idle($manager, $name, $connection, $pdo);
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $connection->getTablePrefix() !== ''
            || ! (new ReflectionClass($pdo))->isInternal() || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new NotificationException('scan_progress_unavailable');
        }
        $anchor = 'mail_scan_'.bin2hex(random_bytes(16));
        $begun = false;
        try {
            if (! $pdo->beginTransaction()) {
                throw new LogicException('Mail progress frame could not begin.');
            }
            $begun = true;
            $pdo->exec('SAVEPOINT '.$anchor);
            TransactionalMailScanSchema::assertOwned($pdo, $driver);
            $result = $operation($pdo, $driver);
            if (! $pdo->inTransaction() || $connection->getPdo() !== $pdo || $connection->transactionLevel() !== 0) {
                throw new LogicException('Mail progress frame changed.');
            }
            // The original savepoint must still exist; rebuild it for ownership-safe failure cleanup.
            $pdo->exec('RELEASE SAVEPOINT '.$anchor);
            $pdo->exec('SAVEPOINT '.$anchor);
            if (! $pdo->commit()) {
                throw new LogicException('Mail progress commit was not confirmed.');
            }
            $this->idle($manager, $name, $connection, $pdo);

            return $result;
        } catch (Throwable $error) {
            // A missing anchor means the frame changed. Never roll back replacement or peer work.
            if ($begun && $connection->getRawPdo() === $pdo && $pdo->inTransaction()) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT '.$anchor);
                    $pdo->rollBack();
                } catch (Throwable) {
                    // No usable reservation escapes; preserve uncertain/foreign state for the caller.
                }
            }
            throw new NotificationException('scan_progress_unavailable');
        }
    }

    private function idle(DatabaseManager $manager, string $name, Connection $connection, PDO $pdo): void
    {
        if (DB::getFacadeRoot() !== $manager || app('db') !== $manager || $manager->getDefaultConnection() !== $name
            || ($manager->getConnections()[$name] ?? null) !== $connection || $connection->getRawPdo() !== $pdo) {
            throw new NotificationException('scan_progress_unavailable');
        }
        foreach ($manager->getConnections() as $resolved) {
            $raw = $resolved->getRawPdo();
            if ($resolved->transactionLevel() !== 0 || ($raw instanceof PDO && $raw->inTransaction())) {
                throw new NotificationException('scan_progress_unavailable');
            }
        }
    }
}
