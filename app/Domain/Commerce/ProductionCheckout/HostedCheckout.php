<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/** One durable request before I/O; retrieved financial evidence survives identity/source withdrawal. No grants are issued. */
final class HostedCheckout
{
    public function __construct(private readonly ProductionCustomerAccess $access, private readonly ProviderGateway $gateway) {}

    public function initiate(ProductionCustomerPrincipal $principal, User $buyer, string $orderId): array
    {
        $prepared = $this->prepare($principal, $buyer, $orderId, true);
        $this->access->current($principal, $buyer);
        CheckoutException::require(config('production_checkout.fresh_checkout_enabled') === true, 'disabled', 503);
        $context = $prepared['order']['context'];
        $origin = HostedEvidence::requireGateway($this->gateway, $context);
        if ($prepared['intent']['session'] === null) {
            // Final read-only re-proof immediately before the first provider create. Any change after
            // this point crosses the external call and belongs to reconciliation/refund handling.
            $this->proveCreatable($principal, $buyer, $prepared);
        }
        try {
            $sessionId = $prepared['intent']['session']['provider_session_id'] ?? null;
            if ($sessionId === null) {
                CheckoutException::require(CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z') < $prepared['intent']['row']['retry_before'], 'retry_window');
                $candidate = $this->gateway->create($context, $prepared['intent']['body']['params'], $prepared['intent']['row']['idempotency_key']);
                $sessionId = $candidate['id'] ?? null;
                CheckoutException::require(OwnAccountStripeGateway::sessionId($sessionId, $context->fundsMode));
            }
            $session = $this->gateway->retrieve($context, $sessionId);
            $safe = HostedEvidence::session($session, $prepared['intent']['body']);
            $payment = $safe['payment_intent_id'] === null ? null : $this->gateway->paymentIntent($context, $safe['payment_intent_id']);
            HostedEvidence::financial($session, $payment, $prepared['intent']['body']);
            $observed = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
            $this->record($prepared['intent']['row']['public_id'], $session, $payment, $origin, $observed);
        } catch (Throwable) {
            $this->uncertain($prepared['intent']['row']['public_id']);
            $this->access->current($principal, $buyer);
            throw new CheckoutException('provider_uncertain', 503);
        }
        $this->access->current($principal, $buyer);

        return $this->status($principal, $buyer, $orderId);
    }

    public function reconcile(ProductionCustomerPrincipal $principal, User $buyer, string $orderId, ?string $verifiedLocator = null): array
    {
        CheckoutException::require(config('production_checkout.reconciliation_enabled') === true, 'disabled', 503);
        $prepared = $this->prepare($principal, $buyer, $orderId, false);
        $this->access->current($principal, $buyer);
        $context = $prepared['order']['context'];
        $origin = HostedEvidence::requireGateway($this->gateway, $context);
        $sessionId = $prepared['intent']['session']['provider_session_id'] ?? $verifiedLocator;
        CheckoutException::require(OwnAccountStripeGateway::sessionId($sessionId, $context->fundsMode), 'locator_required');
        if ($verifiedLocator !== null && $prepared['intent']['session'] !== null) {
            CheckoutException::require($verifiedLocator === $prepared['intent']['session']['provider_session_id']);
        }
        try {
            $session = $this->gateway->retrieve($context, $sessionId);
            $safe = HostedEvidence::session($session, $prepared['intent']['body']);
            $payment = $safe['payment_intent_id'] === null ? null : $this->gateway->paymentIntent($context, $safe['payment_intent_id']);
            HostedEvidence::financial($session, $payment, $prepared['intent']['body']);
            $observed = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
            $this->record($prepared['intent']['row']['public_id'], $session, $payment, $origin, $observed);
        } catch (Throwable) {
            $this->uncertain($prepared['intent']['row']['public_id']);
            $this->access->current($principal, $buyer);
            throw new CheckoutException('provider_uncertain', 503);
        }
        // A lost access credential suppresses buyer projection, never deletes the money observation above.
        $this->access->current($principal, $buyer);

        return $this->status($principal, $buyer, $orderId);
    }

    private function prepare(ProductionCustomerPrincipal $principal, User $buyer, string $orderId, bool $create): array
    {
        return CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $orderId, $create): array {
            $access = $this->access->lock($principal, $buyer, $rows->current);
            $order = OrderEvidence::order($rows, $rows->one('order', $orderId));
            $binding = $this->access->durableBinding($principal);
            ProductionCheckout::requireOwner($binding, $order['body']['buyer']);
            $intents = $rows->selector('intent', 'order_id = ?', [$order['row']['id']]);
            CheckoutException::require(count($intents) <= 1);
            $current = null;
            $selection = null;
            $basis = null;
            $fresh = null;
            $inserted = false;
            if ($create) {
                CheckoutException::require(config('production_checkout.fresh_checkout_enabled') === true, 'disabled', 503);
                $fresh = FreshCheckoutPolicy::capture();
                $current = CurrentPolicy::load($rows->current, $order['review']['row']['candidate_id']);
                Evidence::same($current['binding'], $order['review']['body']['candidate']);
                Evidence::same($current['context']->binding(), $order['context']->binding());
                $current['context']->requireBuyer($binding);
                $at = CarbonImmutable::now('UTC');
                $selection = CurrentSelection::load($rows->current, $order['review']['body']['request']['items'], $at);
                Evidence::same($selection, $order['review']['body']['selection']);
                $basis = TaxExemptions::basis($rows, $order['review']['body']['basis_public_id'], $current, $order['body']['buyer'], $selection, $at, $order['attempt']['expires_at']);
            }
            if ($intents === []) {
                CheckoutException::require($create === true, 'intent_required');
                $context = $order['context'];
                $at = CarbonImmutable::now('UTC');
                CheckoutException::require($at->addSeconds($context->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z') <= $order['attempt']['expires_at'], 'expired');
                $public = (string) Str::uuid();
                $created = $at->format('Y-m-d\TH:i:s\Z');
                $request = HostedEvidence::request($order, $public, $created);
                $record = $rows->insert('intent', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($request),
                    'order_id' => $order['row']['id'], 'attempt_id' => $order['attempt']['id'], 'account_id' => $context->accountId, 'funds_mode' => $context->fundsMode,
                    'idempotency_key' => $request['idempotency_key'], 'retry_before' => $at->addSeconds($context->retrySeconds)->format('Y-m-d\TH:i:s\Z'),
                    'provider_expires_at' => $at->addSeconds($context->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z')]);
                $inserted = true;
            } else {
                $record = $intents[0];
            }
            $intent = HostedEvidence::intent($rows, $order, $record);
            if ($create && $intent['session'] === null) {
                CheckoutException::require(CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z') < $intent['row']['retry_before'], 'retry_window');
            }
            if ($selection !== null) {
                CurrentSelection::proveBytes($selection);
                TaxExemptions::proveRetained($rows, $basis);
                CurrentSelection::proveCurrent($rows->current, $selection, CarbonImmutable::now('UTC'));
                CurrentPolicy::proveCurrent($rows->current, $current);
                CheckoutException::require(config('production_checkout.fresh_checkout_enabled') === true, 'disabled', 503);
            } else {
                CheckoutException::require(config('production_checkout.reconciliation_enabled') === true, 'disabled', 503);
            }
            OrderEvidence::proveRetained($rows, $order['raw']);
            HostedEvidence::proveRetained($rows, $intent['raw']);
            $this->access->proveCurrent($principal, $buyer, $rows->current, $access);
            if ($inserted === true) {
                $fresh->prove();
                // Only a NEW intent installs the one commit observer; retries, reads and reconciliation do not.
                CheckoutIntentAdmission::capture($rows, $access, $buyer, $fresh, $order, $intent, $current, $selection, $basis);
            }

            return compact('order', 'intent', 'current', 'selection', 'basis');
        });
    }

    /**
     * Read-only raw re-proof of the retained policy, selection, basis and request before the first create.
     *
     * This is the one read frame that carries the commit observer: it is the last proof before the
     * provider boundary, so a committing listener on this frame must not be able to withdraw the offer
     * or close the capability after the proofs ran (Codex P1 r4210033214).
     */
    private function proveCreatable(ProductionCustomerPrincipal $principal, User $buyer, array $prepared): void
    {
        CheckoutException::require($prepared['current'] !== null && $prepared['selection'] !== null && $prepared['basis'] !== null);
        CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $prepared): void {
            $access = $this->access->lock($principal, $buyer, $rows->current);
            CheckoutException::require(config('production_checkout.fresh_checkout_enabled') === true, 'disabled', 503);
            $fresh = FreshCheckoutPolicy::capture();
            OrderEvidence::proveRetained($rows, $prepared['order']['raw']);
            HostedEvidence::proveRetained($rows, $prepared['intent']['raw']);
            TaxExemptions::proveRetained($rows, $prepared['basis']);
            CurrentSelection::proveCurrent($rows->current, $prepared['selection'], CarbonImmutable::now('UTC'));
            CurrentPolicy::proveCurrent($rows->current, $prepared['current']);
            $this->access->proveCurrent($principal, $buyer, $rows->current, $access);
            CheckoutException::require(config('production_checkout.fresh_checkout_enabled') === true, 'disabled', 503);
            $fresh->prove();
            CheckoutIntentAdmission::reprove($rows, $access, $buyer, $fresh, $prepared['order'], $prepared['intent'],
                $prepared['current'], $prepared['selection'], $prepared['basis']);
        });
        $this->access->current($principal, $buyer);
    }

    /** Internal system persistence after provider reads; current buyer credentials are deliberately checked afterward. */
    private function record(string $intentId, array $session, ?array $payment, string $origin, string $observedAt): void
    {
        CommandTransaction::run(function (Records $rows) use ($intentId, $session, $payment, $origin, $observedAt): void {
            $record = $rows->one('intent', $intentId);
            $order = OrderEvidence::order($rows, $rows->current->one(CheckoutSchema::TABLES['order'], $record['order_id']));
            $intent = HostedEvidence::intent($rows, $order, $record);
            CheckoutException::require($origin === ($order['context']->fundsMode === 'live' ? 'own_account_sdk' : 'synthetic_rehearsal'));
            $financial = HostedEvidence::financial($session, $payment, $intent['body']);
            $safeSession = HostedEvidence::retainSession($session);
            if ($intent['session'] === null) {
                $public = (string) Str::uuid();
                $created = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
                $binding = ['schema_version' => 1, 'purpose' => 'production_checkout_session_binding', 'public_id' => $public, 'created_at' => $created,
                    'intent_public_id' => $record['public_id'], 'intent_hash' => $record['payload_hash'], 'provider_session_id' => $session['id'],
                    'execution_context' => $order['context']->binding()];
                $rows->insert('session', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($binding), 'intent_id' => $record['id'],
                    'account_id' => $order['context']->accountId, 'funds_mode' => $order['context']->fundsMode, 'provider_session_id' => $session['id']]);
                $intent = HostedEvidence::intent($rows, $order, $record);
            } else {
                CheckoutException::require($intent['session']['provider_session_id'] === $session['id']);
            }
            if ($intent['last_known'] !== null) {
                $prior = HostedEvidence::financial($intent['last_known']['detail']['session'], $intent['last_known']['detail']['payment'], $intent['body']);
                CheckoutException::require(! in_array($prior['session']['status'], ['complete', 'expired'], true)
                    || $prior['session']['status'] === $financial['session']['status'], 'terminal_regression');
            }
            if ($financial['outcome'] === 'confirmed') {
                if ($intent['payment'] === null) {
                    $public = (string) Str::uuid();
                    $created = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
                    $outcome = $observedAt < $order['attempt']['expires_at'] ? 'on_time' : 'paid_exception';
                    $confirmation = ['schema_version' => 1, 'purpose' => 'production_checkout_confirmed_payment', 'public_id' => $public, 'created_at' => $created,
                        'order_id' => $order['row']['public_id'], 'order_hash' => $order['row']['payload_hash'], 'intent_id' => $record['public_id'], 'intent_hash' => $record['payload_hash'],
                        'session_id' => $session['id'], 'observed_at' => $observedAt, 'amount_minor' => $order['body']['amounts']['total_minor'],
                        'outcome' => $outcome, 'provider_evidence_origin' => $origin, 'provider_session' => $safeSession, 'provider_payment' => $financial['payment']];
                    $rows->insert('payment', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($confirmation), 'intent_id' => $record['id'],
                        'session_id' => $intent['session']['id'], 'order_id' => $order['row']['id'], 'account_id' => $order['context']->accountId,
                        'funds_mode' => $order['context']->fundsMode, 'provider_payment_id' => $financial['payment']['id'], 'amount_minor' => $confirmation['amount_minor'],
                        'observed_at' => $observedAt, 'outcome' => $outcome]);
                } else {
                    CheckoutException::require($intent['payment']['provider_payment_id'] === $financial['payment']['id']);
                }
            }
            $detail = ['session' => $safeSession, 'payment' => $financial['payment']];
            $kind = HostedEvidence::observationKind($financial);
            if ($intent['last'] === null || $intent['last']['kind'] !== $kind || CanonicalJson::encode($intent['last']['detail']) !== CanonicalJson::encode($detail)) {
                $this->append($rows, $intent, $kind, $detail, $origin, $observedAt);
            }
            $after = HostedEvidence::intent($rows, $order, $record);
            OrderEvidence::proveRetained($rows, $order['raw']);
            HostedEvidence::proveRetained($rows, $after['raw']);
        });
    }

