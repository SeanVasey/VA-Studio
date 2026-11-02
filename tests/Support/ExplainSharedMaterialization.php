<?php

namespace Tests\Support;

use LogicException;

/** Test-only, source-bound A2 v2 physical oracle. No SQL, I/O or application bootstrap. */
final class ExplainSharedMaterialization
{
    // Public source hashes, never hashes of native/private evidence. A substantive recipe
    // change requires reviewing this contract again; no inferred scalar-family policy.
    private const TEMPLATE = 'a74a45eacb0a5a3b80c17f9176998ea0d0141bd66589ff7848ba35a8f3d7b7c2';

    private const RECIPES = [
        '71c0dfb1456be9cdd04a8c77b04cbda6e5a8afebd0e5fda513c97b75043ed22d',
        'ad67bec381e2e674122da135bf9d09cb154ba7e22b16c16e51c35e31e972a052',
        'e08be045a720127df086bce242cc943385edb07987219b8f7266a56804481282',
    ];

    private const ENUMS = ['QD9E74E9B8F9D', 'Q3A0D56156905', 'QB894B1A4D034'];

    private const EXECUTION = ['ordering_operation', 'grouping_operation', 'duplicates_removal', 'buffer_result', 'windowing'];

    private const SUBQUERIES = ['attached_subqueries', 'select_list_subqueries', 'update_value_subqueries', 'insert_values_subqueries', 'insert_update_subqueries', 'having_subqueries', 'optimized_away_subqueries', 'order_by_subqueries', 'group_by_subqueries'];

    private const STRINGS = ['possible_keys', 'used_key_parts', 'used_columns', 'ref', 'partitions'];

    private const FLAGS = ['using_temporary_table', 'using_filesort', 'index_condition', 'range_checked_for_each_record', 'pushed_condition', 'using_where', 'not_exists', 'using_MRR', 'using_index', 'full_scan_on_NULL_key', 'using_index_for_group_by', 'using_index_for_skip_scan', 'distinct', 'loosescan', 'first_match', 'using_join_buffer', 'const_row_not_found', 'unique_row_not_found', 'impossible_on_condition', 'pushed_join', 'ft_hints', 'backward_index_scan', 'recursive', 'table_function', 'skip_records_in_range_due_to_force', 'using_secondary_engine', 'rematerialize'];

    private array $definitions = [];

    private array $anonymous = [];

    private array $references = [];

    private array $aliases = [];

    private array $roles = [];

    private int $visited = 0;

    public static function proveA2V2(array $graph, array $sourcePlan, string $version, int $stream): array
    {
        self::require($version === 'v2' && $stream === 0, 'source selection');
        self::require(array_keys($sourcePlan) === ['sql', 'bindings', 'families']
            && is_string($sourcePlan['sql'])
            && hash('sha256', 'EXPLAIN FORMAT=JSON '.$sourcePlan['sql']) === self::TEMPLATE
            && $sourcePlan['bindings'] === ['customer_accounts', 'customer_accounts', 'customer_accounts']
            && is_array($sourcePlan['families']) && array_keys($sourcePlan['families']) === [0, 1, 2], 'source plan');
        foreach (self::RECIPES as $family => $hash) {
            $recipe = $sourcePlan['families'][$family];
            $seen = 0;
            self::require(is_array($recipe) && ($recipe['enum'] ?? null) === self::ENUMS[$family]
                && hash('sha256', json_encode(self::canonical($recipe, $seen), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === $hash, 'source recipe');
        }
        $oracle = new self;
        $oracle->index($graph, []);

        return $oracle->prove($graph);
    }

    private static function canonical(mixed $value, int &$seen, int $depth = 0): mixed
    {
        self::require(++$seen <= 512 && $depth <= 16, 'recipe cap');
        if (! is_array($value)) {
            self::require(is_scalar($value) || $value === null, 'recipe value');

            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$child) {
            $child = self::canonical($child, $seen, $depth + 1);
        }
        unset($child);

        return $value;
    }

    private function index(array $node, array $path, int $depth = 0): void
    {
        self::require(++$this->visited <= 8192 && $depth <= 64, 'graph cap');
        if (array_key_exists('select_id', $node)) {
            self::require(self::id($node['select_id']), 'query block ID');
        }
        if (array_key_exists('materialized_from_subquery', $node)) {
            $target = self::target(['raw' => $node, 'path' => $path], true);
            if ($target['kind'] === 'full') {
                $this->definitions[$target['key']][] = $target;
            } elseif ($target['kind'] === 'anonymous') {
                $this->anonymous[] = $target;
            }
        }
        if (array_key_exists('sharing_temporary_table_with', $node)) {
            $ref = $node['sharing_temporary_table_with'];
            self::require(is_array($ref) && array_keys($ref) === ['select_id'] && self::id($ref['select_id']), 'reference ID');
            $this->references[$ref['select_id']] = ($this->references[$ref['select_id']] ?? 0) + 1;
        }
        $alias = $node['table_name'] ?? null;
        if (is_string($alias) && str_starts_with($alias, 'catalog_family_')) {
            self::require(in_array($alias, ['catalog_family_0', 'catalog_family_1', 'catalog_family_2'], true), 'family alias');
            $this->aliases[$alias][] = ['raw' => $node, 'path' => $path];
        }
        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $this->index($child, [...$path, $key], $depth + 1);
            }
        }
    }

