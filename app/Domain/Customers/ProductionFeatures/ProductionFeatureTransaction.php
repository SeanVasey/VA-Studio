<?php

namespace App\Domain\Customers\ProductionFeatures;

use App\Domain\Customers\ProductionIdentity\IdentityMetadataAdmission;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** Original outer transaction ownership before identity/module hooks; never adopts a replacement. */
final class ProductionFeatureTransaction
{
    private readonly PDO $primary;

    private readonly string $database;

    private readonly string $driver;

    private readonly string $name;

    private readonly string $anchor;

    private readonly IdentityMetadataAdmission $metadata;

    public function __construct(private readonly Connection $connection)
    {
        $raw = (new \ReflectionProperty(Connection::class, 'pdo'))->getRawValue($connection);
        $this->metadata = IdentityMetadataAdmission::capture($raw instanceof PDO ? $raw : null);
        $primary = $connection->getRawPdo();
        if (! $primary instanceof PDO || $connection->transactionLevel() !== 1 || ! $primary->inTransaction()) {
            throw new ProductionFeatureException;
        }
        ProductionFeatureConfiguration::plainPrimary($primary);
        $this->primary = $primary;
        $this->database = $connection->getDatabaseName();
        $this->driver = (string) $primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->name = $connection->getName();
        $this->source();
        $this->anchor = 'va_feature_outer_'.bin2hex(random_bytes(16));
        $primary->exec('SAVEPOINT '.$this->anchor);
    }

    public function admit(): void
    {
        $this->source();
        try {
            $this->primary->exec('RELEASE SAVEPOINT '.$this->anchor);
            $this->primary->exec('SAVEPOINT '.$this->anchor);
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }

    public function protectFailure(): bool
    {
        $owned = false;
        if ($this->primary->inTransaction()) {
            try {
                $this->primary->exec('RELEASE SAVEPOINT '.$this->anchor);
                $owned = true;
            } catch (Throwable) {
                // A commit/reopen has lost the original marker; its transaction is foreign.
            }
        }
        $cached = (new \ReflectionProperty(Connection::class, 'pdo'))->getRawValue($this->connection);
        $depth = (new \ReflectionProperty(Connection::class, 'transactions'))->getRawValue($this->connection);
        if ($owned && $cached === $this->primary && $depth === 1) {
            return true;
        }
        if ($owned) {
            $this->primary->rollBack();
        }

        // Operation owns its catch: a lost marker gives no authority to reset a foreign
        // source's framework depth. Its owner must reconcile/close that physical frame.
        return false;
    }

    private function source(): void
    {
        $this->metadata->requireCurrent($this->primary);
        ProductionFeatureConfiguration::plainPrimary($this->primary);
        if (! in_array($this->driver, ['sqlite', 'mysql'], true) || ! $this->primary->inTransaction()
            || DB::getDefaultConnection() !== $this->name || (DB::getConnections()[$this->name] ?? null) !== $this->connection
            || $this->connection->getRawPdo() !== $this->primary || $this->connection->transactionLevel() !== 1
            || $this->connection->getDatabaseName() !== $this->database || $this->connection->getTablePrefix() !== ''
            || ($this->driver === 'mysql' && $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->database)) {
            throw new ProductionFeatureException;
        }
    }
}
