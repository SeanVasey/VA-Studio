<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Support\CanonicalJson;
use PDO;
use Throwable;

/** Fixed internal SQL, bound values, current primary reads and no Laravel query callbacks. */
final class CurrentRows
{
    public function __construct(private PDO $primary, private string $driver) {}

    public function rows(string $table, string $where, array $bindings, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM '.$table.' WHERE '.$where.' ORDER BY id'.($limit === null ? '' : ' LIMIT '.$limit)
            .($this->driver === 'mysql' ? ' FOR UPDATE' : '');
        $statement = $this->primary->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
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
