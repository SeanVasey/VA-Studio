<?php

namespace App\Domain\SoundKits\Purchases;

use App\Domain\Commerce\Checkout\CheckoutPaymentMethods;
use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\ContractBindingException;
use App\Domain\Contracts\Production\SoundKitLicenseBinder;
use App\Domain\Contracts\Production\VaseyKitLicensePdfRenderer;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\SoundKitLicenseKind;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Domain\SoundKits\Products\SoundKitPurchaseBinding;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a Stripe-test kit order (BL-13, FP-007). The browser supplies only the kit's public address, the licensee's name and an
 * idempotency key; everything purchased (exact archive revision, archive hash and size, license version, price in integer minor
 * units, currency) comes from the listed product through {@see SoundKitPurchaseBinding}, which re-verifies the private archive
 * bytes against their scan evidence. A client-supplied price, revision or license is never read.
 *
 * A kit is a single-item, non-exclusive purchase: there is no rights-scope reservation (nothing is exclusive, so there is nothing to
 * serialize against), and a kit order never shares a cart with track licenses (the track quote accepts only track items).
 *
 * The order freezes the exact Stripe test Checkout request (one line naming the kit, the server price), so checkout, verification
 * and reconciliation compare against stored evidence, never against today's configuration.
 */
final class SoundKitOrders
{
    public function create(string $slug, string $ownerKey, string $idempotencyKey, mixed $licenseeName): SoundKitOrder
    {
        app(SoundKitPurchasePolicy::class)->require();
        try {
            QuoteRequest::owner($ownerKey);
            QuoteRequest::key($idempotencyKey);
        } catch (QuoteException) {
            throw new SoundKitPurchaseException('INVALID_REQUEST', 422);
        }
        if (! is_string($slug) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1 || strlen($slug) > 120) {
            throw new SoundKitPurchaseException('NOT_FOUND', 404);
        }
        if (! is_string($licenseeName)) {
            throw new SoundKitPurchaseException('LICENSEE_NAME_INVALID', 422);
        }
        $requestHash = CanonicalJson::hash(['schema' => 'sound-kit-order-request-v1', 'slug' => $slug, 'licensee_name' => $licenseeName]);
        $keyHash = hash('sha256', $idempotencyKey);
        $existing = SoundKitOrder::query()->where('owner_key', $ownerKey)->where('idempotency_key_hash', $keyHash)->first();
        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        $product = SoundKitProduct::query()->where('is_published', true)->where('slug', $slug)->first();
        if (! $product || ! app(SoundKitProducts::class)->listed($product)) {
            throw new SoundKitPurchaseException('NOT_FOUND', 404);
        }
        // File hashing happens here, outside any transaction: the archive is re-verified against scan evidence and its bytes.
        try {
            $binding = app(SoundKitPurchaseBinding::class)->capture($product);
        } catch (ValidationException) {
            throw new SoundKitPurchaseException('KIT_UNAVAILABLE', 409);
        }
        $license = LicenseVersion::query()->findOrFail($binding['snapshot']['license']['version_id']);
        $this->assertIssuable($license, $product, $licenseeName);
        $policy = $this->checkoutPolicy();

        return DB::transaction(function () use ($product, $binding, $license, $ownerKey, $keyHash, $requestHash, $licenseeName, $policy): SoundKitOrder {
            DB::table('quote_owners')->insertOrIgnore(['owner_key' => $ownerKey]);
            DB::table('quote_owners')->where('owner_key', $ownerKey)->lockForUpdate()->firstOrFail();
            $existing = SoundKitOrder::query()->where('owner_key', $ownerKey)->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing) {
                return $this->replay($existing, $requestHash);
            }
            // The product is re-read under lock: the terms the binding captured must still be the listed terms.
            $locked = SoundKitProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $products = app(SoundKitProducts::class);
            if (! $products->listed($locked) || ! hash_equals($products->termsHash($locked), $binding['snapshot']['offer_terms_hash'])) {
                throw new SoundKitPurchaseException('KIT_UNAVAILABLE', 409);
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            $publicId = (string) Str::uuid();
            $pin = $binding['snapshot'];
            $snapshot = ['schema' => 'sound-kit-test-order-v1', 'canonicalization' => CanonicalJson::VERSION, 'family' => 'wav_sample_kit',
                'order_id' => $publicId, 'binding_sha256' => $binding['snapshot_sha256'],
                'product' => ['id' => $locked->public_id, 'title' => $locked->title, 'slug' => $locked->slug], 'offer_terms_hash' => $pin['offer_terms_hash'],
                'kit_revision' => ['id' => $pin['kit_revision_id'], 'number' => $pin['kit_revision_number'], 'manifest_sha256' => $pin['manifest_sha256']],
                'archive' => ['sha256' => $pin['archive']['sha256'], 'size_bytes' => $pin['archive']['size_bytes'], 'mime_type' => 'application/zip'],
                'member_count' => $pin['member_count'],
                'license' => ['version_id' => $license->id, 'submission_hash' => $license->submission_hash, 'source_sha256' => hash('sha256', $license->authored_source)],
                'price_minor' => $pin['price_minor'], 'currency' => $pin['currency']];
            $account = app(CheckoutPolicy::class)->account();
            $request = $this->checkoutRequest($publicId, $locked->title, $pin['price_minor'], $policy, $account, $at, CanonicalJson::hash($snapshot));
            $ciphertext = Crypt::encryptString($licenseeName);
            $order = SoundKitOrder::create(['public_id' => $publicId, 'owner_key' => $ownerKey, 'idempotency_key_hash' => $keyHash,
                'request_hash' => $requestHash, 'sound_kit_product_id' => $locked->id, 'snapshot' => $snapshot,
                'snapshot_hash' => CanonicalJson::hash($snapshot), 'price_minor' => $pin['price_minor'], 'currency' => $pin['currency'],
                'licensee_ciphertext' => $ciphertext, 'licensee_hash' => hash('sha256', $ciphertext), 'account_id' => $account,
                'checkout_request' => $request, 'checkout_request_hash' => CanonicalJson::hash($request),
                'created_at' => $at, 'checkout_before' => $at->addMinutes(SoundKitPurchasePolicy::ORDER_MINUTES)]);
            AuditEvent::recordAttributed('sound_kit.test_order.created', $order, ['public_id' => $publicId, 'snapshot_hash' => $order->snapshot_hash,
                'test_only' => true], null);

            return $order;
        }, 5);
    }

