<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use LogicException;
use PDO;

/** A process declaration for fixtures, never proof of host isolation or product authority. */
final readonly class DisposableTestDatabase
{
    private function __construct(
        private string $driver,
        private string $database,
        private string|false $localMarker,
        private string|false $ciMarker,
        private string|false $ci,
    ) {}

    public static function capture(): self
    {
        $driver = getenv('DB_CONNECTION');
        $database = getenv('DB_DATABASE');
        if (getenv('APP_ENV') !== 'testing' || ! in_array($driver, ['sqlite', 'mysql'], true)
            || ! is_string($database) || $database === '') {
            self::refuse();
        }
        if ($driver === 'sqlite' && $database !== ':memory:') {
            self::refuse();
        }
        if ($driver === 'mysql' && preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1) {
            self::refuse();
        }

        return new self($driver, $database, getenv('VA_LOCAL_DISPOSABLE_MYSQL'),
            getenv('VA_CI_DISPOSABLE_MYSQL'), getenv('CI'));
    }

    public function admit(Connection $connection): Connection
    {
        if ($connection->getDriverName() !== $this->driver || $connection->getDatabaseName() !== $this->database) {
            self::refuse();
        }
        if ($this->driver === 'mysql') {
            $local = $this->localMarker === $this->database
                && str_starts_with($this->database, 'vaseyaudio_');
            $ci = $this->ciMarker === '1' && $this->ci === 'true'
                && strtolower($this->database) !== 'vaseyaudio' && preg_match('/prod|live/i', $this->database) !== 1;
            if (! $local && ! $ci) {
                self::refuse();
            }
        }
        $pdo = $connection->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->driver) {
            self::refuse();
        }
        if ($this->driver === 'mysql') {
            if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) {
                self::refuse();
            }
        } else {
            $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC),
                static fn (array $row): bool => $row['name'] === 'main'));
            if (count($main) !== 1 || $main[0]['file'] !== '') {
                self::refuse();
            }
        }

        return $connection;
    }

    private static function refuse(): never
    {
        throw new LogicException('Disposable test database admission refused.');
    }
}
