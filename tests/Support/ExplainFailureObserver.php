<?php

namespace Tests\Support;

use PHPUnit\Framework\ExpectationFailedException;
use Throwable;

/** TEST-ONLY observability never changes original assertion authority. */
final class ExplainFailureObserver
{
    public static function rethrow(ExpectationFailedException $original, array $plan, int $families): never
    {
        self::run($original, $plan, $families, static fn (string $text): bool => fwrite(STDERR, $text) === strlen($text));
    }

    private static function run(ExpectationFailedException $original, array $plan, int $families, callable $write): never
    {
        try {
            $projection = ExplainFamilyProjection::project($plan, $families);
            $text = 'EXPLAIN failure structure: '.json_encode(['families' => $families, 'projection' => $projection], JSON_THROW_ON_ERROR)."\n";
            if (strlen($text) > 65792 || $write($text) !== true) {
                throw new \LogicException('Diagnostic write refused.');
            }
        } catch (Throwable) {
            try {
                $write("EXPLAIN failure structure: UNAVAILABLE\n");
            } catch (Throwable) { /* Original assertion remains authoritative even if diagnostics cannot be written. */
            }
        }
        throw $original;
    }
}