    /** The stored order, verified: its snapshot and frozen request still hash to their recorded values. */
    public function verified(SoundKitOrder $order): SoundKitOrder
    {
        if (! is_array($order->snapshot) || ! hash_equals($order->snapshot_hash, CanonicalJson::hash($order->snapshot))
            || ! is_array($order->checkout_request) || ! hash_equals($order->checkout_request_hash, CanonicalJson::hash($order->checkout_request))
            || ($order->snapshot['order_id'] ?? null) !== $order->public_id || ($order->snapshot['price_minor'] ?? null) !== $order->price_minor
            || ($order->snapshot['currency'] ?? null) !== $order->currency || $order->currency !== 'USD'
            || ($order->checkout_request['order_payload_hash'] ?? null) !== $order->snapshot_hash
            || ($order->checkout_request['account_id'] ?? null) !== $order->account_id
            || array_sum(array_column(array_column($order->checkout_request['params']['line_items'] ?? [], 'price_data'), 'unit_amount')) !== $order->price_minor
            || ! hash_equals($order->licensee_hash, hash('sha256', $order->licensee_ciphertext))) {
            throw new SoundKitPurchaseException('CHECKOUT_CHANGED', 409);
        }

        return $order;
    }

    /** The order the owner holds, or a 404 indistinguishable from absence. */
    public function owned(string $publicId, string $ownerKey): SoundKitOrder
    {
        app(SoundKitPurchasePolicy::class)->require();
        if (! Str::isUuid($publicId) || preg_match('/\A[a-f0-9]{64}\z/D', $ownerKey) !== 1) {
            throw new SoundKitPurchaseException('NOT_FOUND', 404);
        }
        $order = SoundKitOrder::query()->where('public_id', $publicId)->first();
        if (! $order || ! hash_equals($order->owner_key, $ownerKey)) {
            throw new SoundKitPurchaseException('NOT_FOUND', 404);
        }

        return $this->verified($order);
    }

