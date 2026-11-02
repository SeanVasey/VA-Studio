<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\CatalogGroup;
use App\Domain\Customers\ProductionIdentity\CatalogGroupPlan;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExplainSharedMaterialization;

final class ExplainSharedMaterializationTest extends TestCase
{
    public function test_ordered_consumers_share_unique_families_with_only_the_known_scalar_window_optional(): void
    {
        foreach ([false, true] as $anonymous) {
            foreach ([false, true] as $scalarWindow) {
                foreach ([false, true] as $wireAtGate) {
                    foreach ([false, true] as $fullAtRow) {
                        $offset = $wireAtGate ? 1000 : 0;
                        $proof = self::prove(self::graph($anonymous, $scalarWindow, $wireAtGate, $fullAtRow, $offset));
                        self::assertSame([30 + $offset, 31 + $offset, 32 + $offset], $proof['families']);
                        self::assertSame([[30 + $offset], [31 + $offset], $scalarWindow ? [32 + $offset] : []], $proof['windows']);
                        self::assertSame(6, $proof['branches']);
                    }
                }
            }
        }
    }

    public function test_source_selection_sql_bindings_and_all_family_recipes_are_fixed(): void
    {
        $plan = self::plan();
        $graph = self::graph();
        foreach ([['v1', 0], ['v1-v2-overlap', 0], ['v2', 1]] as [$version, $stream]) {
            self::refuses($graph, 'source selection', $plan, $version, $stream);
        }
        foreach (['sql', 'bindings', 'families'] as $field) {
            $bad = $plan;
            unset($bad[$field]);
            self::refuses($graph, 'source plan', $bad);
        }
        $bad = $plan;
        $bad['sql'] .= ' ';
        self::refuses($graph, 'source plan', $bad);
        $bad = $plan;
        $bad['bindings'][2] = 'another_table';
        self::refuses($graph, 'source plan', $bad);
        $bad = $plan;
        $bad['families'] = array_reverse($bad['families']);
        self::refuses($graph, 'source recipe', $bad);
        foreach ([0, 1, 2] as $family) {
            foreach (['enum', 'order', 'names', 'columns'] as $field) {
                $bad = $plan;
                $bad['families'][$family][$field] = 'changed';
                self::refuses($graph, 'source recipe', $bad);
            }
        }
    }

    public function test_direct_header_helpers_and_ordering_wrappers_preserve_own_execution_roles(): void
    {
        $graph = self::graph();
        $branches = &self::branches($graph);
        foreach ([0, 1, 2] as $family) {
            $helper = &$branches[2 * $family]['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block'];
            $helper['table'] = $helper['grouping_operation']['table'];
            unset($helper['grouping_operation'], $helper);
            $row = &$branches[2 * $family + 1]['query_block'];
            $row['ordering_operation'] = ['using_filesort' => true, 'table' => $row['table']];
            $row['cost_info'] = ['query_cost' => '1.00'];
            unset($row['table'], $row);
        }
        unset($branches);
        $graph['query_block']['ordering_operation'] = ['using_filesort' => true, 'nested_loop' => $graph['query_block']['nested_loop']];
        unset($graph['query_block']['nested_loop']);
        self::assertSame([30, 31, 32], self::prove($graph)['families']);
    }

    public function test_reordered_missing_malformed_and_crosswired_branches_refuse(): void
    {
        $bad = self::graph();
        $branches = &self::branches($bad);
        [$branches[0], $branches[1]] = [$branches[1], $branches[0]];
        self::refuses($bad, 'consumer table count');
        unset($branches);
        $bad = self::graph();
        $branches = &self::branches($bad);
        array_pop($branches);
        self::refuses($bad, 'six ordered branches');
        unset($branches);
        foreach ([null, [], ['query_block' => ['message' => 'no owner']],
            ['query_block' => ['table' => ['table_name' => 'r', 'select_id' => 99]]]] as $replacement) {
            $bad = self::graph();
            $branches = &self::branches($bad);
            $branches[1] = $replacement;
            self::refuses($bad, $replacement === null || $replacement === [] ? 'branch wrapper' : 'branch owner');
            unset($branches);
        }
        $bad = self::graph();
        $branches = &self::branches($bad);
        $branches[1]['query_block']['table'] = self::reference('r', 31);
        self::refuses($bad, 'header row target');
        unset($branches);
        $bad = self::graph();
        $branches = &self::branches($bad);
        $branches[1]['query_block']['table']['table_name'] = 'unrelated';
        self::refuses($bad, 'consumer table alias');
        unset($branches);
    }

