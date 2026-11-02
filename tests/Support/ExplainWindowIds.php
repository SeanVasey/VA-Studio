<?php

namespace Tests\Support;

use LogicException;

/** Test-only physical window ownership; ONLY actual enclosing query_block supplies an ID. */
final class ExplainWindowIds
{
    public static function collect(array $plan): array
    {
        $ids = [];
        $visited = 0;
        $walk = function (array $node, ?int $owner, int $depth) use (&$walk, &$ids, &$visited): void {
            if (++$visited > 8192 || $depth > 64) {
                throw new LogicException('Window ownership traversal cap.');
            }
            if (array_key_exists('windowing', $node)) {
                if ($owner === null || ! is_array($node['windowing'])) {
                    throw new LogicException('Orphan or malformed window ownership.');
                }
                $ids[] = $owner;
            }
            foreach ($node as $key => $value) {
                if ($key === 'query_block') {
                    // A new block is a HARD ownership boundary; never inherit on missing/malformed ID.
                    if (! is_array($value) || ! isset($value['select_id']) || ! is_int($value['select_id']) || $value['select_id'] < 1 || $value['select_id'] > 1000000) {
                        throw new LogicException('Malformed query block owner.');
                    }
                    $walk($value, $value['select_id'], $depth + 1);
                } elseif (is_array($value)) {
                    // Table aliases/shared references never supply or carry window ownership.
                    $next = in_array($key, ['table', 'sharing_temporary_table_with'], true) ? null : $owner;
                    $walk($value, $next, $depth + 1);
                }
            }
        };
        $walk($plan, null, 0);

        return array_values(array_unique($ids));
    }
}
