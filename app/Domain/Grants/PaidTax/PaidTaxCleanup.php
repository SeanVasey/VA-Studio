<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use Throwable;

/** Captured originals only. Cleanup errors never replace the original refusal or adopt a reopened frame. */
final class PaidTaxCleanup
{
    public static function abort(?ProductionTaxPaidOrderSourceV2 $source, ?PaidTaxRows $rows, ?PaidTaxOwnedFrame $frame): void
    {
        foreach ([[$source, 'abortOwnedFrame'], [$rows, 'abort'], [$frame, 'abort']] as [$owned, $method]) {
            try {
                $owned?->{$method}();
            } catch (Throwable) {
                // Keep the original error; a different or damaged owner must never grant new cleanup authority.
            }
        }
    }
}
