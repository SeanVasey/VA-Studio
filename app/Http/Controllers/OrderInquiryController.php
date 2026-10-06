<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\OrderInquiry;
use App\Http\Requests\InquiryRequest;
use App\Http\Responses\InquiryResponse;
use App\Support\CommerceRequestIdentity;
use App\Support\InquiryOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class OrderInquiryController
{
    public function setup(string $order, Request $request, CommerceRequestIdentity $identity, OrderInquiry $inquiries): Response
    {
        return $this->run(fn (): Response => InquiryResponse::protect(response()->json(['orderInquiry' => $inquiries->setup(
            $order, $identity->forRequest($request), $identity->principal($request), $identity->actor($request),
        )])));
    }

    public function store(string $order, Request $request, CommerceRequestIdentity $identity, InquiryOwner $owner, OrderInquiry $inquiries): Response
    {
        return $this->run(function () use ($order, $request, $identity, $owner, $inquiries): Response {
            $result = $inquiries->submit($order, $identity->forRequest($request), $owner->forRequest($request),
                InquiryRequest::body($request), $identity->principal($request), $identity->actor($request));

            return InquiryResponse::protect(response()->json(['state' => 'saved', 'receipt' => $result['receipt']], $result['replayed'] ? 200 : 201));
        });
    }

    public function context(string $receipt, Request $request, InquiryOwner $owner, OrderInquiry $inquiries): Response
    {
        return $this->run(fn (): Response => InquiryResponse::protect(response()->json(['context' => $inquiries->ownerContext($receipt, $owner->forRequest($request))])));
    }

    private function run(callable $operation): Response
    {
        try {
            return $operation();
        } catch (InquiryException $error) {
            return InquiryResponse::error($error->status, $error->errors);
        } catch (CustomerAccessException) {
            return InquiryResponse::error(404);
        } catch (Throwable $error) {
            try {
                Log::error('Order inquiry request failed.', ['exception_class' => $error::class]);
            } catch (Throwable) { /* Logging cannot expose private order or inquiry values. */
            }

            return InquiryResponse::error(503);
        }
    }
}
