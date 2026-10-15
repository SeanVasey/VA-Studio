<?php

namespace App\Http\AccountFeatures;

use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use WeakMap;
use WeakReference;

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
            && (self::$epochs[$request][1] ?? null)?->get() === $response && $response->forRequest($request)) {
            self::$epochs[$request][2] = true;
        } else {
            unset(self::$epochs[$request]);
        }

        return $response;
    }

    public static function capture(Request $request, AccountFeatureResponse $response): object
    {
        $epoch = self::$epochs[$request] ?? null;
        if (! AccountFeatureResponse::constructing($response) || ! is_array($epoch) || $epoch[1] !== null || $epoch[2] !== false) {
            throw new ProductionFeatureException;
        }
        self::$epochs[$request] = [$epoch[0], WeakReference::create($response), false];

        return $epoch[0];
    }

    public static function completed(Request $request, AccountFeatureResponse $response, object $originalEpoch): bool
    {
        $epoch = self::$epochs[$request] ?? null;

        return is_array($epoch) && $epoch[0] === $originalEpoch && $epoch[1]?->get() === $response && $epoch[2] === true;
    }

    public static function consume(Request $request, AccountFeatureResponse $response, object $originalEpoch): bool
    {
        if (! AccountFeatureResponse::attempting($response) || ! self::completed($request, $response, $originalEpoch)) {
            return false;
        }
        unset(self::$epochs[$request]);

        return true;
    }
}
