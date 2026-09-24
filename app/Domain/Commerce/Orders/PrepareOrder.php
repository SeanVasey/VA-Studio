<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderAttempt;
use App\Domain\Commerce\Models\OrderLine;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use App\Domain\Commerce\QuoteSelection;
use App\Domain\Commerce\ReadQuote;
use App\Domain\Commerce\ReservePricedQuote;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class PrepareOrder
{
    public function handle(string $ownerKey, string $idempotencyKey, #[SensitiveParameter] array $request): Order
    {
        QuoteRequest::owner($ownerKey); QuoteRequest::key($idempotencyKey);
        $request = OrderRequest::normalize($request); $keyHash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($ownerKey, $keyHash, $request) {
            // Owner mutex also serializes the same key targeting different quotes.
            DB::table('quote_owners')->insertOrIgnore(['owner_key' => $ownerKey]);
            DB::table('quote_owners')->where('owner_key', $ownerKey)->lockForUpdate()->firstOrFail();
            $existing = Order::where('owner_key', $ownerKey)->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                $captured = app(ReadOrder::class)->verify($existing);
                // Compare encrypted retained input after decoding; never store a guessable buyer/request digest.
                if (! hash_equals(CanonicalJson::encode($captured['request']), CanonicalJson::encode($request))) {
                    throw new QuoteException('IDEMPOTENCY_CONFLICT', 409);
                }

                return $existing;
            }
            $quote = Quote::where('public_id', $request['quoteId'])->where('owner_key', $ownerKey)->lockForUpdate()->first();
            if (! $quote) { throw new QuoteException('QUOTE_NOT_FOUND', 404); }
            if (Order::where('quote_id', $quote->id)->lockForUpdate()->exists()) {
                throw new QuoteException('ORDER_ALREADY_PREPARED', 409);
            }
            $policy = app(OrderPolicy::class)->current();
            if ($quote->expires_at->lessThanOrEqualTo(now())) { throw new QuoteException('QUOTE_EXPIRED', 410); }
            // A new commercial promise checks actual frozen bytes again, before pricing/scope locks.
            app(QuoteSelection::class)->resolve(QuoteRequest::items($quote->request), true);
            app(ReadQuote::class)->verified($quote);
            $pricing = app(PriceQuote::class)->read($quote->public_id, $ownerKey);
            $review = app(ReviewOrder::class)->capture($quote, $pricing, $policy);
            if (! hash_equals($review['reviewHash'], $request['reviewHash'])) {
                throw new QuoteException('ORDER_REVIEW_CHANGED', 409);
            }
            $service = app(ReservePricedQuote::class);
            $service->hold($quote->public_id, $ownerKey, $pricing->snapshot['promotion']['code'] ?? null);
            $orderId = (string) Str::uuid(); $attemptId = (string) Str::uuid();
            $resources = $service->beginAttempt($quote->public_id, $ownerKey, $attemptId);
            $at = now()->toImmutable()->utc()->startOfSecond();
            $payload = app(OrderEvidence::class)->capture($orderId, $attemptId, $quote, $resources['pricing'],
                $resources['reservation'], $resources['promotion_use'], $policy, $request, $at);
            $canonical = CanonicalJson::encode($payload);
            if (strlen($canonical) > OrderEvidence::MAX_BYTES) {
                throw new QuoteException('ORDER_UNAVAILABLE', 503);
            }
            // Authenticate plaintext through Crypt; an unkeyed plaintext digest would permit identity guesses.
            $ciphertext = Crypt::encryptString($canonical);
            $order = Order::create(['public_id' => $orderId, 'owner_key' => $ownerKey, 'quote_id' => $quote->id,
                'quote_pricing_id' => $pricing->id, 'idempotency_key_hash' => $keyHash,
                'payload_ciphertext' => $ciphertext, 'payload_hash' => hash('sha256', $ciphertext),
                'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => $at]);
            foreach ($payload['lines'] as $line) {
                OrderLine::create(['order_id' => $order->id, 'position' => $line['position'],
                    'quote_line_id' => $line['quote_line_id'], 'offer_revision_id' => $line['offer_revision_id'],
                    'line_hash' => CanonicalJson::hash($line)]);
            }
            OrderAttempt::create(['public_id' => $attemptId, 'order_id' => $order->id,
                'inventory_reservation_id' => $resources['reservation']->id, 'promotion_use_id' => $resources['promotion_use']?->id,
                'binding' => $payload['attempt'], 'binding_hash' => CanonicalJson::hash($payload['attempt']),
                'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => $at, 'expires_at' => $payload['attempt']['expires_at']]);
            AuditEvent::record('commerce.order.prepared', $order, ['public_id' => $orderId,
                'quote_public_id' => $quote->public_id, 'attempt_id' => $attemptId, 'test_only' => true]);
            // Encryption, inserts, hooks and locks cannot extend the accepted price or reservation deadline.
            if ($resources['reservation']->expires_at->lessThanOrEqualTo(now()) || $pricing->expires_at->lessThanOrEqualTo(now()) ||
                $quote->expires_at->lessThanOrEqualTo(now())) { throw new QuoteException('INVENTORY_EXPIRED', 410); }

            return $order;
        }, 5);
    }
}
