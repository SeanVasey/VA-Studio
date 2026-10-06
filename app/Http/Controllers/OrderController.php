<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Orders\ReadOwnedTestOrders;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\QuoteException;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\PurchaseAccess;
use App\Http\Middleware\CustomerPrivacy;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Throwable;

final class OrderController
{
    public function history(Request $request, CommerceRequestIdentity $owner, ReadOwnedTestOrders $read): JsonResponse
    {
        return $this->run(function () use ($request, $owner, $read): array {
            $raw = $request->server->get('QUERY_STRING', '');
            // One canonical optional public locator; duplicates, arrays and additional query fields are rejected.
            if (! is_string($raw) || ($raw !== '' && (strlen($raw) !== 43
                || preg_match('/\Abefore=[0-9a-f-]{36}\z/D', $raw) !== 1))) {
                throw new QuoteException('ORDER_HISTORY_CURSOR_INVALID', 422);
            }

            return ['history' => $read->handle($owner->forRequest($request), $raw === '' ? null : substr($raw, 7), $owner->principal($request))];
        });
    }

    public function review(string $quote, Request $request, CommerceRequestIdentity $owner, ReviewOrder $review): JsonResponse
    {
        return $this->run(fn () => ['review' => $review->handle($quote, $owner->forRequest($request), $owner->actor($request), $owner->principal($request))]);
    }

    public function store(Request $request, CommerceRequestIdentity $owner, PrepareOrder $prepare, ReadOrder $read): JsonResponse
    {
        return $this->run(function () use ($request, $owner, $prepare, $read): array {
            $body = $this->body($request);
            $key = $request->header('Idempotency-Key');
            if ($body === null || ! is_string($key)) {
                throw new QuoteException('INVALID_ORDER_REQUEST', 422);
            }

            return ['order' => $read->present($prepare->handle($owner->forRequest($request), $key, $body, $owner->actor($request), $owner->principal($request)))];
        });
    }

    public function status(string $order, Request $request, CommerceRequestIdentity $owner, ReadOrder $read): JsonResponse
    {
        return $this->run(fn () => ['order' => app(PurchaseAccess::class)->read($order, $owner->forRequest($request), $owner->principal($request), fn ($original) => $read->handle($order, $original))]);
    }

    public function items(string $order, Request $request, CommerceRequestIdentity $owner, ReadOrder $read): JsonResponse
    {
        return $this->run(fn () => ['items' => app(PurchaseAccess::class)->read($order, $owner->forRequest($request), $owner->principal($request), fn ($original) => $read->items($order, $original))]);
    }

    public function forQuote(string $quote, Request $request, CommerceRequestIdentity $owner, ReadOrder $read): JsonResponse
    {
        return $this->run(fn () => ['order' => $read->forQuote($quote, $owner->forRequest($request))]);
    }

    private function body(Request $request): ?array
    {
        if (! $request->isJson() || $request->query->count() !== 0 || strlen($request->getContent()) > 4096) {
            return null;
        }
        try {
            $body = json_decode($request->getContent(), false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! $body instanceof stdClass || ! ($body->buyer ?? null) instanceof stdClass) {
            return null;
        }
        $input = get_object_vars($body);
        $input['buyer'] = get_object_vars($body->buyer);

        return $input;
    }

    private function run(callable $operation): JsonResponse
    {
        try {
            return $this->response($operation());
        } catch (CustomerAccessException) {
            return CustomerPrivacy::error(403);
        } catch (QuoteException $exception) {
            return $this->response(['code' => $exception->errorCode, 'message' => match ($exception->errorCode) {
                'ORDER_NOT_FOUND', 'QUOTE_NOT_FOUND' => 'This order review is unavailable.',
                'ORDER_HISTORY_CURSOR_INVALID' => 'This order history page is unavailable. Refresh the list to start again.',
                'ORDER_ALREADY_PREPARED' => 'This selection already has a prepared order. Reload to recover its status.',
                'IDEMPOTENCY_CONFLICT' => 'This request key belongs to a different order request.',
                'INVALID_ORDER_REQUEST', 'INVALID_QUOTE_REQUEST' => 'Enter your name and email and accept the displayed terms.',
                'COMMERCE_UNAVAILABLE', 'ORDER_POLICY_UNAVAILABLE', 'ORDER_PRICING_UNAVAILABLE' => 'Test order preparation is currently unavailable.',
                default => 'This order review is no longer available. Review your selection again.',
            }], $exception->status);
        } catch (Throwable) {
            // Never reflect or report SQL bindings, request identity or decrypted evidence.
            return $this->response(['code' => 'ORDER_UNAVAILABLE', 'message' => 'Order preparation is temporarily unavailable. Retry the same request.'], 500);
        }
    }

    private function response(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
