<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\QuoteException;
use App\Support\QuoteOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Throwable;

final class OrderController
{
    public function review(string $quote, Request $request, QuoteOwner $owner, ReviewOrder $review): JsonResponse
    {
        return $this->run(fn () => ['review' => $review->handle($quote, $owner->forRequest($request))]);
    }

    public function store(Request $request, QuoteOwner $owner, PrepareOrder $prepare, ReadOrder $read): JsonResponse
    {
        return $this->run(function () use ($request, $owner, $prepare, $read): array {
            $body = $this->body($request);
            $key = $request->header('Idempotency-Key');
            if ($body === null || ! is_string($key)) {
                throw new QuoteException('INVALID_ORDER_REQUEST', 422);
            }

            return ['order' => $read->present($prepare->handle($owner->forRequest($request), $key, $body))];
        });
    }

    public function status(string $order, Request $request, QuoteOwner $owner, ReadOrder $read): JsonResponse
    {
        return $this->run(fn () => ['order' => $read->handle($order, $owner->forRequest($request))]);
    }

    public function forQuote(string $quote, Request $request, QuoteOwner $owner, ReadOrder $read): JsonResponse
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
        } catch (QuoteException $exception) {
            return $this->response(['code' => $exception->errorCode, 'message' => match ($exception->errorCode) {
                'ORDER_NOT_FOUND', 'QUOTE_NOT_FOUND' => 'This order review is unavailable.',
                'ORDER_ALREADY_PREPARED' => 'This selection already has a prepared order. Reload to recover its status.',
                'IDEMPOTENCY_CONFLICT' => 'This request key belongs to a different order request.',
                'INVALID_ORDER_REQUEST', 'INVALID_QUOTE_REQUEST' => 'Enter your name and email and accept the displayed terms.',
                'ORDER_POLICY_UNAVAILABLE', 'ORDER_PRICING_UNAVAILABLE' => 'Test order preparation is currently unavailable.',
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
