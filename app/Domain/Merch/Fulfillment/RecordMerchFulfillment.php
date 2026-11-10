<?php

namespace App\Domain\Merch\Fulfillment;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Merch\Models\MerchFulfillment;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Binds a paid store order to what Printful must make and where it must go. It only records; nothing is sent to Printful here.
 *
 * Phase 1 boundary: the caller supplies the recipient and the Printful order items. Proving that those items are what the
 * buyer was charged for needs merch lines on the quote and order chain, which is phase 2. Until then this is not reachable
 * from checkout and the family stays off.
 */
final class RecordMerchFulfillment
{
    public function __construct(private readonly MerchFulfillmentPolicy $policy) {}

    /**
     * @param  array<string, mixed>  $recipient  Printful recipient object
     * @param  list<array<string, mixed>>  $items  Printful v2 order items (source catalog)
     */
    public function handle(Order $order, array $recipient, array $items, string $shipping): MerchFulfillment
    {
        $this->policy->requireEnabled();
        $payment = VerifiedPayment::query()->where('order_id', $order->id)->first();
        if (! $payment) {
            throw new MerchFulfillmentException('MERCH_PAYMENT_NOT_VERIFIED');
        }
        $request = $this->normalize($recipient, $items, $shipping);
        $hash = CanonicalJson::hash($request);

        $existing = MerchFulfillment::query()->where('order_id', $order->id)->first();
        if ($existing) {
            return $this->sameRequest($existing, $hash);
        }
        $publicId = (string) Str::uuid();
        try {
            return MerchFulfillment::query()->create([
                'public_id' => $publicId, 'order_id' => $order->id, 'verified_payment_id' => $payment->id,
                'external_id' => 'vasey-'.$publicId, 'state' => MerchFulfillment::RECORDED,
                'request_ciphertext' => Crypt::encryptString(CanonicalJson::encode($request)), 'request_hash' => $hash,
                'shipping_method' => $request['shipping'], 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $winner = MerchFulfillment::query()->where('order_id', $order->id)->first();
            if (! $winner) {
                throw new MerchFulfillmentException('MERCH_FULFILLMENT_UNAVAILABLE', 503);
            }

            return $this->sameRequest($winner, $hash);
        }
    }

    private function sameRequest(MerchFulfillment $fulfillment, string $hash): MerchFulfillment
    {
        if (! hash_equals($fulfillment->request_hash, $hash)) {
            // An order is fulfilled once, as first recorded; a different request for it is a conflict, never an overwrite.
            throw new MerchFulfillmentException('MERCH_REQUEST_CONFLICT');
        }

        return $fulfillment;
    }

    /**
     * @param  array<string, mixed>  $recipient
     * @param  list<array<string, mixed>>  $items
     * @return array{recipient: array<string, string>, items: list<array<string, mixed>>, shipping: string}
     */
    private function normalize(array $recipient, array $items, string $shipping): array
    {
        $clean = [];
        foreach (['name', 'address1', 'city', 'country_code', 'zip'] as $required) {
            if (! is_string($recipient[$required] ?? null) || trim($recipient[$required]) === '') {
                throw new MerchFulfillmentException('MERCH_RECIPIENT_INVALID', 422);
            }
        }
        foreach (['name', 'company', 'address1', 'address2', 'city', 'state_code', 'country_code', 'zip', 'phone', 'email'] as $field) {
            if (array_key_exists($field, $recipient) && $recipient[$field] !== null) {
                if (! is_string($recipient[$field]) || mb_strlen($recipient[$field]) > 200) {
                    throw new MerchFulfillmentException('MERCH_RECIPIENT_INVALID', 422);
                }
                $clean[$field] = $recipient[$field];
            }
        }
        if (preg_match('/\A[A-Z]{2}\z/D', $clean['country_code']) !== 1) {
            throw new MerchFulfillmentException('MERCH_RECIPIENT_INVALID', 422);
        }
        if (! array_is_list($items) || $items === [] || count($items) > 50) {
            throw new MerchFulfillmentException('MERCH_ITEMS_INVALID', 422);
        }
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item) || ($item['source'] ?? null) !== 'catalog' || ! is_int($item['catalog_variant_id'] ?? null)
                || $item['catalog_variant_id'] < 1 || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 100
                || ! is_array($item['placements'] ?? null) || $item['placements'] === []) {
                throw new MerchFulfillmentException('MERCH_ITEMS_INVALID', 422);
            }
            // retail_price is deliberately not accepted: the store never invents or forwards a price in phase 1.
            $normalized[] = ['source' => 'catalog', 'catalog_variant_id' => $item['catalog_variant_id'], 'quantity' => $item['quantity'],
                'placements' => $item['placements']];
        }
        if (preg_match('/\A[A-Z0-9_]{1,32}\z/D', $shipping) !== 1) {
            throw new MerchFulfillmentException('MERCH_SHIPPING_INVALID', 422);
        }

        return ['recipient' => $clean, 'items' => $normalized, 'shipping' => $shipping];
    }
}
