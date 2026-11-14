<?php

namespace Tests\Unit\ProductionIdentity;

use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExplainFamilyProjection;

final class ExplainFamilyProjectionTest extends TestCase
{
    public function test_structural_projection_preserves_ids_sharing_and_explicit_missing_mapping(): void
    {
        $r = ExplainFamilyProjection::project(['query_block' => ['select_id' => 1, 'table' => [
            'table_name' => 'catalog_family_0', 'materialized_from_subquery' => ['query_block' => ['select_id' => 7, 'windowing' => []]],
            'sharing_temporary_table_with' => ['select_id' => 7],
        ]]], 2);
        self::assertSame('EXACT_ALIAS_OBSERVED', $r['mapping'][0]['mapping']);
        self::assertSame('UNKNOWN', $r['mapping'][1]['mapping']);
        self::assertSame([], $r['mapping'][1]['nodes']);
        self::assertContains(7, array_column($r['nodes'], 'select_id'));
        self::assertContains(7, array_column($r['nodes'], 'shared_select_id'));
        self::assertContains(true, array_column($r['nodes'], 'windowing'));
        self::assertContains(true, array_column($r['nodes'], 'materialized'));
        self::assertContains('materialized_from_subquery', array_column($r['nodes'], 'edge'));
    }

    public function test_missing_malformed_unknown_and_empty_categories_never_guess_family_or_expose_payload(): void
    {
        $r = ExplainFamilyProjection::project(['query_block' => ['select_id' => 'PRIVATE_SENTINEL', 'table_name' => 'PRIVATE_SENTINEL',
            'message' => 'PRIVATE_SENTINEL', 'expression' => 'PRIVATE_SENTINEL', 'rows' => 44444,
            'nested_loop' => [['message' => 'Impossible WHERE'], ['message' => 'Select tables optimized away'],
                ['table_name' => 'catalog_family_6'], ['sharing_temporary_table_with' => ['select_id' => false]]],
        ]], 1);
        self::assertSame('UNKNOWN', $r['mapping'][0]['mapping']);
        self::assertContains('UNKNOWN', array_column($r['nodes'], 'select_id'));
        self::assertContains('IMPOSSIBLE_WHERE', array_column($r['nodes'], 'message_category'));
        self::assertContains('TABLES_OPTIMIZED_AWAY', array_column($r['nodes'], 'message_category'));
        self::assertStringNotContainsString('PRIVATE_SENTINEL', serialize($r));
        self::assertStringNotContainsString('44444', serialize($r));
        self::assertStringNotContainsString('expression', serialize($r));
        self::assertSame(['mapping' => [['family' => 0, 'nodes' => [], 'mapping' => 'UNKNOWN']], 'nodes' => []], ExplainFamilyProjection::project([], 1));
    }

    public function test_traversal_record_and_closed_family_bounds_refuse_without_partial_projection(): void
    {
        foreach ([0, 8] as $families) {
            try {
                ExplainFamilyProjection::project([], $families);
                self::fail('Family scope broadened.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        $deep = [];
        for ($i = 0; $i < 66; $i++) {
            $deep = ['query_block' => $deep];
        }
        foreach ([$deep, array_fill(0, 257, ['select_id' => 1])] as $plan) {
            try {
                ExplainFamilyProjection::project($plan, 1);
                self::fail('Unbounded partial projection returned.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }
}
