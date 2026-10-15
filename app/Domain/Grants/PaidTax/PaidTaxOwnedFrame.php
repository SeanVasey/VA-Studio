<?php

namespace App\Domain\Grants\PaidTax;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Collection;
use LogicException;
use PDO;
use ReflectionProperty;
use Throwable;

/** Exact original framework root and cleanup marker; never release it after a younger source is born. */
final class PaidTaxOwnedFrame implements \JsonSerializable
{
    private bool $aborted = false;

    private bool $sourceAdmitted = false;

    private readonly DatabaseTransactionsManager $manager;

    private readonly DatabaseTransactionRecord $record;

    private readonly string $name;

    private readonly string $abortAnchor;

    private function __construct(private readonly Connection $connection, private readonly PDO $primary)
    {
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        PaidTaxException::require($manager instanceof DatabaseTransactionsManager && $manager::class === DatabaseTransactionsManager::class);
        $this->manager = $manager;
        $this->name = $connection->getName();
        $current = (new ReflectionProperty(DatabaseTransactionsManager::class, 'currentTransaction'))->getValue($manager);
        $pending = $manager->getPendingTransactions();
        PaidTaxException::require(is_array($current) && $pending instanceof Collection && $pending::class === Collection::class);
        $record = $current[$this->name] ?? null;
        PaidTaxException::require($record instanceof DatabaseTransactionRecord && $record::class === DatabaseTransactionRecord::class
            && $record->connection === $this->name && $record->level === 1 && $record->parent === null
            && is_array((new ReflectionProperty(Collection::class, 'items'))->getValue($pending))
            && $pending->containsStrict($record));
        $this->record = $record;
        $this->abortAnchor = 'paid_tax_abort_'.bin2hex(random_bytes(16));
        $this->execute('SAVEPOINT '.$this->abortAnchor);
    }

    public static function capture(Connection $connection, PDO $primary): self
    {
        PaidTaxException::require($connection->getRawPdo() === $primary && $connection->transactionLevel() === 1 && $primary->inTransaction());

        return new self($connection, $primary);
    }

    public function proveHeld(): void
    {
        $current = (new ReflectionProperty(DatabaseTransactionsManager::class, 'currentTransaction'))->getValue($this->manager);
        $pending = $this->manager->getPendingTransactions();
        $attributes = get_object_vars($this->record);
        PaidTaxException::require(! $this->aborted && is_array($current)
            && ($current[$this->name] ?? null) === $this->record && $pending instanceof Collection && $pending::class === Collection::class
            && ($attributes['connection'] ?? null) === $this->name && ($attributes['level'] ?? null) === 1
            && array_key_exists('parent', $attributes) && $attributes['parent'] === null
            && (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($this->connection) === $this->manager
            && is_array((new ReflectionProperty(Collection::class, 'items'))->getValue($pending))
            && $pending->containsStrict($this->record));
    }

    /** Once, BEFORE the younger SourceV2: prove the original physical frame without renewing its age. */
    public function admitSource(): void
    {
        $this->proveHeld();
        PaidTaxException::require(! $this->sourceAdmitted);
        $this->sourceAdmitted = true;
        $this->execute('RELEASE SAVEPOINT '.$this->abortAnchor);
        $this->execute('SAVEPOINT '.$this->abortAnchor);
        $this->proveHeld();
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
        $current = is_array($current) ? $current : [];
        $ownsCurrent = $this->owns($current[$this->name] ?? null);
        foreach (['pendingTransactions', 'committedTransactions'] as $name) {
            $property = new ReflectionProperty(DatabaseTransactionsManager::class, $name);
            $records = $property->getValue($this->manager);
            if ($records instanceof Collection && $records::class === Collection::class
                && is_array((new ReflectionProperty(Collection::class, 'items'))->getValue($records))) {
                // Removal suppresses owned deferred commit AND rollback callbacks.
                $property->setValue($this->manager, $records->reject(fn ($record): bool => $this->owns($record))->values());
            }
        }
        if ($ownsCurrent) {
            $current[$this->name] = null;
            $currentProperty->setValue($this->manager, $current);
        } elseif (($current[$this->name] ?? null) instanceof DatabaseTransactionRecord) {
            $reopened = $current[$this->name];
            $attributes = $reopened::class === DatabaseTransactionRecord::class ? get_object_vars($reopened) : [];
            if (($attributes['connection'] ?? null) === $this->name
                && ($attributes['level'] ?? null) === 1 && ($attributes['parent'] ?? null) === $this->record) {
                // A new root after depth0 can inherit the stale old manager parent. Preserve its callbacks
                // while detaching only this exact discarded original parent, so its later commit closes cleanly.
                $reopened->parent = null;
            }
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
            // Reject extension objects BEFORE any metadata dereference, including a magic missing parent.
            if ($record::class !== DatabaseTransactionRecord::class) {
                return false;
            }
            $attributes = get_object_vars($record);
            $parent = $attributes['parent'] ?? null;
            $parentAttributes = $parent instanceof DatabaseTransactionRecord && $parent::class === DatabaseTransactionRecord::class
                ? get_object_vars($parent) : [];
            if (($attributes['connection'] ?? null) !== $this->name
                || ! is_int($attributes['level'] ?? null) || ! is_int($parentAttributes['level'] ?? null)
                || $attributes['level'] <= $parentAttributes['level']) {
                return false;
            }
            $record = $parent;
        }

        return false;
    }

    private function execute(string $sql): void
    {
        try {
            PaidTaxException::require($this->primary->exec($sql) !== false);
        } catch (Throwable) {
            throw new PaidTaxException;
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
