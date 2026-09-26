<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Finalization\ReadFinalization;
use App\Domain\Commerce\Finalization\ReadPaymentState;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Durable owned evidence. No hold, expiry transition, fresh offer check or provider request. */
final class ReadOrder
{
    public function handle(string $id, string $ownerKey): array
    {
        QuoteRequest::owner($ownerKey);
        if (! OrderRequest::uuid($id)) { throw new QuoteException('ORDER_NOT_FOUND', 404); }
        $order = Order::where('public_id', $id)->where('owner_key', $ownerKey)->first();
        if (! $order) { throw new QuoteException('ORDER_NOT_FOUND', 404); }

        return $this->present($order);
    }

    public function forQuote(string $quoteId, string $ownerKey): array
    {
        QuoteRequest::owner($ownerKey);
        if (! OrderRequest::uuid($quoteId)) { throw new QuoteException('ORDER_NOT_FOUND', 404); }
        $id = Quote::where('public_id', $quoteId)->where('owner_key', $ownerKey)->value('id');
        $order = $id === null ? null : Order::where('quote_id', $id)->where('owner_key', $ownerKey)->first();
        if (! $order) { throw new QuoteException('ORDER_NOT_FOUND', 404); }

        return $this->present($order);
    }

    /** Internal private evidence reader; HTTP callers must authorize before using this method. */
    public function verify(Order $order): array
    {
        try {
            if ($order->canonicalization_version !== CanonicalJson::VERSION) { throw new \UnexpectedValueException; }
            if (! hash_equals($order->payload_hash, hash('sha256', $order->payload_ciphertext))) {
                throw new \UnexpectedValueException;
            }
            $canonical = Crypt::decryptString($order->payload_ciphertext);
            if (strlen($canonical) > OrderEvidence::MAX_BYTES) {
                throw new \UnexpectedValueException;
            }
            $payload = json_decode($canonical, true, 128, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || CanonicalJson::encode($payload) !== $canonical ||
                ($payload['schema_version'] ?? null) !== 1 || ($payload['purpose'] ?? null) !== 'test_order_preparation' ||
                ($payload['order_id'] ?? null) !== $order->public_id || ($payload['owner_key'] ?? null) !== $order->owner_key) {
                throw new \UnexpectedValueException;
            }
            $quote = Quote::findOrFail($order->quote_id); $pricing = QuotePricing::findOrFail($order->quote_pricing_id);
            $attempt = $order->attempt()->sole();
            $reservation = InventoryReservation::findOrFail($attempt->inventory_reservation_id);
            $use = $attempt->promotion_use_id === null ? null : PromotionUse::findOrFail($attempt->promotion_use_id);
            if ($quote->owner_key !== $order->owner_key || $attempt->canonicalization_version !== CanonicalJson::VERSION ||
                $attempt->public_id !== $payload['attempt_id'] || ! $attempt->created_at->equalTo($order->created_at)) {
                throw new \UnexpectedValueException;
            }
            $expected = app(OrderEvidence::class)->historical($order, $attempt, $quote, $pricing,
                $reservation, $use, $payload['policy'], $payload['request']);
            if (! hash_equals($canonical, CanonicalJson::encode($expected)) ||
                CanonicalJson::hash($expected['attempt']) !== $attempt->binding_hash ||
                CanonicalJson::hash($attempt->binding) !== $attempt->binding_hash ||
                $attempt->expires_at->toIso8601ZuluString() !== $expected['attempt']['expires_at']) {
                throw new \UnexpectedValueException;
            }
            $references = $order->lines()->orderBy('position')->get();
            if ($references->count() !== count($expected['lines'])) { throw new \UnexpectedValueException; }
            foreach ($expected['lines'] as $position => $line) {
                $reference = $references[$position];
                if ($reference->position !== $position || $reference->quote_line_id !== $line['quote_line_id'] ||
                    $reference->offer_revision_id !== $line['offer_revision_id'] || $reference->line_hash !== CanonicalJson::hash($line)) {
                    throw new \UnexpectedValueException;
                }
            }

            $finalization = OrderFinalization::where('order_id', $order->id)->first();
            if ($finalization) { app(ReadFinalization::class)->verify($finalization, $payload); }

            return $payload;
        } catch (QueryException $error) {
            // Preserve database deadlocks for the enclosing creation transaction's retry.
            throw $error;
        } catch (Throwable) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
    }

    /** Projection only, after the caller has checked ownership. */
    public function present(Order $order): array
    {
        $payload = $this->verify($order);

        return ['orderSchema' => 1, 'id' => $order->public_id, 'quoteId' => $payload['quote']['public_id'],
            'pricingId' => $payload['pricing']['public_id'], 'reviewHash' => $payload['review']['reviewHash'],
            'createdAt' => $order->created_at->utc()->toISOString(),
            'testOnly' => true, 'payable' => false, 'currency' => $payload['pricing']['snapshot']['currency'],
            'totalMinor' => $payload['pricing']['snapshot']['total_minor']] + app(ReadPaymentState::class)->projection($order, $payload);
    }
}
