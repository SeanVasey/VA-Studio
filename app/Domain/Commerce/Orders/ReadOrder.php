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
use SensitiveParameter;
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

    /** Original prepared prices only; this projection does not establish payment or delivery rights. */
    public function items(string $id, #[SensitiveParameter] string $ownerKey): array
    {
        QuoteRequest::owner($ownerKey);
        if (! OrderRequest::uuid($id)) { throw new QuoteException('ORDER_NOT_FOUND', 404); }
        $order = Order::where('public_id', $id)->where('owner_key', $ownerKey)->first();
        if (! $order || ! hash_equals($order->owner_key, $ownerKey)) { throw new QuoteException('ORDER_NOT_FOUND', 404); }
        $payload = $this->verify($order);
        $pricing = $payload['pricing']['snapshot'];
        if (! in_array($pricing['schema_version'], [1, 2, 3], true) || $pricing['currency'] !== 'USD'
            || ! array_is_list($payload['lines']) || count($payload['lines']) < 1 || count($payload['lines']) > 10) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }

        $lines = [];
        foreach ($payload['lines'] as $position => $line) {
            $title = $line['selection']['offer_snapshot']['product']['title'];
            $name = $line['disclosure']['name'];
            $version = $line['disclosure']['version'];
            foreach ([$title, $name] as $text) {
                if (! is_string($text) || $text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 255) {
                    throw new QuoteException('ORDER_CHANGED', 409);
                }
            }
            if ($line['position'] !== $position || ! is_int($version) || $version < 1 || $line['pricing']['quantity'] !== 1) {
                throw new QuoteException('ORDER_CHANGED', 409);
            }
            $lines[] = ['position' => $position, 'title' => $title, 'licenseName' => $name, 'licenseVersion' => $version,
                'quantity' => $line['pricing']['quantity'], 'baseMinor' => $line['pricing']['base_minor'],
                'discountMinor' => $line['pricing']['discount_minor'], 'taxBasisMinor' => $line['pricing']['tax_basis_minor'],
                'taxMinor' => $line['pricing']['tax_minor'], 'totalMinor' => $line['pricing']['total_minor']];
        }

        return ['orderItemsSchema' => 1, 'orderId' => $order->public_id, 'testOnly' => true, 'currency' => $pricing['currency'],
            'subtotalMinor' => $pricing['subtotal_minor'], 'discountMinor' => $pricing['discount_minor'],
            'taxBasisMinor' => $pricing['tax_basis_minor'], 'taxMinor' => $pricing['tax_minor'], 'totalMinor' => $pricing['total_minor'],
            'lines' => $lines];
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

            $release = \App\Domain\Commerce\Models\TestUnpaidRelease::where('order_id', $order->id)->first();
            if ($release) { app(\App\Domain\Commerce\UnpaidRelease\ReadUnpaidRelease::class)->verify($release, $order, $payload); }
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
        return $this->projection($order, $this->verify($order));
    }

    /** One authorized, verified original supplies both the summary and its first-line preview. */
    public function historyEntry(Order $order): array
    {
        $payload = $this->verify($order);
        $lines = $payload['lines'];
        if (! array_is_list($lines) || count($lines) < 1 || count($lines) > 10 || $lines[0]['position'] !== 0) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
        $title = $lines[0]['selection']['offer_snapshot']['product']['title'];
        $name = $lines[0]['disclosure']['name'];
        $version = $lines[0]['disclosure']['version'];
        foreach ([$title, $name] as $text) {
            if (! is_string($text) || $text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 255) {
                throw new QuoteException('ORDER_CHANGED', 409);
            }
        }
        if (! is_int($version) || $version < 1) { throw new QuoteException('ORDER_CHANGED', 409); }

        return ['order' => $this->projection($order, $payload), 'preview' => [
            'orderId' => $order->public_id, 'itemCount' => count($lines),
            'firstItem' => ['title' => $title, 'licenseName' => $name, 'licenseVersion' => $version],
        ]];
    }

    private function projection(Order $order, #[SensitiveParameter] array $payload): array
    {

        return ['orderSchema' => 1, 'id' => $order->public_id, 'quoteId' => $payload['quote']['public_id'],
            'pricingId' => $payload['pricing']['public_id'], 'reviewHash' => $payload['review']['reviewHash'],
            'createdAt' => $order->created_at->utc()->toISOString(),
            'testOnly' => true, 'payable' => false, 'currency' => $payload['pricing']['snapshot']['currency'],
            'totalMinor' => $payload['pricing']['snapshot']['total_minor']] + app(ReadPaymentState::class)->projection($order, $payload);
    }
}