    public function test_unrelated_references_and_duplicate_full_definitions_do_not_supply_missing_edges(): void
    {
        $bad = self::graph();
        $branches = &self::branches($bad);
        unset($branches[1]['query_block']['table']['materialized_from_subquery']);
        // A globally visible reference to the correct ID cannot replace the row edge.
        $bad['unrelated'] = ['table' => self::reference('other', 30)];
        self::refuses($bad, 'materialization');
        unset($branches);
        $bad = self::graph();
        $branches = &self::branches($bad);
        $bad['unrelated'] = ['table' => self::full('other', ['select_id' => 30, 'table' => ['table_name' => 'source']])];
        self::refuses($bad, 'unique full definition');
        unset($branches);
        $bad = self::graph();
        $bad['unrelated'] = ['table' => self::reference('catalog_family_0', 30)];
        self::refuses($bad, 'unique family alias');
    }

    public function test_ordinary_families_require_their_own_actual_window_and_foreign_windows_refuse(): void
    {
        foreach ([0, 1] as $family) {
            $bad = self::graph();
            $branches = &self::branches($bad);
            $block = &$branches[2 * $family]['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block']['grouping_operation']['table']['materialized_from_subquery']['query_block'];
            $block['table'] = $block['windowing']['table'];
            unset($block['windowing']);
            self::refuses($bad, 'family own window');
            unset($block, $branches);
        }
        $bad = self::graph();
        $bad['unrelated'] = ['query_block' => ['select_id' => 91, 'windowing' => []]];
        self::refuses($bad, 'only proven family windows');
        $bad = self::graph();
        $branches = &self::branches($bad);
        $block = &$branches[0]['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block']['grouping_operation']['table']['materialized_from_subquery']['query_block'];
        $block['windowing'] = ['table' => ['table_name' => 'source', 'attached_subqueries' => [
            ['query_block' => ['select_id' => 91, 'windowing' => []]],
        ]]];
        self::refuses($bad, 'family own window');
        unset($block, $branches);
    }

    public function test_malformed_ids_aliases_ambiguous_targets_and_role_collisions_refuse(): void
    {
        foreach ([null, '30', true, 0, -1, 1000001] as $id) {
            $bad = self::graph();
            $branches = &self::branches($bad);
            $branches[1]['query_block']['table']['materialized_from_subquery']['sharing_temporary_table_with']['select_id'] = $id;
            self::refuses($bad, 'reference ID');
            unset($branches);
        }
        foreach (['catalog_family_02', 'catalog_family_3', 'catalog_family_', false] as $alias) {
            $bad = self::graph();
            $branches = &self::branches($bad);
            $branches[0]['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block']['grouping_operation']['table']['table_name'] = $alias;
            self::refuses($bad, $alias === false ? 'table metadata' : 'family alias');
            unset($branches);
        }
        $bad = self::graph();
        $branches = &self::branches($bad);
        $branches[1]['query_block']['select_id'] = null;
        self::refuses($bad, 'query block ID');
        unset($branches);
        $bad = self::graph();
        $branches = &self::branches($bad);
        $branches[1]['query_block']['table']['materialized_from_subquery']['query_block'] = ['select_id' => 99];
        self::refuses($bad, 'one materialization target');
        unset($branches);
        $bad = self::graph();
        $branches = &self::branches($bad);
        $branches[0]['query_block']['nested_loop'][1]['table'] = self::reference('actual', 80);
        self::refuses($bad, 'disjoint materialization roles');
        unset($branches);
    }

    public function test_gate_and_anonymous_wire_must_use_the_validated_first_immediate_operand(): void
    {
        $gatePath = ['query_block', 'nested_loop', 1, 'table', 'materialized_from_subquery', 'query_block', 'table'];
        foreach ([11, 30, 80] as $wrongId) {
            $bad = self::graph();
            $gate = &self::at($bad, $gatePath);
            $gate = self::reference('catalog_wire', $wrongId);
            self::refuses($bad, 'wire consumer target');
            unset($gate);
        }
        $bad = self::graph();
        $gate = &self::at($bad, $gatePath);
        $gate['table_name'] = 'unrelated';
        $bad['unrelated'] = ['table' => self::reference('other', 10)];
        self::refuses($bad, 'consumer table alias');
        unset($gate);
        $bad = self::graph();
        unset($bad['query_block']['nested_loop'][0]['table']['materialized_from_subquery']['using_temporary_table']);
        self::refuses($bad, 'anonymous wire shape');
        $bad = self::graph();
        $bad['query_block']['nested_loop'][0]['table']['materialized_from_subquery']['query_block']['windowing'] = [];
        self::refuses($bad, 'anonymous wire shape');
        $bad = self::graph();
        $bad['unrelated'] = ['table' => self::full('other', ['select_id' => 10])];
        self::refuses($bad, 'anonymous wire collision');
        $bad = self::graph();
        $bad['unrelated'] = $bad['query_block']['nested_loop'][0];
        self::refuses($bad, 'unique full definition');
    }

