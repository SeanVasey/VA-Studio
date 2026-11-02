<?php

namespace Tests\Unit\ProductionIdentity;

use LogicException;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExplainWindowIds;

final class ExplainWindowIdsTest extends TestCase
{
    public function test_authentic_nested_ordering_windows_resolve_all_seven_existing_expected_ids(): void
    {
        $plan = ['query_block' => ['select_id' => 1, 'nested_loop' => []]];
        foreach ([5, 14, 23, 32, 41, 50, 59] as $position => $id) {
            $block = ['select_id' => $id];
            if ($position < 5) {
                $block['windowing'] = [];
            } else {
                $block['ordering_operation'] = ['windowing' => []];
            }
            $plan['query_block']['nested_loop'][] = ['table' => ['materialized_from_subquery' => ['query_block' => $block]]];
        }
        $old = [];
        $oldWalk = function (array $n) use (&$oldWalk, &$old): void {
            if (isset($n['windowing'],$n['select_id'])) {
                $old[] = $n['select_id'];
            } foreach ($n as $v) {
                if (is_array($v)) {
                    $oldWalk($v);
                }
            }
        };
        $oldWalk($plan);
        self::assertCount(5, array_unique($old), 'Authentic prior co-location defect reproduced.');
        self::assertSame([5, 14, 23, 32, 41, 50, 59], ExplainWindowIds::collect($plan));
        self::assertCount(7, ExplainWindowIds::collect($plan), 'Original seven-family requirement unchanged.');
    }

    public function test_nearest_nested_block_owns_window_and_siblings_do_not_supply_ids(): void
    {
        $plan = ['query_block' => ['select_id' => 7, 'nested_loop' => [
            ['query_block' => ['select_id' => 9, 'ordering_operation' => ['windowing' => []]]],
            ['ordering_operation' => ['windowing' => []]],
            ['sharing_temporary_table_with' => ['select_id' => 44]],
            ['table' => ['select_id' => 55]],
        ]]];
        self::assertSame([9, 7], ExplainWindowIds::collect($plan));
    }

    public function test_orphan_malformed_nested_boundary_shared_reference_and_table_ownership_refuse(): void
    {
        $bad = [['windowing' => []], ['select_id' => 7, 'windowing' => []], ['query_block' => ['select_id' => '7', 'windowing' => []]],
            ['query_block' => ['select_id' => 7, 'nested_loop' => [['query_block' => ['windowing' => []]]]]],
            ['query_block' => ['select_id' => 7, 'query_block' => false]],
            ['query_block' => ['select_id' => 7, 'sharing_temporary_table_with' => ['select_id' => 9, 'windowing' => []]]],
            ['query_block' => ['select_id' => 7, 'table' => ['select_id' => 9, 'windowing' => []]]],
            ['nested_loop' => [['query_block' => ['select_id' => 7]], ['ordering_operation' => ['windowing' => []]]]],
            ['query_block' => ['select_id' => 0, 'windowing' => []]], ['query_block' => ['select_id' => true, 'windowing' => []]],
            ['query_block' => ['select_id' => 7, 'windowing' => false]]];
        foreach ($bad as $plan) {
            try {
                ExplainWindowIds::collect($plan);
                self::fail('Unknown window owner borrowed an unrelated ID.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_upper_id_dedup_caps_and_unchanged_per_id_sharing_requirement(): void
    {
        self::assertSame([1000000], ExplainWindowIds::collect(['query_block' => ['select_id' => 1000000, 'windowing' => [], 'ordering_operation' => ['windowing' => []]]]));
        $deep = [];
        for ($i = 0; $i < 66; $i++) {
            $deep = ['ordering_operation' => $deep];
        }
        foreach ([['query_block' => ['select_id' => 1000001, 'windowing' => []]], $deep, array_fill(0, 8193, [])] as $plan) {
            try {
                ExplainWindowIds::collect($plan);
                self::fail('Ownership ID/traversal bound admitted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        $windows = ExplainWindowIds::collect(['query_block' => ['select_id' => 7, 'windowing' => []]]);
        $shared = [];
        // EXACT existing native oracle expression; absent sharing must still fail that assertion.
        self::assertSame([7], array_values(array_diff($windows, $shared)));
        try {
            self::assertSame([], array_values(array_diff($windows, $shared)));
            self::fail('Missing sharing reference accepted.');
        } catch (ExpectationFailedException) {
            self::assertTrue(true);
        }
    }
}
