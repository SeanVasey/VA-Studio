<?php

namespace App\Http\AccountFeatures;

use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use WeakMap;

/** Completes release epochs only after the real root handle and every RequestHandled listener. */
final class AccountFeatureHttpKernel extends Kernel
{
    private static ?WeakMap $epochs = null;

    public function handle($request)
    {
        if (! AccountFeaturePrivacy::matches($request)) {
            return parent::handle($request);
        }
        self::$epochs ??= new WeakMap;
        if (isset(self::$epochs[$request]) && self::$epochs[$request][2] === false) {
            return AccountFeaturePrivacy::error(503);
        }
        $epoch = new \stdClass;
        self::$epochs[$request] = [$epoch, null, false];
        $response = parent::handle($request);
        if ((self::$epochs[$request][0] ?? null) === $epoch && $response instanceof AccountFeatureResponse
            && $response->forRequest($request)) {
            self::$epochs[$request] = [$epoch, $response, true];
        }

        return $response;
    }

    public static function completed(Request $request, AccountFeatureResponse $response): bool
    {
        $epoch = self::$epochs[$request] ?? null;

        return is_array($epoch) && $epoch[1] === $response && $epoch[2] === true;
    }
}
