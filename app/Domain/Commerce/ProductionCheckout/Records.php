<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use PDO;

/** Fixed owned-table inserts on the captured primary PDO; no write-query listeners or model hooks. */
final readonly class Records
{
    public CurrentRows $current;

    public function __construct(private ?PDO $primary, private string $driver, ?CurrentRows $retained = null, private ?CheckoutCommandFrame $frame = null)
    {
        CheckoutException::require($retained !== null || $primary !== null);
        $this->current = $retained ?? new CurrentRows($primary, $driver);
        $this->provePrimary();
    }

    public function commandFrame(): CheckoutCommandFrame
    {
        CheckoutException::require($this->frame !== null, 'write_frame');

        return $this->frame;
    }

    /** Read-only producer adapter using the consumer's already captured primary transaction. */
    public static function retained(CurrentRows $reader): self
    {
        CheckoutException::require(method_exists($reader, 'identityPrimary') && method_exists($reader, 'identityDriver'), 'reader_contract');
        HeldSourceTransaction::requireCurrent($reader);

        return new self(null, $reader->identityDriver(), $reader);
    }

    public function provePrimary(): void
    {
        PrimaryBoundary::prove($this->primary ?? $this->current->identityPrimary(), $this->driver);
    }

    public function insert(string $kind, array $row): array
    {
        $table = CheckoutSchema::TABLES[$kind] ?? null;
        CheckoutException::require($table !== null && $this->primary !== null && $this->primary->inTransaction());
        $columns = CheckoutSchema::definitions()[$table]['columns'];
        unset($columns['id']);
        Evidence::keys($row, array_keys($columns));
        $statement = $this->primary->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
        $id = (int) $this->primary->lastInsertId();
        $actual = $this->current->one($table, $id);
        Evidence::same(['id' => $id, ...$row], $actual);

        return $actual;
    }

    public function one(string $kind, string $publicId): array
    {
        CheckoutException::require(isset(CheckoutSchema::TABLES[$kind]));
        $rows = $this->current->rows(CheckoutSchema::TABLES[$kind], 'public_id = ?', [$publicId], 2);
        CheckoutException::require(count($rows) === 1);

        return $rows[0];
    }

    public function selector(string $kind, string $where, array $bindings, int $limit = 2): array
    {
        CheckoutException::require(isset(CheckoutSchema::TABLES[$kind]));

        return $this->current->rows(CheckoutSchema::TABLES[$kind], $where, $bindings, $limit);
    }
}
