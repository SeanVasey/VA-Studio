<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PricingSnapshot;
use App\Domain\Commerce\PromotionPolicy;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReadQuote;
use App\Domain\Commerce\ReadQuoteDisclosure;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

final class QuoteController
{
    public function store(Request $request, CommerceRequestIdentity $owner, CreateQuote $create): JsonResponse
    {
        $items = $this->items($request);
        $key = $request->header('Idempotency-Key');
        if ($items === null || ! is_string($key)) {
            return $this->response(['code' => 'INVALID_QUOTE_REQUEST', 'message' => 'Choose a valid selection and try again.'], 422);
        }

        try {
            return $this->present($create->handle($owner->forRequest($request), $key, $items, $owner->actor($request), $owner->principal($request)));
        } catch (QuoteException $exception) {
            return $this->failure($exception);
        }
    }

    public function show(string $quote, Request $request, CommerceRequestIdentity $owner, ReadQuote $read): JsonResponse
    {
        try {
            return $this->present($read->handle($quote, $owner->forRequest($request)));
        } catch (QuoteException $exception) {
            return $this->failure($exception);
        }
    }

    public function license(string $quote, string $revision, Request $request, CommerceRequestIdentity $owner, ReadQuoteDisclosure $read): JsonResponse
    {
        try {
            return $this->response($read->handle($quote, $owner->forRequest($request), $revision));
        } catch (QuoteException $exception) {
            return $this->failure($exception);
        }
    }

    public function price(string $quote, Request $request, CommerceRequestIdentity $owner, PriceQuote $pricing): JsonResponse
    {
        // This operation accepts an empty JSON object only. Configuration owns all calculations.
        try {
            $body = $request->isJson() && $request->query->count() === 0 && strlen($request->getContent()) <= 1024
                ? json_decode($request->getContent(), false, 4, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $body = null;
        }
        if (! $body instanceof stdClass || get_object_vars($body) !== []) {
            return $this->failure(new QuoteException('INVALID_QUOTE_REQUEST', 422));
        }
        try {
            return $this->response(['pricing' => app(PricingSnapshot::class)->present($pricing->create($quote, $owner->forRequest($request), $owner->actor($request), $owner->principal($request)))]);
        } catch (QuoteException $exception) {
            return $this->failure($exception);
        }
    }

    public function pricing(string $quote, Request $request, CommerceRequestIdentity $owner, PriceQuote $pricing): JsonResponse
    {
        try {
            return $this->response(['pricing' => app(PricingSnapshot::class)->present($pricing->read($quote, $owner->forRequest($request), $owner->actor($request), $owner->principal($request)))]);
        } catch (QuoteException $exception) {
            return $this->failure($exception);
        }
    }

    public function promotionPrice(string $quote, Request $request, CommerceRequestIdentity $owner, PriceQuote $pricing): JsonResponse
    {
        try {
            $body = $request->isJson() && $request->query->count() === 0 && strlen($request->getContent()) <= 1024
                ? json_decode($request->getContent(), false, 4, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $body = null;
        }
        if (! $body instanceof stdClass || array_keys(get_object_vars($body)) !== ['promotionCode'] || ! PromotionPolicy::validCode($body->promotionCode)) {
            return $this->failure(new QuoteException('INVALID_QUOTE_REQUEST', 422));
        }
        try {
            return $this->response(['pricing' => app(PricingSnapshot::class)->present(
                $pricing->createWithPromotion($quote, $owner->forRequest($request), $body->promotionCode, $owner->actor($request), $owner->principal($request)))]);
        } catch (QuoteException $exception) {
            return $this->failure($exception);
        }
    }

    /** Read the declared JSON contract only; never merge query, form, price or owner fields. */
    private function items(Request $request): ?array
    {
        if (! $request->isJson() || $request->query->count() !== 0 || strlen($request->getContent()) > 32768) {
            return null;
        }
        try {
            $body = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! $body instanceof stdClass || array_keys(get_object_vars($body)) !== ['items'] || ! is_array($body->items)) {
            return null;
        }
        $items = [];
        foreach ($body->items as $line) {
            if (! $line instanceof stdClass) {
                return null;
            }
            $items[] = get_object_vars($line);
        }

        return $items;
    }

    private function present(Quote $quote): JsonResponse
    {
        $items = array_map(static function (array $line) use ($quote): array {
            $snapshot = $line['offer_snapshot'];

            return [
                'trackId' => $line['track_id'],
                'offerId' => $line['offer_id'],
                'offerRevisionId' => $line['offer_revision_id'],
                'licenseVersionId' => $line['license_version_id'],
                'title' => $snapshot['product']['title'],
                'artist' => $snapshot['product']['artist'],
                'licenseName' => $snapshot['license']['name'],
                'priceMinor' => $snapshot['commercial']['price_minor'],
                'currency' => $snapshot['commercial']['currency'],
                'deliverableRoles' => array_values(array_unique(array_column($snapshot['assets'], 'role'))),
                'features' => $snapshot['license']['features'],
                'licenseUrl' => route('quotes.license', ['quote' => $quote->public_id, 'revision' => $line['offer_revision_id']]),
            ];
        }, $quote->snapshot['lines']);

        return $this->response(['quote' => [
            'id' => $quote->public_id,
            'expiresAt' => $quote->expires_at->utc()->toISOString(),
            'currency' => $quote->currency,
            'subtotalMinor' => $quote->subtotal_minor,
            'taxMinor' => null,
            'totalMinor' => null,
            'taxStatus' => 'unresolved',
            'payable' => false,
            'items' => $items,
        ]]);
    }

    private function failure(QuoteException $exception): JsonResponse
    {
        $message = match ($exception->errorCode) {
            'IDEMPOTENCY_CONFLICT' => 'This request key belongs to a different selection. Start a new review.',
            'QUOTE_EXPIRED' => 'This selection review has expired. Review your selection again.',
            'SELECTION_CHANGED' => 'A selected offer has changed or is unavailable. Choose again.',
            'QUOTE_NOT_FOUND' => 'This selection review is unavailable.',
            'PRICING_NOT_FOUND' => 'This selection does not have a pricing review.',
            'PRICING_EXPIRED' => 'This pricing review has expired. Review your selection again.',
            'PRICING_CHANGED' => 'Pricing rules have changed. Start a new selection review.',
            'COMMERCE_UNAVAILABLE', 'PRICING_UNAVAILABLE' => 'Pricing is temporarily unavailable. Try again later.',
            'PROMOTION_UNAVAILABLE' => 'This promotion is unavailable.',
            'PROMOTION_NOT_ELIGIBLE' => 'This selection does not qualify for that promotion.',
            'PROMOTION_LIMIT_REACHED' => 'This promotion has reached its usage limit.',
            'PROMOTION_CHANGED' => 'Promotion rules have changed. Start a new selection review.',
            'INVENTORY_UNAVAILABLE', 'INVENTORY_BLOCKED', 'INVENTORY_SCOPE_UNAVAILABLE' => 'A selected offer is currently unavailable. Choose again.',
            'INVENTORY_EXPIRED', 'INVENTORY_CHANGED', 'INVENTORY_NOT_FOUND' => 'This selection is no longer reserved. Start a new selection review.',
            'INVENTORY_POLICY_UNAVAILABLE', 'EXCLUSIVE_POLICY_UNAVAILABLE' => 'Selection review is temporarily unavailable.',
            default => 'Choose a valid selection and try again.',
        };

        return $this->response(['code' => $exception->errorCode, 'message' => $message], $exception->status);
    }

    private function response(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
