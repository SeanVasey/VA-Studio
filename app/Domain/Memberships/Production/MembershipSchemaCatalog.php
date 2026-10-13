<?php

namespace App\Domain\Memberships\Production;

use LogicException;
use PDO;

/** Native dictionary batches belonging to exactly one schema inspection; never a cross-call ownership cache. */
final class MembershipSchemaCatalog
{
    private array $batches = [];

    public function __construct(private readonly PDO $pdo) {}

    public function named(string $sql, array $names, string $name): array
    {
        return $this->rows($sql, array_combine($names, array_map(fn (string $value): array => [$value], $names)), $name);
    }

    /** Fixed server SQL only. Every original equality, LOWER and BINARY comparison runs unchanged in each branch. */
    public function rows(string $sql, array $parameters, string $name): array
    {
        $key = $sql."\0".json_encode($parameters, JSON_THROW_ON_ERROR);
        if (! isset($this->batches[$key])) {
            if (preg_match('/\ASELECT (.+?) FROM (information_schema\..+?) WHERE (.+?)(?: ORDER BY ([a-zA-Z_., ]+))?\z/D', $sql, $parts) !== 1
                || ! isset($parameters[$name])) {
                throw new LogicException('Unsupported membership dictionary lookup.');
            }
            $lookups = [];
            $where = preg_replace_callback('/((?:BINARY )?(?:lower\()?([a-zA-Z_][a-zA-Z0-9_.]*)(?:\))? = (?:BINARY |lower\()?\?(?:\))?)/i', function (array $match) use (&$lookups): string {
                $lookups[] = ['predicate' => $match[1], 'column' => $match[2]];

                return '1 = 1';
            }, $parts[3]);
            if ($lookups === [] || substr_count($sql, '?') !== count($lookups)) {
                throw new LogicException('Unsupported membership dictionary parameters.');
            }
            foreach ($parameters as $values) {
                if (! is_array($values) || count($values) !== count($lookups) || ! array_all($values, fn (mixed $value): bool => is_string($value))) {
                    throw new LogicException('Unsupported membership dictionary values.');
                }
            }
            $order = isset($parts[4]) ? array_map(trim(...), explode(',', $parts[4])) : [];
            $fields = implode('', array_map(fn (int $index, array $lookup): string => ', '.$lookup['column'].' AS membership_key_'.$index, array_keys($lookups), $lookups));
            $fields .= implode('', array_map(fn (int $index, string $column): string => ', '.$column.' AS membership_order_'.$index, array_keys($order), $order));
            $original = implode(' AND ', array_column($lookups, 'predicate'));
            $predicate = implode(' AND ', array_map(fn (int $index, array $lookup): string => str_replace($lookup['column'], 'membership_key_'.$index, $lookup['predicate']), array_keys($lookups), $lookups));
            // Materialize the selected dictionary rows once, preserving native comparison collations.
            $filter = implode(' OR ', array_fill(0, count($parameters), '('.$original.')'));
            $branches = array_map(fn (int $index): string => 'SELECT /*+ NO_MERGE(membership_lookup) */ '.$index.' AS membership_batch, membership_lookup.* FROM membership_lookup WHERE '.$predicate, range(0, count($parameters) - 1));
            $statement = $this->pdo->prepare('WITH membership_lookup AS (SELECT '.$parts[1].$fields.' FROM '.$parts[2].' WHERE '.$where.' AND ('.$filter.')) '.implode(' UNION ALL ', $branches)
                .($order === [] ? '' : ' ORDER BY membership_batch'.implode('', array_map(fn (int $index): string => ', membership_order_'.$index, array_keys($order)))));
            $bindings = array_merge(...array_values($parameters));
            $statement->execute([...$bindings, ...$bindings]);
            $names = array_keys($parameters);
            $batch = array_fill_keys($names, []);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $target = $names[(int) $row['membership_batch']];
                foreach (array_keys($row) as $column) {
                    if (str_starts_with($column, 'membership_')) {
                        unset($row[$column]);
                    }
                }
                $batch[$target][] = $row;
            }
            $this->batches[$key] = $batch;
        }

        return $this->batches[$key][$name];
    }
}
