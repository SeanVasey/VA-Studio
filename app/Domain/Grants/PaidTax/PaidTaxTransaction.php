<?php

namespace App\Domain\Grants\PaidTax;

use Closure;

/** One ordinary begin/commit; the caller retains exact owned cleanup instead of a generic rollback/retry. */
final class PaidTaxTransaction
{
    public static function run(PaidTaxRuntime $runtime, Closure $operation, ?PaidTaxOwnedFrame &$original): array
    {
        $runtime->outsideTransactions();
        $runtime->connection->beginTransaction();
        $runtime->prove(1);
        $original = PaidTaxOwnedFrame::capture($runtime->connection, $runtime->primary);
        $result = $operation($original);
        PaidTaxException::require(is_array($result));
        $runtime->prove(1);
        $runtime->connection->commit();

        return $result;
    }
}
