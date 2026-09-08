<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\Quote;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReadQuote
{
    public function handle(string $id, string $ownerKey): Quote
    {
        QuoteRequest::owner($ownerKey);
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id)) {
            throw new QuoteException('QUOTE_NOT_FOUND', 404);
        }

        return DB::transaction(function () use ($id, $ownerKey) {
            $quote = Quote::query()->where('public_id', $id)->where('owner_key', $ownerKey)->lockForUpdate()->first();
            if (! $quote) {
                throw new QuoteException('QUOTE_NOT_FOUND', 404);
            }

            return $this->verified($quote);
        }, 5);
    }

    /** Internal transaction helper shared by GET and idempotent replay; it never mutates the historical quote. */
    public function verified(Quote $quote): Quote
    {
        if ($quote->expires_at->lessThanOrEqualTo(now())) {
            throw new QuoteException('QUOTE_EXPIRED', 410);
        }
        try {
            $items = QuoteRequest::items($quote->request);
            $snapshot = $quote->snapshot;
            $lines = app(QuoteSelection::class)->resolve($items, false);
            $expected = ['schema_version' => 1, 'purpose' => 'selection_review', 'payable' => false, 'public_id' => $quote->public_id, 'issued_at' => $quote->created_at->utc()->toIso8601ZuluString(), 'expires_at' => $quote->expires_at->utc()->toIso8601ZuluString(), 'currency' => 'USD', 'subtotal_minor' => array_sum(array_column(array_column(array_column($lines, 'offer_snapshot'), 'commercial'), 'price_minor')), 'tax_status' => 'unresolved', 'tax_minor' => null, 'total_minor' => null, 'lines' => $lines];
            $references = $quote->lines()->orderBy('position')->get();
            if ($quote->canonicalization_version !== CanonicalJson::VERSION || ! hash_equals($quote->request_hash, CanonicalJson::hash($items)) || ! hash_equals($quote->snapshot_hash, CanonicalJson::hash($snapshot)) || ! hash_equals($quote->snapshot_hash, CanonicalJson::hash($expected)) || $quote->subtotal_minor !== $expected['subtotal_minor'] || $quote->currency !== 'USD' || $references->count() !== count($lines)) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            foreach ($lines as $position => $line) {
                $reference = $references[$position];
                if ($reference->position !== $position || $reference->offer_revision_id !== $line['offer_revision_id'] || ! hash_equals($reference->line_hash, CanonicalJson::hash($line))) {
                    throw new QuoteException('SELECTION_CHANGED', 409);
                }
            }
        } catch (QueryException $exception) {
            // Preserve database deadlocks for the outer transaction's bounded retry.
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof QuoteException && $exception->errorCode === 'SELECTION_CHANGED') {
                throw $exception;
            }
            throw new QuoteException('SELECTION_CHANGED', 409);
        }

        // A lock wait or media verification must not extend the frozen lifetime.
        if ($quote->expires_at->lessThanOrEqualTo(now())) {
            throw new QuoteException('QUOTE_EXPIRED', 410);
        }

        return $quote;
    }
}
