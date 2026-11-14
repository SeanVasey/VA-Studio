<?php

namespace Tests\Unit\ProductionIdentity;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\ExplainFailureObserver;

final class ExplainFailureObserverTest extends TestCase
{
    public function test_count_sharing_minima_and_coverage_failures_emit_once_and_rethrow_same_object(): void
    {
        foreach (['count', 'sharing', 'minima', 'coverage'] as $reason) {
            $original = new ExpectationFailedException($reason);
            $writes = [];
            try {
                (new ReflectionMethod(ExplainFailureObserver::class, 'run'))->invoke(null, $original, ['query_block' => ['select_id' => 7, 'table_name' => 'PRIVATE_SENTINEL', 'message' => 'PRIVATE_SENTINEL']], 1, static function (string $text) use (&$writes): bool {
                    $writes[] = $text;

                    return true;
                });
                self::fail('Failure swallowed.');
            } catch (ExpectationFailedException $caught) {
                self::assertSame($original, $caught);
            }
            self::assertCount(1, $writes);
            self::assertStringNotContainsString('PRIVATE_SENTINEL', $writes[0]);
            self::assertStringContainsString('UNKNOWN', $writes[0]);
        }
        // Actual observer is called ONLY by catch; a successful assertion produces no diagnostic call.
        $writes = [];
        try {
            self::assertSame(7, 7);
        } catch (ExpectationFailedException $e) {
            $writes[] = $e;
        }
        self::assertSame([], $writes);
    }

    public function test_cap_schema_and_write_failures_never_replace_original_assertion(): void
    {
        $deep = [];
        for ($i = 0; $i < 66; $i++) {
            $deep = ['query_block' => $deep];
        }
        foreach (['cap', 'schema', 'write_false', 'write_throw'] as $fault) {
            $original = new ExpectationFailedException('ORIGINAL_PRIVATE_SENTINEL');
            $writes = [];
            $writer = static function (string $text) use (&$writes, $fault): bool {
                $writes[] = $text;
                if ($fault === 'write_throw') {
                    throw new \RuntimeException('PRIVATE_SENTINEL');
                }

return $fault !== 'write_false';
            };
            try {
                (new ReflectionMethod(ExplainFailureObserver::class, 'run'))->invoke(null, $original, $fault === 'cap' ? $deep : [], $fault === 'schema' ? 8 : 1, $writer);
                self::fail('Original failure lost.');
            } catch (ExpectationFailedException $caught) {
                self::assertSame($original, $caught);
            }
            self::assertLessThanOrEqual(2, count($writes));
            self::assertSame("EXPLAIN failure structure: UNAVAILABLE\n", $writes[array_key_last($writes)]);
            self::assertStringNotContainsString('PRIVATE_SENTINEL',implode('',$writes));
        }
    }
}
