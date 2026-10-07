<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Support\CanonicalJson;
use LogicException;
use PDO;
use Throwable;

/** Fixed internal SQL, bound values, current primary reads and no Laravel query callbacks. */
final class CurrentRows
{
    private bool $committedReadOnly = false;

    public function __construct(private PDO $primary, private string $driver) {}

    /** Internal frozen-receipt reads only; this mode never opens a transaction or acquires row locks. */
    public static function committedReadOnly(PDO $primary, string $driver): self
    {
        if ($primary->inTransaction()) {
            throw new LogicException('committed_read_frame');
        }
        $reader = new self($primary, $driver);
        $reader->committedReadOnly = true;

        return $reader;
    }

    public function identityPrimary(): PDO
    {
        return $this->primary;
    }

    public function identityDriver(): string
    {
        return $this->driver;
    }

    public function rows(string $table, string $where, array $bindings, ?int $limit = null): array
    {
        if ($this->committedReadOnly) {
            return $this->nonlockingRows($table, $where, $bindings, $limit);
        }

        $sql = 'SELECT * FROM '.$table.' WHERE '.$where.' ORDER BY id'.($limit === null ? '' : ' LIMIT '.$limit)
            .($this->driver === 'mysql' ? ' FOR UPDATE' : '');
        $statement = $this->primary->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function nonlockingRows(string $table, string $where, array $bindings, ?int $limit): array
    {
        if ($this->primary->inTransaction()) {
            throw new LogicException('committed_read_frame');
        }
        $sql = 'SELECT * FROM '.$table.' WHERE '.$where.' ORDER BY id'.($limit === null ? '' : ' LIMIT '.$limit);
        $statement = $this->primary->prepare($sql);
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($this->primary->inTransaction()) {
            throw new LogicException('committed_read_frame');
        }

        return $rows;
    }

    public function one(string $table, int $id): array
    {
        return $this->rows($table, 'id = ?', [$id])[0] ?? [];
    }

    public function audits(string $type, int $id, int $limit): array
    {
        return array_map(self::audit(...), $this->rows('audit_events', 'subject_type = ? AND subject_id = ?', [$type, $id], $limit));
    }

    public static function audit(array $row): array
    {
        try {
            MachinePolicyV1::require(is_string($row['context'] ?? null) && strlen($row['context']) <= 8192);
            $row['context'] = json_decode($row['context'], true, 8, JSON_THROW_ON_ERROR);
            MachinePolicyV1::require(is_array($row['context']));
            CanonicalJson::encode($row['context']);

            return $row;
        } catch (Throwable) {
            MachinePolicyV1::require(false);
        }
    }
}