    public function licensee(SoundKitOrder $order): string
    {
        $this->verified($order);

        return Crypt::decryptString($order->licensee_ciphertext);
    }

    private function replay(SoundKitOrder $existing, string $requestHash): SoundKitOrder
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new SoundKitPurchaseException('IDEMPOTENCY_CONFLICT', 409);
        }

        return $this->verified($existing);
    }

    /**
     * Refuse BEFORE any money moves when the contract could not be issued afterwards: the license text must declare exactly the
     * six placeholders, the licensor name must be configured, and the licensee name must bind under the kit profile's input rule.
     */
    private function assertIssuable(LicenseVersion $license, SoundKitProduct $product, string $licenseeName): void
    {
        if (SoundKitLicenseKind::templateProblems((string) $license->authored_source) !== []) {
            throw new SoundKitPurchaseException('KIT_UNAVAILABLE', 409);
        }
        try {
            $licensor = SoundKitLicenseBinder::licensorFromConfig();
            $coverage = app(VaseyKitLicensePdfRenderer::class)->coverage();
        } catch (ContractBindingException|ContractIssuanceException) {
            throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
        }
        $purchase = ['kit_name' => $product->title, 'purchased_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'fee_minor' => $product->price_minor,
            'currency' => $product->currency, 'licensee_name' => $licenseeName, 'order_reference' => (string) Str::uuid()];
        try {
            SoundKitLicenseBinder::bind($purchase, $licensor, $coverage);
        } catch (ContractBindingException $error) {
            throw match ($error->placeholder) {
                'LICENSEE_NAME' => new SoundKitPurchaseException('LICENSEE_NAME_INVALID', 422),
                'LICENSOR_NAME' => new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503),
                default => new SoundKitPurchaseException('KIT_UNAVAILABLE', 409),
            };
        }
    }

    private function checkoutPolicy(): array
    {
        try {
            return app(CheckoutPolicy::class)->current();
        } catch (Throwable) {
            throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
        }
    }

    /**
     * The Stripe test Checkout request in the shape the track chain's evidence readers verify (CheckoutEvidence::session,
     * PaymentEvidence::capture): one line, quantity 1, the server price, named for the kit; no automatic tax, promotion codes or
     * adaptive pricing. `intent_id` and `attempt_id` are the kit order's own id (a kit order has one attempt and one intent).
     */
    private function checkoutRequest(string $orderId, string $title, int $price, array $policy, string $account, CarbonImmutable $at, string $snapshotHash): array
    {
        if ($price < 50 || $price > 99999999) {
            throw new SoundKitPurchaseException('KIT_UNAVAILABLE', 409);
        }
        $metadata = ['kit_order_id' => $orderId, 'schema_version' => 'kit-1'];
        $return = $policy['return_origin'].'/sound-kit-orders/'.$orderId;
        $params = ['mode' => 'payment', 'payment_method_types' => CheckoutPaymentMethods::offered(), 'client_reference_id' => $orderId,
            'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $price,
                'product_data' => ['name' => 'VASEY.AUDIO sound kit (test): '.mb_strimwidth($title, 0, 200, '')]]]],
            'automatic_tax' => ['enabled' => false], 'adaptive_pricing' => ['enabled' => false], 'allow_promotion_codes' => false,
            'customer_creation' => 'if_required', 'success_url' => $return, 'cancel_url' => $return,
            'expires_at' => $at->addSeconds(SoundKitPurchasePolicy::PROVIDER_SECONDS)->timestamp, 'expand' => ['line_items']];

        return ['schema_version' => 1, 'purpose' => 'stripe_test_kit_checkout', 'mapping' => 'kit_single_line_zero_test_tax_v1',
            'api_version' => CheckoutPolicy::API_VERSION, 'order_id' => $orderId, 'order_payload_hash' => $snapshotHash,
            'attempt_id' => $orderId, 'intent_id' => $orderId, 'account_id' => $account, 'mode' => 'test', 'policy_version' => $policy['version'],
            'idempotency_key' => 'vasey-kit-checkout-v1-'.$orderId, 'params' => $params];
    }
}
