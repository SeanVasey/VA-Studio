<?php

namespace Tests\Support;

use LogicException;

/** Diagnostic structure ONLY; no expressions, native messages, row facts or unknown identifiers escape. */
final class ExplainFamilyProjection
{
    public static function project(array $plan, int $families): array
    {
        if ($families < 1 || $families > 7) {
            throw new LogicException('Closed observed family count required.');
        }
        $nodes = [];
        $seen = 0;
        $walk = function (array $node, ?int $parent, string $edge, int $depth) use (&$walk, &$nodes, &$seen, $families): void {
            if (++$seen > 8192 || $depth > 64) {
                throw new LogicException('EXPLAIN projection traversal cap.');
            }
            $selected = array_key_exists('select_id', $node) || array_key_exists('table_name', $node)
                || array_key_exists('windowing', $node) || array_key_exists('sharing_temporary_table_with', $node)
                || array_key_exists('materialized_from_subquery', $node) || array_key_exists('message', $node)
                || in_array($edge, ['materialized_from_subquery', 'sharing_temporary_table_with'], true);
            $here = $parent;
            if ($selected) {
                if (count($nodes) >= 256) {
                    throw new LogicException('EXPLAIN projection record cap.');
                }
                $id = $node['select_id'] ?? null;
                $id = is_int($id) && $id > 0 && $id <= 1000000 ? $id : 'UNKNOWN';
                $alias = 'UNKNOWN';
                foreach (range(0, $families - 1) as $family) {
                    if (($node['table_name'] ?? null) === 'catalog_family_'.$family) {
                        $alias = $family;
                    }
                }
                $shared = $node['sharing_temporary_table_with']['select_id'] ?? null;
                $shared = is_int($shared) && $shared > 0 && $shared <= 1000000 ? $shared : 'UNKNOWN';
                $message = match ($node['message'] ?? null) {
                    null => 'NONE',
                    'Impossible WHERE' => 'IMPOSSIBLE_WHERE',
                    'No matching rows after partition pruning' => 'NO_MATCH_AFTER_PARTITION_PRUNING',
                    'Select tables optimized away' => 'TABLES_OPTIMIZED_AWAY',
                    'no matching row in const table' => 'NO_MATCH_CONST_TABLE',
                    default => 'UNKNOWN',
                };
                $here = count($nodes);
                $nodes[] = ['node' => $here, 'parent' => $parent, 'edge' => $edge, 'select_id' => $id,
                    'family' => $alias, 'windowing' => array_key_exists('windowing', $node),
                    'materialized' => array_key_exists('materialized_from_subquery', $node),
                    'shared_select_id' => $shared, 'message_category' => $message];
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $safeEdge = in_array($key, ['query_block', 'windowing', 'materialized_from_subquery', 'sharing_temporary_table_with', 'table', 'nested_loop', 'union_result', 'query_specifications', 'ordering_operation', 'grouping_operation', 'duplicates_removal'], true) ? $key : 'UNKNOWN';
                    $walk($value, $here, $safeEdge, $depth + 1);
                }
            }
        };
        $walk($plan, null, 'ROOT', 0);
        $mapping = [];
        foreach (range(0, $families - 1) as $family) {
            $matching = array_values(array_filter($nodes, static fn (array $n): bool => $n['family'] === $family));
            $mapping[] = ['family' => $family, 'nodes' => array_column($matching, 'node'), 'mapping' => $matching === [] ? 'UNKNOWN' : 'EXACT_ALIAS_OBSERVED'];
        }
        $result = ['mapping' => $mapping, 'nodes' => $nodes];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 65536) {
            throw new LogicException('EXPLAIN projection output cap.');
        }

        return $result;
    }
}