    public function test_own_scope_rejects_non_leaf_hidden_or_malformed_execution_without_borrowing_subqueries(): void
    {
        foreach (['union_result', 'intersect_result', 'except_result', 'unary_result', 'query_specifications', 'unknown_scope'] as $boundary) {
            $bad = self::graph();
            $branches = &self::branches($bad);
            $branches[1]['query_block'][$boundary] = [];
            self::refuses($bad, 'unknown execution metadata');
            unset($branches);
        }
        $bad = self::graph();
        $branches = &self::branches($bad);
        $row = $branches[1]['query_block']['table'];
        unset($branches[1]['query_block']['table']);
        $branches[1]['query_block']['attached_subqueries'] = [['query_block' => ['select_id' => 99, 'table' => $row]]];
        self::refuses($bad, 'consumer table count');
        unset($branches);
        $bad = self::graph();
        $branches = &self::branches($bad);
        $branches[1]['query_block']['nested_loop'] = ['not_a_list' => ['table' => self::reference('r', 30)]];
        self::refuses($bad, 'own execution list');
        unset($branches);
    }

    public function test_global_array_count_and_depth_caps_refuse_before_partial_graph_acceptance(): void
    {
        $bad = self::graph();
        $bad['excess'] = array_fill(0, 8193, []);
        self::refuses($bad, 'graph cap');
        $bad = self::graph();
        $deep = [];
        for ($i = 0; $i < 66; $i++) {
            $deep = ['nested' => $deep];
        }
        $bad['excess'] = $deep;
        self::refuses($bad, 'graph cap');
    }

    public function test_two_internal_anonymous_frames_preserve_the_proven_shared_wire_and_families(): void
    {
        // Synthetic counterpart of the bounded counterexample: one internal frame
        // in each of families 1/2. The original f378 helper refuses this graph.
        $graph = self::graph();
        self::addInternalFrame($graph, 1, 100);
        self::addInternalFrame($graph, 2, 200);
        self::assertSame(self::prove(self::graph()), self::prove($graph));
        foreach ([1, 2] as $family) {
            $single = self::graph();
            self::addInternalFrame($single, $family, 100 * $family);
            self::assertSame([30, 31, 32], self::prove($single)['families']);
        }
    }

    public function test_anonymous_extras_outside_or_under_gate_header_or_family_zero_refuse(): void
    {
        $bad = self::graph();
        $bad['unrelated'] = ['table' => self::anonymousFrame(100)];
        self::refuses($bad, 'internal anonymous confinement');
        $bad = self::graph();
        self::addInternalFrame($bad, 0, 100);
        self::refuses($bad, 'internal anonymous confinement');
        foreach (['gate', 'header'] as $role) {
            $bad = self::graph();
            if ($role === 'gate') {
                $block = &$bad['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block'];
            } else {
                $branches = &self::branches($bad);
                $block = &$branches[2]['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block'];
            }
            $block['attached_subqueries'] = [['query_block' => ['select_id' => 900, 'table' => self::anonymousFrame(100)]]];
            self::refuses($bad, 'internal anonymous confinement');
            unset($block, $branches);
        }
    }

    public function test_nearest_header_or_gate_cannot_borrow_an_outer_allowed_family_path(): void
    {
        foreach (['header', 'gate'] as $role) {
            $bad = self::graph();
            $branches = &self::branches($bad);
            if ($role === 'header') {
                $moved = $branches[4]['query_block']['nested_loop'][1]['table'];
                $branches[4]['query_block']['nested_loop'][1]['table'] = self::reference('actual', 22);
            } else {
                $moved = $bad['query_block']['nested_loop'][1]['table'];
                $bad['query_block']['nested_loop'][1]['table'] = self::reference('g', 80);
            }
            unset($branches);
            $moved['materialized_from_subquery']['query_block']['attached_subqueries'] = [
                ['query_block' => ['select_id' => 900, 'table' => self::anonymousFrame(100)]],
            ];
            $family = &self::familyBlock($bad, 1);
            $family['attached_subqueries'] = [['query_block' => ['select_id' => 901, 'table' => $moved]]];
            self::refuses($bad, 'internal anonymous confinement');
            unset($family);
        }
    }

