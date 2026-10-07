<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\CurrentSelection;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\OwnAccountStripeGateway;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Buyer-bound provider-calculated tax checkout. The buyer assents to terms and pre-tax prices; Stripe Checkout
 * `automatic_tax` calculates tax on the hosted page; only an authoritative, complete and paid session is
 * retained as the amounts the buyer reviewed. No grant is issued and no client amount is accepted.
 */
final class ProductionTaxCheckout
{
    public function __construct(private readonly ProductionCustomerAccess $access, private readonly ?TaxCheckoutTransport $transport = null) {}

    public function preview(ProductionCustomerPrincipal $principal, User $buyer, int $candidateId, array $items): array
    {
        TaxCheckoutPolicy::requireEnabled();
        $items = PreparationSelection::items($items);
        $result = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $candidateId, $items): array {
            $tax = TaxCheckoutRecords::writer($rows);
            $access = $this->access->lock($principal, $buyer, $tax->current);
            $binding = $this->access->durableBinding($principal);
            $candidate = TaxCandidate::load($tax->current, $candidateId);
            $candidate['context']->requireBuyer($binding);
            $selection = CurrentSelection::load($tax->current, $items, CarbonImmutable::now('UTC'));
            $commitment = TaxCheckoutEvidence::commitment($binding, $candidate['binding'], $candidate['context'], $selection, $candidate['machine']);
            CurrentSelection::proveBytes($selection);
            CurrentSelection::proveCurrent($tax->current, $selection, CarbonImmutable::now('UTC'));
            TaxCandidate::proveCurrent($tax->current, $candidate);
            $this->access->proveCurrent($principal, $buyer, $tax->current, $access);
            TaxCheckoutPolicy::requireEnabled();

            return self::termsProjection($commitment, $selection) + ['previewHash' => CanonicalJson::hash($commitment)];
        });
        $this->access->current($principal, $buyer);

        return $result;
    }

    public function order(ProductionCustomerPrincipal $principal, User $buyer, int $candidateId, array $items, string $previewHash,
        bool $accepted, array $buyerDeclarations, string $key): array
    {
        TaxCheckoutPolicy::requireEnabled();
        CheckoutException::require($accepted === true && Evidence::hash($previewHash), 'invalid', 422);
        Evidence::keys($buyerDeclarations, ['legalName']);
        CheckoutException::require(is_string($buyerDeclarations['legalName']) && strlen($buyerDeclarations['legalName']) <= 255
            && trim($buyerDeclarations['legalName']) !== '' && mb_check_encoding($buyerDeclarations['legalName'], 'UTF-8')
            && ! preg_match('/[\x00-\x1f\x7f]/u', $buyerDeclarations['legalName']), 'invalid', 422);
        $declarations = ['legal_name' => trim($buyerDeclarations['legalName']), 'identity_meaning' => 'buyer_declared_legal_name'];
        $items = PreparationSelection::items($items);
        $digest = Evidence::key($key);

        $result = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $candidateId, $items, $previewHash, $declarations, $digest): array {
            $tax = TaxCheckoutRecords::writer($rows);
            $access = $this->access->lock($principal, $buyer, $tax->current);
            $binding = $this->access->durableBinding($principal);
            $request = ['candidate_id' => $candidateId, 'items' => $items, 'preview_hash' => $previewHash, 'accepted' => true,
                'buyer_origin_id' => $binding['origin_id'], 'buyer_declarations' => $declarations, 'key_digest' => $digest];
            $requestHash = CanonicalJson::hash($request);
            $existing = $tax->selector('order', 'buyer_origin_id = ? AND request_key = ?', [$binding['origin_id'], $digest]);
            CheckoutException::require(count($existing) <= 1);
            if ($existing !== []) {
                $order = TaxCheckoutEvidence::order($tax, $existing[0]);
                ProductionCheckout::requireOwner($binding, $order['body']['buyer']);
                CheckoutException::require($order['row']['request_hash'] === $requestHash);
                $this->access->proveCurrent($principal, $buyer, $tax->current, $access);

                return self::orderProjection($order);
            }
            $candidate = TaxCandidate::load($tax->current, $candidateId);
            $context = $candidate['context'];
            $context->requireBuyer($binding);
            $at = CarbonImmutable::now('UTC');
            $selection = CurrentSelection::load($tax->current, $items, $at);
            $commitment = TaxCheckoutEvidence::commitment($binding, $candidate['binding'], $context, $selection, $candidate['machine']);
            // The buyer assents only to the exact terms and pre-tax prices previewed; any change refuses.
            CheckoutException::require(hash_equals(CanonicalJson::hash($commitment), $previewHash), 'changed');
            $subtotal = $selection['selection']['advertised_subtotal_minor'];
            CheckoutException::require($subtotal >= 50 && $subtotal <= 99999999, 'unsupported');
            $public = (string) Str::uuid();
            $created = $at->format('Y-m-d\TH:i:s\Z');
            $lines = [];
            foreach ($selection['selection']['lines'] as $line) {
                $lines[] = ['public_id' => (string) Str::uuid(), 'position' => $line['position'], 'selection' => $line,
                    'currency' => 'USD', 'amount_minor' => $line['price_minor']];
            }
            $body = ['schema_version' => 1, 'purpose' => 'production_tax_checkout_order', 'public_id' => $public, 'created_at' => $created,
                'request' => $request, 'request_hash' => $requestHash, 'preview_hash' => $previewHash, 'buyer' => $binding,
                'buyer_declarations' => $declarations, 'candidate' => $candidate['binding'], 'machine' => $candidate['machine'],
                'execution_context' => $context->binding(), 'selection' => $selection, 'selection_hash' => $selection['selection_hash'],
                'seller' => $candidate['machine']['choices']['seller_identity'],
                'assent' => [...$candidate['machine']['choices']['assent'], 'accepted' => true, 'preview_hash' => $previewHash, 'accepted_at' => $created],
                'amounts' => TaxCheckoutEvidence::preTax($subtotal), 'lines' => $lines,
                'inventory' => ['kind' => 'unscoped_nonexclusive', 'selection_hash' => $selection['selection_hash']],
                'expires_at' => $at->addSeconds($context->reservationSeconds)->format('Y-m-d\TH:i:s\Z')];
            $record = $tax->insert('order', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body),
                'buyer_origin_id' => $binding['origin_id'], 'candidate_id' => $candidateId, 'request_key' => $digest, 'request_hash' => $requestHash,
                'currency' => 'USD', 'subtotal_minor' => $subtotal, 'line_count' => count($lines)]);
            $order = TaxCheckoutEvidence::order($tax, $record);
            CurrentSelection::proveBytes($selection);
            CurrentSelection::proveCurrent($tax->current, $selection, CarbonImmutable::now('UTC'));
            TaxCandidate::proveCurrent($tax->current, $candidate);
            Evidence::same([$record], $tax->selector('order', 'buyer_origin_id = ? AND request_key = ?', [$binding['origin_id'], $digest]));
            $this->access->proveCurrent($principal, $buyer, $tax->current, $access);
            TaxCheckoutPolicy::requireEnabled();

            return self::orderProjection($order);
        });
        $this->access->current($principal, $buyer);

        return $result;
    }

    /**
     * Retain the exact provider request before any I/O, then (only with an admitted transport) create and read the
     * hosted session. With `provider => null` the request is retained and the call refuses with no transport use.
     */
    public function initiate(ProductionCustomerPrincipal $principal, User $buyer, string $orderId): array
    {
        TaxCheckoutPolicy::requireEnabled();
        $prepared = $this->prepare($principal, $buyer, $orderId, true);
        if ($prepared['intent']['reviewed'] !== null) {
            return $this->status($principal, $buyer, $orderId);
        }
        $transport = TaxCheckoutPolicy::transport($this->transport);
        $context = $prepared['order']['context'];
        $request = $prepared['intent']['body'];
        try {
            $sessionId = $prepared['intent']['binding']['provider_session_id'] ?? null;
            if ($sessionId === null) {
                CheckoutException::require(CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z') < $request['retry_before'], 'retry_window');
                $created = $transport->create($context, $request['params'], $request['idempotency_key']);
                $sessionId = $created['id'] ?? null;
                CheckoutException::require(OwnAccountStripeGateway::sessionId($sessionId, $context->fundsMode));
            }
            $safe = TaxCheckoutEvidence::session($transport->retrieve($context, $sessionId), $request);
        } catch (Throwable $error) {
            $this->access->current($principal, $buyer);
            throw $error instanceof CheckoutException && in_array($error->reason, ['retry_window', 'tax_ceiling'], true)
                ? $error : new CheckoutException('provider_uncertain', 503);
        }
        if ($prepared['intent']['binding'] === null) {
            $this->bind($prepared['intent']['row']['public_id'], $sessionId);
        }
        $status = $this->status($principal, $buyer, $orderId);

        return $status + ['checkoutStatus' => $safe['status'], 'checkoutUrl' => $safe['status'] === 'open' ? $safe['url'] : null];
    }

    /** Authoritative GETs only. A complete, paid, tax-complete session is retained once; nothing else is written. */
    public function reconcile(ProductionCustomerPrincipal $principal, User $buyer, string $orderId): array
    {
        TaxCheckoutPolicy::requireEnabled();
        $prepared = $this->prepare($principal, $buyer, $orderId, false);
        if ($prepared['intent']['reviewed'] !== null) {
            return $this->status($principal, $buyer, $orderId);
        }
        $sessionId = $prepared['intent']['binding']['provider_session_id'] ?? null;
        CheckoutException::require($sessionId !== null, 'session_required');
        $transport = TaxCheckoutPolicy::transport($this->transport);
        $context = $prepared['order']['context'];
        $request = $prepared['intent']['body'];
        try {
            $session = $transport->retrieve($context, $sessionId);
            $safe = TaxCheckoutEvidence::session($session, $request);
            $payment = $safe['payment_intent_id'] === null ? null : $transport->paymentIntent($context, $safe['payment_intent_id']);
            $financial = TaxCheckoutEvidence::financial($session, $payment, $request);
        } catch (Throwable $error) {
            $this->access->current($principal, $buyer);
            throw $error instanceof CheckoutException && $error->reason === 'tax_ceiling' ? $error : new CheckoutException('provider_uncertain', 503);
        }
        if ($financial['outcome'] === 'confirmed') {
            $this->retain($prepared['intent']['row']['public_id'], $financial, CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), $transport->boundTo());
        }
        $status = $this->status($principal, $buyer, $orderId);

        return $status + ['checkoutStatus' => $financial['session']['status']];
    }

    public function status(ProductionCustomerPrincipal $principal, User $buyer, string $orderId): array
    {
        TaxCheckoutPolicy::requireEnabled();
        $result = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $orderId): array {
            $tax = TaxCheckoutRecords::writer($rows);
            $access = $this->access->lock($principal, $buyer, $tax->current);
            $order = TaxCheckoutEvidence::order($tax, $tax->one('order', $orderId));
            ProductionCheckout::requireOwner($this->access->durableBinding($principal), $order['body']['buyer']);
            $result = self::orderProjection($order);
            $records = $tax->selector('request', 'order_id = ?', [$order['row']['id']]);
            CheckoutException::require(count($records) <= 1);
            if ($records !== []) {
                $intent = TaxCheckoutEvidence::intent($tax, $order, $records[0]);
                if ($intent['reviewed'] !== null) {
                    $result['paymentStatus'] = 'verified';
                    $result['fulfillmentStatus'] = $intent['reviewed_body']['outcome'] === 'on_time' ? 'pending_fulfillment' : 'paid_exception';
                    $result['reviewedAmounts'] = $intent['reviewed_body']['amounts'];
                }
            }
            $this->access->proveCurrent($principal, $buyer, $tax->current, $access);

            return $result;
        });
        $this->access->current($principal, $buyer);

        return $result;
    }

    private function prepare(ProductionCustomerPrincipal $principal, User $buyer, string $orderId, bool $create): array
    {
        $prepared = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $orderId, $create): array {
            $tax = TaxCheckoutRecords::writer($rows);
            $access = $this->access->lock($principal, $buyer, $tax->current);
            $order = TaxCheckoutEvidence::order($tax, $tax->one('order', $orderId));
            ProductionCheckout::requireOwner($this->access->durableBinding($principal), $order['body']['buyer']);
            $requests = $tax->selector('request', 'order_id = ?', [$order['row']['id']]);
            CheckoutException::require(count($requests) <= 1);
            CheckoutException::require($create || $requests !== [], 'request_required');
            $candidate = null;
            $selection = null;
            if ($create) {
                // Never send stale terms or prices to the provider: the approved candidate and the selection must be current.
                $candidate = TaxCandidate::load($tax->current, $order['row']['candidate_id']);
                Evidence::same($order['body']['candidate'], $candidate['binding']);
                Evidence::same($order['context']->binding(), $candidate['context']->binding());
                $selection = CurrentSelection::load($tax->current, $order['body']['request']['items'], CarbonImmutable::now('UTC'));
                Evidence::same($order['body']['selection'], $selection);
            }
            if ($requests === []) {
                $at = CarbonImmutable::now('UTC');
                $context = $order['context'];
                CheckoutException::require($at->addSeconds($context->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z') <= $order['body']['expires_at'], 'expired');
                $public = (string) Str::uuid();
                $created = $at->format('Y-m-d\TH:i:s\Z');
                $body = TaxCheckoutEvidence::request($order, $public, $created);
                $record = $tax->insert('request', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body),
                    'order_id' => $order['row']['id'], 'account_id' => $context->accountId, 'funds_mode' => $context->fundsMode,
                    'idempotency_key' => $body['idempotency_key'], 'currency' => 'USD', 'subtotal_minor' => $order['row']['subtotal_minor'],
                    'tax_behavior' => $context->taxBehavior, 'maximum_rate_bps' => $context->maximumRateBps,
                    'provider_expires_at' => $body['provider_expires_at']]);
            } else {
                $record = $requests[0];
            }
            $intent = TaxCheckoutEvidence::intent($tax, $order, $record);
            if ($create) {
                CurrentSelection::proveBytes($selection);
                CurrentSelection::proveCurrent($tax->current, $selection, CarbonImmutable::now('UTC'));
                TaxCandidate::proveCurrent($tax->current, $candidate);
            }
            $this->access->proveCurrent($principal, $buyer, $tax->current, $access);
            TaxCheckoutPolicy::requireEnabled();

            return compact('order', 'intent');
        });
        $this->access->current($principal, $buyer);

        return $prepared;
    }

    /** Internal persistence of the provider locator returned by the authoritative read; no buyer input. */
    private function bind(string $requestId, string $sessionId): void
    {
        CommandTransaction::run(function (Records $rows) use ($requestId, $sessionId): void {
            $tax = TaxCheckoutRecords::writer($rows);
            $record = $tax->one('request', $requestId);
            $order = TaxCheckoutEvidence::order($tax, $tax->byId('order', $record['order_id']));
            $intent = TaxCheckoutEvidence::intent($tax, $order, $record);
            if ($intent['binding'] !== null) {
                CheckoutException::require($intent['binding']['provider_session_id'] === $sessionId);

                return;
            }
            $public = (string) Str::uuid();
            $created = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
            $body = TaxCheckoutEvidence::binding(['row' => $record, 'body' => $intent['body']], $public, $created, $sessionId);
            $tax->insert('binding', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body), 'request_id' => $record['id'],
                'account_id' => $order['context']->accountId, 'funds_mode' => $order['context']->fundsMode, 'provider_session_id' => $sessionId]);
            TaxCheckoutEvidence::intent($tax, $order, $record);
        });
    }

    /** Retain the buyer-reviewed, provider-calculated paid session exactly once. */
    private function retain(string $requestId, array $financial, string $observedAt, string $transport): void
    {
        CommandTransaction::run(function (Records $rows) use ($requestId, $financial, $observedAt, $transport): void {
            $tax = TaxCheckoutRecords::writer($rows);
            $record = $tax->one('request', $requestId);
            $order = TaxCheckoutEvidence::order($tax, $tax->byId('order', $record['order_id']));
            $intent = TaxCheckoutEvidence::intent($tax, $order, $record);
            CheckoutException::require($intent['binding'] !== null && $intent['binding']['provider_session_id'] === $financial['session']['session_id']);
            Evidence::same($financial, TaxCheckoutEvidence::financial($financial['retained_session'], $financial['payment'], $intent['body']));
            if ($intent['reviewed'] !== null) {
                CheckoutException::require($intent['reviewed']['provider_payment_id'] === $financial['payment']['id']);

                return;
            }
            $public = (string) Str::uuid();
            $created = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
            $body = TaxCheckoutEvidence::reviewedBody($order, ['row' => $record, 'body' => $intent['body']], $intent['binding'], $financial,
                $public, $created, $observedAt, $transport);
            $amounts = $body['amounts'];
            $tax->insert('reviewed', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body), 'binding_id' => $intent['binding']['id'],
                'request_id' => $record['id'], 'order_id' => $order['row']['id'], 'account_id' => $order['context']->accountId,
                'funds_mode' => $order['context']->fundsMode, 'provider_session_id' => $intent['binding']['provider_session_id'],
                'provider_payment_id' => $financial['payment']['id'], 'currency' => 'USD', 'tax_behavior' => $amounts['tax_behavior'],
                'amount_subtotal_minor' => $amounts['subtotal_minor'], 'amount_tax_minor' => $amounts['tax_minor'],
                'amount_total_minor' => $amounts['total_minor'], 'observed_at' => $observedAt]);
            TaxCheckoutEvidence::intent($tax, $order, $record);
        });
    }

    private static function termsProjection(array $commitment, array $selection): array
    {
        return ['candidateId' => $commitment['candidate']['candidate_id'], 'seller' => $commitment['seller'], 'assent' => $commitment['assent'],
            'amounts' => $commitment['amounts'], 'fundsMode' => $commitment['execution_context']['funds_mode'],
            'taxBehavior' => $commitment['execution_context']['tax']['behavior'],
            'licenses' => array_map(fn (array $line): array => ['position' => $line['position'], 'trackId' => $line['track_id'],
                'offerRevisionId' => $line['offer_revision_id'], 'trackTitle' => $line['offer_snapshot']['product']['title'],
                'artist' => $line['offer_snapshot']['product']['artist'], 'licenseVersionId' => $line['license_version_id'],
                'priceMinor' => $line['price_minor'], 'license' => array_intersect_key($line['offer_snapshot']['license'],
                    array_flip(['name', 'version', 'authored_source', 'source_hash', 'structured_terms', 'features']))], $selection['selection']['lines'])];
    }

    private static function orderProjection(array $order): array
    {
        return ['orderId' => $order['row']['public_id'], 'createdAt' => $order['row']['created_at'], 'amounts' => $order['body']['amounts'],
            'assentAccepted' => true, 'fundsMode' => $order['context']->fundsMode, 'paymentStatus' => 'unverified', 'fulfillmentStatus' => 'pending_payment'];
    }
}
