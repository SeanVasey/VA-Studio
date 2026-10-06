<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\RefundResolution\ReadOwnedTestRefundResolution;
use App\Domain\Customers\CustomerAccessException;
use App\Http\Responses\TestExceptionResolutionResponse as Boundary;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TestExceptionResolutionController
{
    public function __invoke(string $order, Request $request, CommerceRequestIdentity $identity, ReadOwnedTestRefundResolution $read): JsonResponse
    {
        try {
            return Boundary::protect(response()->json(['resolution' => $read->handle(
                $order, $identity->forRequest($request), $identity->principal($request))]));
        } catch (CustomerAccessException) {
            return Boundary::error(403);
        } catch (QuoteException $error) {
            return Boundary::error($error->errorCode === 'ORDER_NOT_FOUND' ? 404 : 503);
        } catch (Throwable $error) {
            try {
                Log::error('Test resolution read failed.', ['exception_class' => $error::class]);
            } catch (Throwable) { /* Keep the private response when logging fails. */
            }

            return Boundary::error(503);
        }
    }
}
