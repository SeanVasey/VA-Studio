<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Collection;
use LogicException;
use PDO;
use ReflectionProperty;
use Throwable;

/** Original framework ownership and two private markers, established before the younger held source. */
final class TaxPaidReadFrame implements \JsonSerializable
{
    private bool $terminal = false;

    private bool $aborted = false;

    private readonly DatabaseTransactionsManager $manager;

    private readonly DatabaseTransactionRecord $record;

    private readonly string $name;

    private readonly string $abortAnchor;

    private readonly string $terminalAnchor;

    private function __construct(private readonly Connection $connection, private readonly PDO $primary)
    {
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        CheckoutException::require($manager instanceof DatabaseTransactionsManager && $manager::class === DatabaseTransactionsManager::class, 'committed_read_frame');
        $this->manager = $manager;
        $this->name = $connection->getName();
        $current = (new ReflectionProperty(DatabaseTransactionsManager::class, 'currentTransaction'))->getValue($manager);
        $record = $current[$this->name] ?? null;
        CheckoutException::require($record instanceof DatabaseTransactionRecord && $record::class === DatabaseTransactionRecord::class
            && $record->connection === $this->name && $record->level === 1 && $record->parent === null
            && $manager->getPendingTransactions()->containsStrict($record), 'committed_read_frame');
        $this->record = $record;
        $this->abortAnchor = 'tax_abort_'.bin2hex(random_bytes(16));
        $this->terminalAnchor = 'tax_terminal_'.bin2hex(random_bytes(16));
        $this->execute('SAVEPOINT '.$this->abortAnchor);
        $this->execute('SAVEPOINT '.$this->terminalAnchor);
    }

    public static function capture(Connection $connection, PDO $primary): self
    {
        CheckoutException::require($connection->getRawPdo() === $primary && $connection->transactionLevel() === 1 && $primary->inTransaction(), 'committed_read_frame');

        return new self($connection, $primary);
    }

    public function proveHeld(): void
    {
        $current = (new ReflectionProperty(DatabaseTransactionsManager::class, 'currentTransaction'))->getValue($this->manager);
        CheckoutException::require(! $this->terminal && ! $this->aborted && ($current[$this->name] ?? null) === $this->record
            && (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($this->connection) === $this->manager
            && $this->manager->getPendingTransactions()->containsStrict($this->record), 'committed_read_frame');
    }

    /** Irreversible on both drivers. SQLite also removes the younger generic marker. No held work follows. */
    public function sealTerminal(): void
    {
        $this->proveHeld();
        $this->terminal = true;
        $this->execute('RELEASE SAVEPOINT '.$this->terminalAnchor);
    }

    /** Discard only original records. No callback, policy, alias, deadline or replacement PDO is consulted. */
    public function abort(): void
    {
        if ($this->aborted) {
            return;
        }
        $this->aborted = true;
        $rolledBack = false;
        try {
            if ($this->primary->inTransaction()) {
                // Missing original marker means no authority to roll back a reopened physical frame.
                $this->execute('RELEASE SAVEPOINT '.$this->abortAnchor);
                $rolledBack = $this->primary->rollBack();
            }
        } catch (Throwable) {
            // Cleanup never replaces the refusal/managed callback error or infers a positive commit.
        }
        $currentProperty = new ReflectionProperty(DatabaseTransactionsManager::class, 'currentTransaction');
        $current = $currentProperty->getValue($this->manager);
        $ownsCurrent = $this->owns($current[$this->name] ?? null);
        foreach (['pendingTransactions', 'committedTransactions'] as $name) {
            $property = new ReflectionProperty(DatabaseTransactionsManager::class, $name);
            $records = $property->getValue($this->manager);
            if ($records instanceof Collection && $records::class === Collection::class) {
                // Removal suppresses owned deferred commit AND rollback callbacks.
                $property->setValue($this->manager, $records->reject(fn ($record): bool => $this->owns($record))->values());
            }
        }
        if ($ownsCurrent) {
            $current[$this->name] = null;
            $currentProperty->setValue($this->manager, $current);
        }
        if ($rolledBack && $ownsCurrent && $this->connection->getRawPdo() === $this->primary) {
            (new ReflectionProperty(Connection::class, 'transactions'))->setValue($this->connection, 0);
        }
    }

    private function owns(mixed $record): bool
    {
        // Standard bounded original descendant chain; independent root records and peers are preserved.
        for ($depth = 0; $depth < 128 && $record instanceof DatabaseTransactionRecord; $depth++) {
            if ($record === $this->record) {
                return true;
            }
            $record = $record->parent;
        }

        return false;
    }

    private function execute(string $sql): void
    {
        try {
            CheckoutException::require($this->primary->exec($sql) !== false, 'committed_read_frame');
        } catch (Throwable) {
            throw new CheckoutException('committed_read_frame');
        }
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new LogicException('Tax paid read frames cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax paid read frames cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Tax paid read frames are internal server objects.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_tax_paid_read_frame'];
    }
}