    private function prove(array $graph): array
    {
        self::require(isset($graph['query_block']) && is_array($graph['query_block']), 'root block');
        $root = self::namedTables(self::ownTables($graph['query_block'], ['query_block']), ['w', 'g']);
        $wireConsumer = self::target($root['w'], true);
        $gate = $this->definition(self::target($root['g'])['key']);
        $this->role($gate);
        $gateTables = self::namedTables(self::ownTables($gate['block'], $gate['path']), ['catalog_wire']);
        $gateWire = self::target($gateTables['catalog_wire'], true);
        $full = array_values(array_filter([$wireConsumer, $gateWire], fn (array $target): bool => $target['kind'] !== 'ref'));
        self::require(count($full) === 1, 'wire full definition');
        $wire = $full[0];
        $specs = $wire['block']['union_result']['query_specifications'] ?? null;
        self::require(is_array($specs) && array_is_list($specs) && count($specs) === 6, 'six ordered branches');
        $operands = [];
        $ids = [];
        foreach ($specs as $index => $spec) {
            self::object($spec, 'branch wrapper');
            foreach ($spec as $key => $value) {
                self::require($key === 'query_block' || (in_array($key, ['dependent', 'cacheable'], true) && is_bool($value)), 'branch wrapper');
            }
            $block = $spec['query_block'] ?? null;
            self::require(is_array($block) && self::id($block['select_id'] ?? null), 'branch owner');
            $ids[] = $block['select_id'];
            $operands[] = self::ownTables($block, [...$wire['path'], 'union_result', 'query_specifications', $index, 'query_block']);
        }
        self::require(count(array_unique($ids)) === 6, 'distinct branch owners');
        $families = [];
        $windows = [];
        foreach ([0, 1, 2] as $family) {
            $header = self::namedTables($operands[2 * $family], ['expected', 'actual']);
            $helper = $this->definition(self::target($header['actual'])['key']);
            $this->role($helper);
            // Validate the entire helper's own scope, then require the source-shaped
            // direct/grouping path. A matching table in a nested subquery is not an edge.
            $alias = 'catalog_family_'.$family;
            $inner = self::namedTables(self::ownTables($helper['block'], $helper['path']), [$alias]);
            $direct = [...$helper['path'], 'table'];
            $grouped = [...$helper['path'], 'grouping_operation', 'table'];
            self::require(in_array($inner[$alias]['path'], [$direct, $grouped], true), 'header family path');
            $headerTarget = self::target($inner[$alias]);
            $row = self::namedTables($operands[2 * $family + 1], ['r']);
            $rowTarget = self::target($row['r']);
            self::require($headerTarget['key'] === $rowTarget['key'], 'header row target');
            $key = $headerTarget['key'];
            $definition = $this->definition($key);
            $this->role($definition);
            self::require(($this->references[$key] ?? 0) >= 1, 'family reuse');
            self::require(count($this->aliases[$alias] ?? []) === 1
                && self::target($this->aliases[$alias][0])['key'] === $key, 'unique family alias');
            $owners = ExplainWindowIds::collect(['query_block' => $definition['block']]);
            self::require($owners === [$key] || ($family === 2 && $owners === []), 'family own window');
            $families[$family] = $key;
            $windows[$family] = $owners;
        }
        if ($wire['kind'] === 'anonymous') {
            self::require(($wire['materialized']['using_temporary_table'] ?? null) === true
                && ! array_key_exists('windowing', $wire['block'])
                && count($this->anonymous) === 1, 'anonymous wire shape');
            // MySQL's anonymous UNION is represented by its first IMMEDIATE operand,
            // only after all six source-bound roles pass. Never make it a window owner.
            $wire['key'] = $ids[0];
            self::require(! isset($this->definitions[$wire['key']]), 'anonymous wire collision');
        } else {
            $this->definition($wire['key']);
            self::require($this->anonymous === [], 'unresolved anonymous definition');
        }
        foreach ([$wireConsumer, $gateWire] as $consumer) {
            self::require($consumer['kind'] === 'ref' ? $consumer['key'] === $wire['key'] : $consumer['path'] === $wire['path'], 'wire consumer target');
        }
        self::require(($this->references[$wire['key']] ?? 0) >= 1, 'wire reuse');
        $this->role($wire);
        self::require(count($this->roles) === 8, 'complete distinct roles');
        $allWindows = ExplainWindowIds::collect($graph);
        $familyWindows = array_merge(...array_values($windows));
        sort($allWindows);
        sort($familyWindows);
        self::require($allWindows === $familyWindows, 'only proven family windows');

        return ['families' => $families, 'windows' => $windows, 'branches' => 6];
    }

