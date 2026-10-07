<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** Private transaction continuity fence; releasing the anchor never undoes consumer writes. */
final readonly class HeldSourceTransaction
{
    private function __construct(
        private Connection $connection,
        private PDO $primary,
        private string $driver,
        private string $database,
        private string $prefix,
        private string $anchor,
    ) {}

    public static function requireCurrent(CurrentRows $reader): void
    {
        $connection = DB::connection();
        $primary = $reader->identityPrimary();
        CheckoutException::require($connection->getRawPdo() === $primary
            && $connection->getDriverName() === $reader->identityDriver()
            && $primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $reader->identityDriver()
            && in_array($reader->identityDriver(), ['sqlite', 'mysql'], true)
            && $connection->transactionLevel() === 1 && $primary->inTransaction(), 'held_transaction');
    }

    public static function capture(CurrentRows $reader): self
    {
        self::requireCurrent($reader);
        $connection = DB::connection();
        $frame = new self($connection, $reader->identityPrimary(), $reader->identityDriver(),
            $connection->getDatabaseName(), $connection->getTablePrefix(), 'pco_hold_'.bin2hex(random_bytes(16)));
        $frame->requireFrame($reader);
        $frame->execute('SAVEPOINT ');

        return $frame;
    }

    public function prove(CurrentRows $reader): void
    {
        $this->requireFrame($reader);
        // Direct PDO commit/reopen or rollback across the anchor destroys it even when Tx1 looks unchanged.
        $this->execute('RELEASE SAVEPOINT ');
        $this->execute('SAVEPOINT ');
        $this->requireFrame($reader);
    }

    private function requireFrame(CurrentRows $reader): void
    {
        self::requireCurrent($reader);
        CheckoutException::require(DB::connection() === $this->connection && $reader->identityPrimary() === $this->primary
            && $reader->identityDriver() === $this->driver && $this->connection->getDatabaseName() === $this->database
            && $this->connection->getTablePrefix() === $this->prefix
            && $this->primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'held_transaction');
    }

    private function execute(string $operation): void
    {
        try {
            CheckoutException::require($this->primary->exec($operation.$this->anchor) !== false, 'held_transaction');
        } catch (Throwable) {
            // Do not retain a driver exception containing the private anchor or connection diagnostics.
            throw new CheckoutException('held_transaction');
        }
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'held_transaction'];
    }
}
