<?php

namespace App\Domain\SupportAttachments;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/** Read-only raw closure on the original idle primary. Never begins a transaction or exposes write authority. */
final class AttachmentCommittedRows
{
    private readonly Connection $connection;

    private readonly string $prefix;

    private readonly string $database;

    public function __construct(private readonly AttachmentRows $captured)
    {
        $captured->assertCurrent();
        AttachmentException::require(DB::transactionLevel() === 1);
        $this->connection = DB::connection();
        $this->prefix = $this->connection->getTablePrefix();
        $this->database = $captured->driver() === 'mysql' ? (string) $captured->identity()->query('SELECT DATABASE()')->fetchColumn() : 'main';
    }

    public function assertIdle(): void
    {
        AttachmentException::require(DB::connection() === $this->connection && $this->connection->getPdo() === $this->captured->identity()
            && $this->connection->getTablePrefix() === $this->prefix && $this->connection->transactionLevel() === 0 && ! $this->captured->identity()->inTransaction());
        if ($this->captured->driver() === 'mysql') {
            AttachmentException::require($this->captured->identity()->query('SELECT DATABASE()')->fetchColumn() === $this->database);
        }
    }

    public function rows(string $table, string $where, array $bindings, int $limit): array
    {
        $this->assertIdle();
        AttachmentException::require($limit >= 1 && $limit <= 101);
        // table() uses only the captured PDO and permanent-schema shadow checks, with no provider callbacks.
        $sql = 'SELECT * FROM '.$this->captured->table($table).' WHERE '.$where.' ORDER BY id LIMIT '.$limit;
        $statement = $this->captured->identity()->prepare($sql);
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->assertIdle();

        return $rows;
    }

    public function one(string $table, string $where, array $bindings): array
    {
        $rows = $this->rows($table, $where, $bindings, 2);
        AttachmentException::require(count($rows) <= 1);

        return $rows[0] ?? [];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Committed read context cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'closed_attachment_read'];
    }
}