    private function definition(int $key): array
    {
        self::require(count($this->definitions[$key] ?? []) === 1, 'unique full definition');

        return $this->definitions[$key][0];
    }

    private function role(array $definition): void
    {
        foreach ($this->roles as $role) {
            self::require($role['key'] !== $definition['key'] && $role['path'] !== $definition['path'], 'disjoint materialization roles');
        }
        $this->roles[] = ['key' => $definition['key'], 'path' => $definition['path']];
    }

    private static function target(array $table, bool $allowAnonymous = false): array
    {
        $materialized = $table['raw']['materialized_from_subquery'] ?? null;
        self::object($materialized, 'materialization');
        $full = array_key_exists('query_block', $materialized);
        $ref = array_key_exists('sharing_temporary_table_with', $materialized);
        self::require($full !== $ref, 'one materialization target');
        foreach ($materialized as $key => $value) {
            self::require(in_array($key, ['query_block', 'sharing_temporary_table_with'], true)
                || (in_array($key, ['dependent', 'cacheable', 'using_temporary_table'], true) && is_bool($value)), 'materialization metadata');
        }
        if ($ref) {
            $reference = $materialized['sharing_temporary_table_with'];
            self::require(is_array($reference) && array_keys($reference) === ['select_id'] && self::id($reference['select_id']), 'reference ID');

            return ['kind' => 'ref', 'key' => $reference['select_id']];
        }
        $block = $materialized['query_block'];
        self::object($block, 'full query block');
        $anonymous = ! array_key_exists('select_id', $block);
        self::require($anonymous ? $allowAnonymous && is_array($block['union_result'] ?? null) : self::id($block['select_id']), 'full definition ID');

        return ['kind' => $anonymous ? 'anonymous' : 'full', 'key' => $block['select_id'] ?? null,
            'block' => $block, 'materialized' => $materialized, 'path' => [...$table['path'], 'materialized_from_subquery', 'query_block']];
    }

    private static function namedTables(array $tables, array $names): array
    {
        self::require(count($tables) === count($names), 'consumer table count');
        $byName = [];
        foreach ($tables as $table) {
            $name = $table['raw']['table_name'] ?? null;
            self::require(is_string($name) && in_array($name, $names, true) && ! isset($byName[$name]), 'consumer table alias');
            $byName[$name] = $table;
        }

        return $byName;
    }