    private function uncertain(string $intentId): void
    {
        CommandTransaction::run(function (Records $rows) use ($intentId): void {
            $record = $rows->one('intent', $intentId);
            $order = OrderEvidence::order($rows, $rows->current->one(CheckoutSchema::TABLES['order'], $record['order_id']));
            $intent = HostedEvidence::intent($rows, $order, $record);
            if ($intent['last'] === null || $intent['last']['kind'] !== 'uncertain') {
                $this->append($rows, $intent, 'uncertain', ['code' => 'provider_unavailable'], null, CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'));
            }
            $after = HostedEvidence::intent($rows, $order, $record);
            OrderEvidence::proveRetained($rows, $order['raw']);
            HostedEvidence::proveRetained($rows, $after['raw']);
        });
    }

    private function append(Records $rows, array $intent, string $kind, array $detail, ?string $origin, string $observedAt): void
    {
        $sequence = count($intent['observations']) + 1;
        CheckoutException::require($sequence <= 128, 'observation_limit');
        $public = (string) Str::uuid();
        $created = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $body = ['schema_version' => 1, 'purpose' => 'production_checkout_observation', 'public_id' => $public, 'created_at' => $created,
            'sequence' => $sequence, 'intent_id' => $intent['row']['public_id'], 'intent_hash' => $intent['row']['payload_hash'], 'kind' => $kind,
            'observed_at' => $observedAt, 'provider_evidence_origin' => $origin, 'detail' => $detail];
        $rows->insert('observation', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body),
            'intent_id' => $intent['row']['id'], 'sequence' => $sequence, 'kind' => $kind]);
    }

    public function status(ProductionCustomerPrincipal $principal, User $buyer, string $orderId): array
    {
        $result = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $orderId): array {
            $access = $this->access->lock($principal, $buyer, $rows->current);
            $order = OrderEvidence::order($rows, $rows->one('order', $orderId));
            ProductionCheckout::requireOwner($this->access->durableBinding($principal), $order['body']['buyer']);
            $result = ProductionCheckout::orderProjection($order);
            $records = $rows->selector('intent', 'order_id = ?', [$order['row']['id']]);
            CheckoutException::require(count($records) <= 1);
            if ($records !== []) {
                $intent = HostedEvidence::intent($rows, $order, $records[0]);
                if ($intent['payment'] !== null) {
                    $result['paymentStatus'] = 'verified';
                    $result['fulfillmentStatus'] = $intent['payment']['outcome'] === 'on_time' ? 'pending_fulfillment' : 'paid_exception';
                } elseif ($intent['last'] !== null && $intent['last']['kind'] !== 'uncertain') {
                    $session = HostedEvidence::session($intent['last']['detail']['session'], $intent['body']);
                    $result['checkoutStatus'] = $session['status'];
                    if ($session['status'] === 'open') {
                        $result['checkoutUrl'] = $session['url'];
                    }
                } else {
                    $result['checkoutStatus'] = 'unknown';
                }
                HostedEvidence::proveRetained($rows, $intent['raw']);
            }
            OrderEvidence::proveRetained($rows, $order['raw']);
            $this->access->proveCurrent($principal, $buyer, $rows->current, $access);

            return $result;
        });
        $this->access->current($principal, $buyer);

        return $result;
    }
}
