<?php

namespace App\Domain\Commerce\Checkout;

use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HostedCheckout
{
    public function __construct(private CheckoutEvidence $evidence, private CheckoutPolicy $policy) {}

    public function start(string $id, string $ownerKey): array
    {
        $order = $this->owned($id, $ownerKey);
        $intent = DB::transaction(function () use ($order): CheckoutIntent {
            // A short order lock serializes one durable provider intent; never includes provider I/O.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = CheckoutIntent::where('order_id', $locked->id)->first();
            if ($existing) { $this->evidence->verifyIntent($existing, $locked); return $existing; }
            $policy = $this->policy->current(); $account = $this->policy->account();
            $captured = app(ReadOrder::class)->verify($locked); $attempt = $locked->attempt()->sole();
            if ($attempt->expires_at->lessThanOrEqualTo(now())) { throw new QuoteException('CHECKOUT_EXPIRED', 410); }
            $at = now()->toImmutable()->utc()->startOfSecond(); $publicId = (string) Str::uuid();
            $payload = $this->evidence->request($locked, $captured, $publicId, $policy, $account, $at);
            [$ciphertext, $hash] = $this->evidence->encrypt($payload);
            $intent = CheckoutIntent::create(['public_id' => $publicId, 'order_id' => $locked->id, 'order_attempt_id' => $attempt->id,
                'account_id' => $account, 'mode' => 'test', 'idempotency_key' => 'vasey-checkout-v1-'.$publicId,
                'request_ciphertext' => $ciphertext, 'request_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
                'created_at' => $at, 'initiate_before' => $attempt->expires_at, 'retry_before' => $at->addSeconds(900),
                'provider_expires_at' => $at->addSeconds(3600)]);
            AuditEvent::record('commerce.checkout.initiated', $intent, ['public_id' => $publicId, 'order_public_id' => $locked->public_id, 'test_only' => true]);
            if ($attempt->expires_at->lessThanOrEqualTo(now())) { throw new QuoteException('CHECKOUT_EXPIRED', 410); }

            return $intent;
        }, 5);
        if (! CheckoutSession::where('checkout_intent_id', $intent->id)->exists()) { $this->dispatch($intent); }

        return $this->projection($order, $intent);
    }

    public function status(string $id, string $ownerKey): array
    {
        $order = $this->owned($id, $ownerKey);

        return $this->projection($order, CheckoutIntent::where('order_id', $order->id)->first());
    }

    public function reconcile(string $id, string $ownerKey): array
    {
        $order = $this->owned($id, $ownerKey);
        $intent = CheckoutIntent::where('order_id', $order->id)->first();
        if ($intent) { $this->dispatch($intent, true); }

        return $this->projection($order, $intent);
    }

    /** Console recovery accepts only a locator; the retrieved provider object must match all retained evidence. */
    public function recover(CheckoutIntent $intent, ?string $providerSessionId = null): array
    {
        $this->dispatch($intent, true, $providerSessionId);

        return $this->projection(Order::findOrFail($intent->order_id), $intent);
    }

    private function owned(string $id, string $ownerKey): Order
    {
        QuoteRequest::owner($ownerKey);
        if (! OrderRequest::uuid($id)) { throw new QuoteException('ORDER_NOT_FOUND', 404); }
        $order = Order::where('public_id', $id)->where('owner_key', $ownerKey)->first();
        if (! $order) { throw new QuoteException('ORDER_NOT_FOUND', 404); }
        app(ReadOrder::class)->verify($order);

        return $order;
    }

    private function dispatch(CheckoutIntent $intent, bool $refresh = false, ?string $candidate = null): void
    {
        if (DB::transactionLevel() !== 0) { throw new QuoteException('CHECKOUT_UNAVAILABLE', 503); }
        $request = $this->evidence->verifyIntent($intent, Order::findOrFail($intent->order_id));
        $session = CheckoutSession::where('checkout_intent_id', $intent->id)->first();
        if ($candidate !== null && (! preg_match('/\Acs_test_[A-Za-z0-9]{1,120}\z/', $candidate) ||
            ($session && $session->provider_session_id !== $candidate))) { throw new QuoteException('CHECKOUT_CHANGED', 409); }
        if ($session && ! $refresh && $candidate === null) { return; }
        // No unbounded POST replay beyond the captured safe window, even when the outcome is unknown.
        if (! $session && $candidate === null && $intent->retry_before->lessThanOrEqualTo(now())) { return; }
        if ($this->policy->account() !== $intent->account_id) { throw new QuoteException('CHECKOUT_UNAVAILABLE', 503); }
        $gateway = app(StripeCheckoutGateway::class);
        try { $account = $gateway->account(); }
        catch (\Throwable) { throw new QuoteException('CHECKOUT_UNAVAILABLE', 503); }
        if (($account['id'] ?? null) !== $intent->account_id || ($account['object'] ?? null) !== 'account') {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }
        // Account validation may take time. Recheck the frozen send window just before a create.
        if (! $session && $candidate === null && $intent->retry_before->lessThanOrEqualTo(now())) { return; }
        try {
            $raw = $session || $candidate !== null ? $gateway->retrieve($session?->provider_session_id ?? $candidate) :
                $gateway->create($request['params'], $intent->idempotency_key);
        } catch (\Throwable) { throw new QuoteException('CHECKOUT_UNAVAILABLE', 503); }
        $observed = $this->evidence->session($raw, $request);
        if (($session && $observed['session_id'] !== $session->provider_session_id) || ($candidate !== null && $observed['session_id'] !== $candidate)) {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }
        $at = now()->toImmutable()->utc()->startOfSecond();
        DB::transaction(function () use ($intent, $request, $observed, $at): void {
            CheckoutIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $session = CheckoutSession::where('checkout_intent_id', $intent->id)->first();
            if ($session) {
                if ($session->provider_session_id !== $observed['session_id']) { throw new QuoteException('CHECKOUT_CHANGED', 409); }
                $latest = $this->latest($session, $request);
                // An older in-flight response cannot regress a terminal observation.
                if ($latest['status'] !== 'open' && $observed['status'] === 'open') { return; }
                if ($latest['status'] !== 'open' && $observed['status'] !== $latest['status']) { throw new QuoteException('CHECKOUT_CHANGED', 409); }
            }
            [$ciphertext, $hash] = $this->evidence->encrypt($observed);
            if (! $session) {
                $session = CheckoutSession::create(['checkout_intent_id' => $intent->id, 'account_id' => $intent->account_id, 'mode' => 'test',
                    'provider_session_id' => $observed['session_id'], 'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash,
                    'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => $at]);
                AuditEvent::record('commerce.checkout.bound', $session, ['intent_public_id' => $intent->public_id, 'test_only' => true]);
            }
            CheckoutObservation::create(['checkout_session_id' => $session->id, 'observed_at' => $at, 'status' => $observed['status'],
                'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION]);
        }, 5);
    }

    private function projection(Order $order, ?CheckoutIntent $intent): array
    {
        $payload = app(ReadOrder::class)->verify($order);
        $data = ['checkoutSchema' => 1, 'orderId' => $order->public_id, 'id' => $intent?->public_id,
            'currency' => 'USD', 'totalMinor' => $payload['pricing']['snapshot']['total_minor'], 'status' => 'not_started',
            'testOnly' => true, 'paymentStatus' => 'not_verified', 'fulfillmentStatus' => 'not_started',
            'url' => null, 'expiresAt' => null, 'observedAt' => null];
        if (! $intent) { return $data; }
        $request = $this->evidence->verifyIntent($intent, $order);
        $data['expiresAt'] = $intent->provider_expires_at->utc()->toISOString();
        $session = CheckoutSession::where('checkout_intent_id', $intent->id)->first();
        if (! $session) {
            $data['status'] = $intent->retry_before->greaterThan(now()) ? 'pending' : 'reconciliation_required';

            return $data;
        }
        $latest = $this->latest($session, $request);
        $data['status'] = $latest['status'];
        $data['observedAt'] = $latest['observed_at'];
        if ($latest['status'] === 'open') {
            if ($intent->provider_expires_at->greaterThan(now())) { $data['url'] = $latest['url']; }
            else { $data['status'] = 'reconciliation_required'; }
        }

        return $data;
    }

    private function latest(CheckoutSession $session, array $request): array
    {
        $initial = $this->evidence->decrypt($session->evidence_ciphertext, $session->evidence_hash, $session->canonicalization_version);
        $observation = CheckoutObservation::where('checkout_session_id', $session->id)->orderByDesc('id')->first();
        if (! $observation || $session->account_id !== $request['account_id'] || $session->mode !== 'test' ||
            ($initial['session_id'] ?? null) !== $session->provider_session_id || ($initial['intent_id'] ?? null) !== $request['intent_id']) {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }
        $latest = $this->evidence->decrypt($observation->evidence_ciphertext, $observation->evidence_hash, $observation->canonicalization_version);
        if (($latest['intent_id'] ?? null) !== $request['intent_id'] || ($latest['session_id'] ?? null) !== $session->provider_session_id ||
            ($latest['account_id'] ?? null) !== $request['account_id'] || ($latest['mode'] ?? null) !== 'test' ||
            ($latest['status'] ?? null) !== $observation->status || ! in_array($observation->status, ['open', 'complete', 'expired'], true) ||
            ($latest['expires_at'] ?? null) !== $request['params']['expires_at'] ||
            CanonicalJson::encode($latest['metadata'] ?? null) !== CanonicalJson::encode($request['params']['metadata']) ||
            ($observation->status === 'open' && ! $this->evidence->url($latest['url'] ?? null, $session->provider_session_id))) {
            throw new QuoteException('CHECKOUT_CHANGED', 409);
        }

        return $latest + ['observed_at' => $observation->observed_at->utc()->toISOString()];
    }
}