    /** Traverse only this query block's execution, never a materialized/subquery payload. */
    private static function ownTables(array $block, array $path): array
    {
        $tables = [];
        $walk = function (array $node, array $at, bool $owner = false, bool $window = false) use (&$walk, &$tables): void {
            self::object($node, 'own execution object');
            foreach ($node as $key => $value) {
                if ($key === 'select_id') {
                    self::require($owner && self::id($value), 'own execution ID');
                } elseif ($key === 'table') {
                    self::object($value, 'own table');
                    foreach ($value as $field => $entry) {
                        if ($field === 'materialized_from_subquery') {
                            self::target(['raw' => $value, 'path' => [...$at, 'table']], true);
                        } elseif (in_array($field, self::SUBQUERIES, true)) {
                            self::subqueries($entry);
                        } elseif (in_array($field, self::STRINGS, true)) {
                            self::strings($entry);
                        } elseif ($field === 'select_id') {
                            self::require(self::id($entry), 'table ID');
                        } elseif (in_array($field, ['table_name', 'access_type', 'key', 'key_length', 'filtered', 'attached_condition', 'message'], true)) {
                            self::require(is_string($entry), 'table metadata');
                        } elseif (in_array($field, ['rows_examined_per_scan', 'rows_produced_per_join'], true)) {
                            self::require(is_int($entry) && $entry >= 0, 'table row estimate');
                        } else {
                            self::metadata($field, $entry);
                        }
                    }
                    $tables[] = ['raw' => $value, 'path' => [...$at, 'table']];
                } elseif ($key === 'nested_loop') {
                    self::list($value, 'own execution list');
                    foreach ($value as $index => $entry) {
                        self::object($entry, 'own execution entry');
                        $walk($entry, [...$at, $key, $index]);
                    }
                } elseif (in_array($key, self::EXECUTION, true)) {
                    self::object($value, 'own execution wrapper');
                    $walk($value, [...$at, $key], false, $key === 'windowing');
                } elseif (in_array($key, self::SUBQUERIES, true)) {
                    self::subqueries($value);
                } elseif ($key === 'windows' && $window) {
                    self::windows($value);
                } else {
                    // Includes rejection of hidden UNION/non-leaf operands and unknown scopes.
                    self::metadata($key, $value);
                }
            }
        };
        // index() has already bounded every array to 8192 visits / depth 64.
        $walk($block, $path, true);

        return $tables;
    }

    private static function metadata(int|string $key, mixed $value): void
    {
        if ($key === 'cost_info') {
            self::object($value, 'cost metadata');
            foreach ($value as $field => $cost) {
                self::require(in_array($field, ['query_cost', 'read_cost', 'eval_cost', 'prefix_cost', 'sort_cost', 'data_read_per_join'], true) && is_string($cost), 'cost metadata');
            }
        } elseif (in_array($key, ['dependent', 'cacheable'], true)) {
            self::require(is_bool($value), 'boolean metadata');
        } elseif ($key === 'message') {
            self::require(is_string($value), 'message metadata');
        } else {
            self::require(in_array($key, self::FLAGS, true) && (is_bool($value) || is_string($value)), 'unknown execution metadata');
        }
    }

    private static function subqueries(mixed $list): void
    {
        self::list($list, 'subqueries');
        foreach ($list as $wrapper) {
            self::object($wrapper, 'subquery wrapper');
            self::object($wrapper['query_block'] ?? null, 'independent subquery boundary');
            foreach ($wrapper as $key => $value) {
                self::require($key === 'query_block' || (in_array($key, ['dependent', 'cacheable', 'using_temporary_table'], true) && is_bool($value)), 'subquery metadata');
            }
        }
    }

    private static function windows(mixed $list): void
    {
        self::list($list, 'window descriptors');
        foreach ($list as $descriptor) {
            self::object($descriptor, 'window descriptor');
            foreach ($descriptor as $key => $value) {
                if ($key === 'name') {
                    self::require(is_string($value), 'window name');
                } elseif ($key === 'definition_position') {
                    self::require($value === 'artificial_window' || self::id($value), 'window position');
                } elseif (in_array($key, ['last_executed_window', 'using_temporary_table', 'using_filesort'], true)) {
                    self::require(is_bool($value), 'window flag');
                } elseif (in_array($key, ['functions', 'filesort_key'], true)) {
                    self::strings($value);
                } elseif ($key === 'frame_buffer') {
                    self::object($value, 'frame buffer');
                    foreach ($value as $flag => $enabled) {
                        self::require(in_array($flag, ['using_temporary_table', 'optimized_frame_evaluation'], true) && is_bool($enabled), 'frame buffer flag');
                    }
                } else {
                    self::require(false, 'unknown window metadata');
                }
            }
        }
    }

    private static function strings(mixed $list): void
    {
        self::list($list, 'string list');
        foreach ($list as $value) {
            self::require(is_string($value), 'string metadata');
        }
    }

    private static function object(mixed $value, string $reason): void
    {
        self::require(is_array($value) && $value !== [] && ! array_is_list($value), $reason);
    }

    private static function list(mixed $value, string $reason): void
    {
        self::require(is_array($value) && $value !== [] && array_is_list($value), $reason);
    }

    private static function id(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 1000000;
    }

    private static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            // Fixed structural reasons only: never interpolate native expressions/aliases.
            throw new LogicException('A2 shared materialization refused: '.$reason.'.');
        }
    }
}