    public function test_internal_frames_cannot_borrow_any_protected_role_or_immediate_branch_id_or_reference(): void
    {
        // All seven proven role IDs and all six immediate wire operand IDs,
        // including the first operand used only as the anonymous wire reference.
        foreach ([80, 20, 30, 21, 31, 22, 32, 10, 11, 12, 13, 14, 15] as $id) {
            $bad = self::graph();
            self::addInternalFrame($bad, 1, $id);
            self::refuses($bad, 'internal anonymous protected ID');
            $bad = self::graph();
            self::addInternalFrame($bad, 2, 200);
            $extra = &self::internalBlock($bad, 2);
            $extra['union_result']['query_specifications'][0]['query_block']['table'] = self::reference('dictionary', $id);
            self::refuses($bad, 'internal anonymous protected reference');
            unset($extra);
        }
        foreach ([[], false] as $window) {
            $bad = self::graph();
            self::addInternalFrame($bad, 1, 100);
            $extra = &self::internalBlock($bad, 1);
            $extra['union_result']['query_specifications'][0]['query_block']['windowing'] = $window;
            self::refuses($bad, 'internal anonymous window');
            unset($extra);
        }
    }

    public function test_each_allowed_family_has_at_most_one_extra_and_named_wire_policy_is_unchanged(): void
    {
        foreach ([1, 2] as $family) {
            $bad = self::graph();
            self::addInternalFrame($bad, $family, 100);
            $block = &self::familyBlock($bad, $family);
            $block['attached_subqueries'] = [['query_block' => ['select_id' => 900, 'table' => self::anonymousFrame(100)]]];
            self::refuses($bad, 'internal anonymous cardinality');
            unset($block);
        }
        $bad = self::graph();
        self::addInternalFrame($bad, 1, 100);
        $extra = &self::internalBlock($bad, 1);
        $extra['union_result']['query_specifications'][0]['query_block']['table'] = self::anonymousFrame(300);
        self::refuses($bad, 'internal anonymous cardinality');
        unset($extra);
        $bad = self::graph(anonymous: false);
        self::addInternalFrame($bad, 1, 100);
        self::refuses($bad, 'unresolved anonymous definition');
    }

    public function test_allowed_internal_frames_do_not_relax_selected_wire_or_consumer_boundaries(): void
    {
        $graph = self::graph();
        self::addInternalFrame($graph, 1, 100);
        self::addInternalFrame($graph, 2, 200);
        foreach (['missing', 'false', 'window'] as $mutation) {
            $bad = $graph;
            $wire = &$bad['query_block']['nested_loop'][0]['table']['materialized_from_subquery'];
            if ($mutation === 'missing') {
                unset($wire['using_temporary_table']);
            } elseif ($mutation === 'false') {
                $wire['using_temporary_table'] = false;
            } else {
                $wire['query_block']['windowing'] = [];
            }
            self::refuses($bad, 'anonymous wire shape');
            unset($wire);
        }
        $bad = $graph;
        $bad['query_block']['nested_loop'][0]['table'] = self::reference('w', 10);
        self::refuses($bad, 'wire full definition');
        $bad = $graph;
        $branches = &self::branches($bad);
        $branches[1]['query_block']['table'] = self::reference('r', 31);
        self::refuses($bad, 'header row target');
        unset($branches);
        $bad = $graph;
        $bad['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block']['table'] = self::reference('catalog_wire', 11);
        self::refuses($bad, 'wire consumer target');
    }

    private static function anonymousFrame(int $firstId): array
    {
        return self::full('dictionary_frame', ['union_result' => ['query_specifications' => [
            ['query_block' => ['select_id' => $firstId, 'table' => ['table_name' => 'dictionary_a']]],
            ['query_block' => ['select_id' => $firstId + 1, 'table' => ['table_name' => 'dictionary_b']]],
        ]]]);
    }

    private static function addInternalFrame(array &$graph, int $family, int $firstId): void
    {
        $block = &self::familyBlock($graph, $family);
        if (isset($block['windowing'])) {
            $block['windowing']['table'] = self::anonymousFrame($firstId);
        } else {
            $block['table'] = self::anonymousFrame($firstId);
        }
    }

