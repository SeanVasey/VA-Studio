<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReadQuote;
use App\Support\QuoteOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

final class QuoteController
{
    public function store(Request $request, QuoteOwner $owner, CreateQuote $create): JsonResponse
    {
        $items = $this->items($request);
        $key = $request->header('Idempotency-Key');
        if ($items === null || ! is_string($key)) {
            return $this->response(['code' => 'INVALID_QUOTE_REQUEST', 'message' => 'Choose a valid selection and try again.'], 422);
        }

        try {
            return $this->present($create->handle($owner->forRequest($request), $key, $items));
        } catch (QuoteException $exception) {
            return $this->failure($exception);
        }
    }

    public function show(string $quote, Request $request, QuoteOwner $owner, ReadQuote $read): JsonResponse
    {
        try {
            return $this->present($read->handle($quote, $owner->forRequest($request)));
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
        $items = array_map(static function (array $line): array {
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
