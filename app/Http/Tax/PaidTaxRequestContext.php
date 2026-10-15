<?php

namespace App\Http\Tax;

use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxRequestBudget;
use Illuminate\Http\Request;
use WeakMap;

/** One original budget and one claim; tombstones live only as long as the exact request. */
final class PaidTaxRequestContext
{
    private static ?WeakMap $requests = null;

    public static function capture(Request $request): void
    {
        self::$requests ??= new WeakMap;
        PaidTaxException::require(! isset(self::$requests[$request]));
        // Reserve before clock/config callbacks, including failure: this request cannot recapture.
        self::$requests[$request] = ['budget' => null, 'claimed' => false];
        $budget = PaidTaxRequestBudget::capture($request->server->get('REQUEST_TIME_FLOAT'));
        self::$requests[$request] = ['budget' => $budget, 'claimed' => false];
    }

    public static function claim(Request $request): PaidTaxRequestBudget
    {
        $context = self::$requests[$request] ?? null;
        PaidTaxException::require(is_array($context) && ! $context['claimed'] && $context['budget'] instanceof PaidTaxRequestBudget);
        $context['claimed'] = true;
        self::$requests[$request] = $context;

        return $context['budget'];
    }
}