    private static function &familyBlock(array &$graph, int $family): array
    {
        $branches = &self::branches($graph);

        return $branches[2 * $family]['query_block']['nested_loop'][1]['table']['materialized_from_subquery']['query_block']['grouping_operation']['table']['materialized_from_subquery']['query_block'];
    }

    private static function &internalBlock(array &$graph, int $family): array
    {
        $block = &self::familyBlock($graph, $family);
        if (isset($block['windowing'])) {
            return $block['windowing']['table']['materialized_from_subquery']['query_block'];
        }

        return $block['table']['materialized_from_subquery']['query_block'];
    }

    private static function prove(array $graph): array
    {
        return ExplainSharedMaterialization::proveA2V2($graph, self::plan(), AccountAdmissionGuards::V2, 0);
    }

    private static function plan(): array
    {
        // Pure source compiler, with no Laravel bootstrap, database or saved native JSON.
        return CatalogGroupPlan::build(CatalogGroup::A2_ACCOUNT_FLOOR, AccountAdmissionGuards::V2);
    }

    private static function refuses(array $graph, string $reason, ?array $plan = null, string $version = 'v2', int $stream = 0): void
    {
        try {
            ExplainSharedMaterialization::proveA2V2($graph, $plan ?? self::plan(), $version, $stream);
            self::fail('Invalid structural graph or source contract was accepted.');
        } catch (LogicException $refusal) {
            self::assertSame('A2 shared materialization refused: '.$reason.'.', $refusal->getMessage());
        }
    }

    /** Authored structural graph; IDs and metadata are synthetic, never captured native data. */
    private static function graph(bool $anonymous = true, bool $scalarWindow = false, bool $wireAtGate = false, bool $fullAtRow = false, int $offset = 0): array
    {
        $branches = [];
        foreach ([0, 1, 2] as $family) {
            $familyId = 30 + $family + $offset;
            $source = ['table' => ['table_name' => 'source_'.$family]];
            $body = $family < 2 || $scalarWindow ? ['windowing' => [
                'windows' => [['definition_position' => 1, 'functions' => ['row_number']]], ...$source,
            ]] : $source;
            $definition = ['select_id' => $familyId, ...$body];
            $familyTable = $fullAtRow ? self::reference('catalog_family_'.$family, $familyId) : self::full('catalog_family_'.$family, $definition);
            $helper = self::full('actual', ['select_id' => 20 + $family + $offset, 'grouping_operation' => ['using_temporary_table' => true, 'table' => $familyTable]]);
            $branches[] = ['query_block' => ['select_id' => 10 + 2 * $family + $offset, 'nested_loop' => [
                ['table' => ['table_name' => 'expected', 'access_type' => 'system']], ['table' => $helper],
            ]]];
            $branches[] = ['query_block' => ['select_id' => 11 + 2 * $family + $offset,
                'table' => $fullAtRow ? self::full('r', $definition) : self::reference('r', $familyId)]];
        }
        $wireBlock = ['union_result' => ['using_temporary_table' => false, 'query_specifications' => $branches]];
        $wireKey = ($anonymous ? 10 : 70) + $offset;
        if (! $anonymous) {
            $wireBlock = ['select_id' => $wireKey, ...$wireBlock];
        }
        $wire = $wireAtGate ? self::reference('w', $wireKey) : self::full('w', $wireBlock);
        $gateWire = $wireAtGate ? self::full('catalog_wire', $wireBlock) : self::reference('catalog_wire', $wireKey);
        $gate = self::full('g', ['select_id' => 80 + $offset, 'table' => $gateWire]);

        return ['query_block' => ['select_id' => 1 + $offset, 'nested_loop' => [['table' => $wire], ['table' => $gate]]]];
    }

    private static function full(string $alias, array $block): array
    {
        return ['table_name' => $alias, 'materialized_from_subquery' => ['using_temporary_table' => true, 'query_block' => $block]];
    }

    private static function reference(string $alias, int $id): array
    {
        return ['table_name' => $alias, 'materialized_from_subquery' => ['sharing_temporary_table_with' => ['select_id' => $id]]];
    }

    private static function &branches(array &$graph): array
    {
        return $graph['query_block']['nested_loop'][0]['table']['materialized_from_subquery']['query_block']['union_result']['query_specifications'];
    }

    private static function &at(array &$graph, array $path): array
    {
        $value = &$graph;
        foreach ($path as $key) {
            $value = &$value[$key];
        }

        return $value;
    }
}
