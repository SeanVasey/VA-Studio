<?php

namespace App\Domain\Grants\PaidTax;

use Closure;

/** One ordinary begin/commit; the caller retains exact owned cleanup instead of a generic rollback/retry. */
final class PaidTaxTransaction
{
    public static function run(PaidTaxRuntime $runtime, Closure $operation, ?PaidTaxOwnedFrame &$original): array
    {
        $runtime->outsideTransactions();
        PaidTaxException::require($original === null);
        $birth = PaidTaxBeginningDispatcher::install($runtime, static function (PaidTaxOwnedFrame $frame) use (&$original): void {
            $original = $frame;
        });
        try {
            $runtime->connection->beginTransaction();
        } finally {
            // A delegate's replacement remains its property; never restore over it on success or failure.
            $birth->releaseOwned();
        }
        PaidTaxException::require($original instanceof PaidTaxOwnedFrame);
        $runtime->prove(1);
        $original->proveHeld();
        $result = $operation($original);
        PaidTaxException::require(is_array($result));
        $runtime->prove(1);
        $runtime->connection->commit();

        return $result;
    }
}
