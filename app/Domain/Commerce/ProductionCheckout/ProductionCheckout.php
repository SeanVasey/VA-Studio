<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ContractIdentity\ContractIdentityStatements;
use App\Domain\Commerce\ContractIdentity\ContractPrintableSet;
use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Actual buyer-bound review/assent/order commands. Provider dispatch and historical test commerce are separate. */
final class ProductionCheckout
{
    public function __construct(private readonly ProductionCustomerAccess $access) {}

    /**
     * D-30: what the buyer is shown before the review while contract identity capture is on: the licensee types and each
     * type's confirmation statement. The review request echoes the displayed `statementVersion`.
     */
    public static function contractIdentityOffer(): array
    {
        CheckoutException::require(ContractIdentityPolicy::contractIdentityV2(), 'disabled', 503);

        return ['licenseeTypes' => ContractIdentityDeclarationV2::LICENSEE_TYPES, 'statements' => ContractIdentityStatements::offered()];
    }

    public function review(ProductionCustomerPrincipal $principal, User $buyer, int $candidateId, array $items,
        string $basisId, array $buyerDeclarations, string $key): array
    {
        $buyerDeclarations = ContractIdentityPolicy::contractIdentityV2()
            // D-30: the licensee identity a production contract binds, confirmed against the statement version the buyer was
            // shown. Exclusive has no production path (ExclusiveInventoryGate), so no artist name is admitted here.
            ? ContractIdentityDeclarationV2::fromDisplayedInput($buyerDeclarations, false, ContractIdentityStatements::current(),
                ContractPrintableSet::checkout())
            : self::legacyDeclarations($buyerDeclarations);
        $items = PreparationSelection::items($items);
        $digest = Evidence::key($key);

        $result = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $candidateId, $items, $basisId, $buyerDeclarations, $digest): array {
            $access = $this->access->lock($principal, $buyer, $rows->current);
            $binding = $this->access->durableBinding($principal);
            $request = ['candidate_id' => $candidateId, 'items' => $items, 'basis_id' => $basisId, 'buyer_origin_id' => $binding['origin_id'],
                'buyer_declarations' => $buyerDeclarations, 'key_digest' => $digest];
            $hash = CanonicalJson::hash($request);
            $existing = $rows->selector('review', 'buyer_origin_id = ? AND request_key = ?', [$binding['origin_id'], $digest]);
            CheckoutException::require(count($existing) <= 1);
            if ($existing !== []) {
                $retained = OrderEvidence::review($rows, $existing[0]);
                CheckoutException::require($retained['row']['request_hash'] === $hash);
                self::requireOwner($binding, $retained['body']['buyer']);
                self::retainedIdentity($retained['body']['buyer_declarations']);
                Evidence::same([$retained['row']], $rows->selector('review', 'buyer_origin_id = ? AND request_key = ?', [$binding['origin_id'], $digest]));
                OrderEvidence::proveRetained($rows, $retained['raw']);
                $this->access->proveCurrent($principal, $buyer, $rows->current, $access);

                return self::reviewProjection($retained['body']);
            }
            $fresh = FreshCheckoutPolicy::capture();
            $current = CurrentPolicy::load($rows->current, $candidateId);
            $context = $current['context'];
            $context->requireBuyer($binding);
            $at = CarbonImmutable::now('UTC');
            $selection = CurrentSelection::load($rows->current, $items, $at);
            $basis = TaxExemptions::basis($rows, $basisId, $current, $binding, $selection, $at);
            $subtotal = $selection['selection']['advertised_subtotal_minor'];
            CheckoutException::require($subtotal >= 50 && $subtotal <= 99999999, 'unsupported');
            $public = (string) Str::uuid();
            $created = $at->format('Y-m-d\TH:i:s\Z');
            $body = ['schema_version' => 1, 'purpose' => 'production_checkout_review', 'public_id' => $public, 'created_at' => $created,
                'expires_at' => $at->addSeconds($context->reviewLifetimeSeconds)->format('Y-m-d\TH:i:s\Z'), 'request' => $request, 'request_hash' => $hash,
                'buyer' => $binding, 'buyer_declarations' => $buyerDeclarations, 'candidate' => $current['binding'], 'machine' => $current['machine'],
                'execution_context' => $context->binding(), 'selection' => $selection, 'selection_hash' => CanonicalJson::hash($selection),
                'basis_public_id' => $basis['row']['public_id'], 'basis_hash' => $basis['row']['payload_hash'],
                'amounts' => ['currency' => 'USD', 'subtotal_minor' => $subtotal, 'tax_minor' => 0, 'total_minor' => $subtotal,
                    'authority' => 'owner_delegated_qualified_exemption', 'basis_public_id' => $basisId, 'basis_hash' => $basis['row']['payload_hash']],
                'seller' => $current['machine']['choices']['seller_identity'], 'assent' => [...$current['machine']['choices']['assent'], 'accepted' => false], 'payable' => true];
            $rows->commandFrame()->capDeadline(hrtime(true) + (int) floor(((float) CarbonImmutable::parse($body['expires_at'], 'UTC')->format('U.u')
                - (float) CarbonImmutable::now('UTC')->format('U.u')) * 1_000_000_000));
            $body['review_hash'] = OrderEvidence::reviewHash($body);
            $record = $rows->insert('review', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body), 'basis_id' => $basis['row']['id'],
                'candidate_id' => $candidateId, 'buyer_origin_id' => $binding['origin_id'], 'request_key' => $digest, 'request_hash' => $hash]);
            $retained = OrderEvidence::review($rows, $record);
            $projection = self::reviewProjection($body);
            CheckoutException::require(CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z') < $body['expires_at'], 'expired');
            CurrentSelection::proveBytes($selection);
            OrderEvidence::proveRetained($rows, $retained['raw']);
            TaxExemptions::proveRetained($rows, $basis);
            CurrentSelection::proveCurrent($rows->current, $selection, CarbonImmutable::now('UTC'));
            CurrentPolicy::proveCurrent($rows->current, $current);
            $this->access->proveCurrent($principal, $buyer, $rows->current, $access);
            $fresh->prove();
            CheckoutWriteAdmission::capture($rows, $this->access, $principal, $buyer, $fresh, 'review', $record['public_id']);

            return $projection;
        });
        $this->access->current($principal, $buyer);

        return $result;
    }

    public function accept(ProductionCustomerPrincipal $principal, User $buyer, string $reviewId, string $reviewHash, bool $accepted, string $key): array
    {
        CheckoutException::require($accepted === true && Evidence::hash($reviewHash), 'invalid', 422);
        $digest = Evidence::key($key);

        $result = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $reviewId, $reviewHash, $digest): array {
            $access = $this->access->lock($principal, $buyer, $rows->current);
            $binding = $this->access->durableBinding($principal);
            $request = ['review_id' => $reviewId, 'review_hash' => $reviewHash, 'accepted' => true, 'buyer_origin_id' => $binding['origin_id'], 'key_digest' => $digest];
            $requestHash = CanonicalJson::hash($request);
            $existing = $rows->selector('order', 'buyer_origin_id = ? AND request_key = ?', [$binding['origin_id'], $digest]);
            CheckoutException::require(count($existing) <= 1);
            if ($existing !== []) {
                $order = OrderEvidence::order($rows, $existing[0]);
                self::requireOwner($binding, $order['body']['buyer']);
                CheckoutException::require($order['row']['request_hash'] === $requestHash);
                self::retainedIdentity($order['body']['buyer_declarations']);
                $projection = self::orderProjection($order);
                OrderEvidence::proveRetained($rows, $order['raw']);
                $this->access->proveCurrent($principal, $buyer, $rows->current, $access);

                return $projection;
            }
            $fresh = FreshCheckoutPolicy::capture();
            $review = OrderEvidence::review($rows, $rows->one('review', $reviewId));
            Evidence::same($binding, $review['body']['buyer']);
            CheckoutException::require($review['body']['review_hash'] === $reviewHash);
            // The reviewed identity must match the gate now: a flip between review and assent refuses rather than ordering
            // a contract identity with capture off, or a name-only declaration with capture on.
            CheckoutException::require(ContractIdentityDeclarationV2::isV2($review['body']['buyer_declarations']) === ContractIdentityPolicy::contractIdentityV2());
            self::retainedIdentity($review['body']['buyer_declarations']);
            $rows->commandFrame()->capDeadline(hrtime(true) + (int) floor(((float) CarbonImmutable::parse($review['body']['expires_at'], 'UTC')->format('U.u')
                - (float) CarbonImmutable::now('UTC')->format('U.u')) * 1_000_000_000));
            $current = CurrentPolicy::load($rows->current, $review['row']['candidate_id']);
            Evidence::same($current['binding'], $review['body']['candidate']);
            Evidence::same($current['context']->binding(), $review['body']['execution_context']);
            $at = CarbonImmutable::now('UTC');
            CheckoutException::require($at->format('Y-m-d\TH:i:s\Z') < $review['body']['expires_at'], 'expired');
            $selection = CurrentSelection::load($rows->current, $review['body']['request']['items'], $at);
            Evidence::same($selection, $review['body']['selection']);
            $basis = TaxExemptions::basis($rows, $review['body']['basis_public_id'], $current, $binding, $selection, $at);
            $public = (string) Str::uuid();
            $attemptId = (string) Str::uuid();
            $created = $at->format('Y-m-d\TH:i:s\Z');
            $expires = $at->addSeconds($current['context']->reservationSeconds)->format('Y-m-d\TH:i:s\Z');
            $lines = [];
            foreach ($selection['selection']['lines'] as $line) {
                $lines[] = ['public_id' => (string) Str::uuid(), 'position' => $line['position'], 'selection' => $line,
                    'currency' => 'USD', 'amount_minor' => $line['price_minor'], 'tax_minor' => 0, 'total_minor' => $line['price_minor']];
            }
            $body = ['schema_version' => 1, 'purpose' => 'production_checkout_order', 'public_id' => $public, 'created_at' => $created,
                'review_id' => $reviewId, 'review_hash' => $reviewHash, 'review_payload_hash' => $review['row']['payload_hash'],
                'request' => $request, 'request_hash' => $requestHash, 'buyer' => $binding, 'buyer_declarations' => $review['body']['buyer_declarations'],
                'accepted_at' => $created, 'assent' => [...$current['machine']['choices']['assent'], 'accepted' => true, 'review_hash' => $reviewHash, 'accepted_at' => $created],
                'amounts' => $review['body']['amounts'], 'lines' => $lines, 'attempt_id' => $attemptId, 'expires_at' => $expires, 'payable' => true];
            $record = $rows->insert('order', ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body), 'review_id' => $review['row']['id'],
                'buyer_origin_id' => $binding['origin_id'], 'request_key' => $digest, 'request_hash' => $requestHash,
                'total_minor' => $body['amounts']['total_minor'], 'line_count' => count($lines)]);
            foreach ($lines as $line) {
                $lineBody = ['schema_version' => 1, 'purpose' => 'production_checkout_order_line', 'public_id' => $line['public_id'], 'created_at' => $created,
                    'order_public_id' => $public, 'order_payload_hash' => $record['payload_hash'], 'line' => $line];
                $rows->insert('line', ['public_id' => $line['public_id'], 'created_at' => $created, ...Evidence::seal($lineBody), 'order_id' => $record['id'],
                    'position' => $line['position'], 'track_id' => $line['selection']['track_id'], 'offer_revision_id' => $line['selection']['offer_revision_id'],
                    'license_version_id' => $line['selection']['license_version_id'], 'amount_minor' => $line['amount_minor'], 'line_hash' => CanonicalJson::hash($line)]);
            }
            $attemptBody = ['schema_version' => 1, 'purpose' => 'production_checkout_attempt', 'public_id' => $attemptId, 'created_at' => $created,
                'order_public_id' => $public, 'order_payload_hash' => $record['payload_hash'], 'expires_at' => $expires,
                'inventory' => ['kind' => 'unscoped_nonexclusive', 'selection_hash' => $selection['selection_hash'], 'pending_resources_retained' => true]];
            $rows->insert('attempt', ['public_id' => $attemptId, 'created_at' => $created, ...Evidence::seal($attemptBody), 'order_id' => $record['id'], 'expires_at' => $expires]);
            $order = OrderEvidence::order($rows, $record);
            $projection = self::orderProjection($order);
            CheckoutException::require(CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z') < $review['body']['expires_at'], 'expired');
            CurrentSelection::proveBytes($selection);
            TaxExemptions::proveRetained($rows, $basis);
            OrderEvidence::proveRetained($rows, $order['raw']);
            CurrentSelection::proveCurrent($rows->current, $selection, CarbonImmutable::now('UTC'));
            CurrentPolicy::proveCurrent($rows->current, $current);
            $this->access->proveCurrent($principal, $buyer, $rows->current, $access);
            $fresh->prove();
            CheckoutWriteAdmission::capture($rows, $this->access, $principal, $buyer, $fresh, 'order', $record['public_id']);

            return $projection;
        });
        $this->access->current($principal, $buyer);

        return $result;
    }

    public function read(ProductionCustomerPrincipal $principal, User $buyer, string $orderId): array
    {
        $result = CommandTransaction::run(function (Records $rows) use ($principal, $buyer, $orderId): array {
            $access = $this->access->lock($principal, $buyer, $rows->current);
            $order = OrderEvidence::order($rows, $rows->one('order', $orderId));
            self::requireOwner($this->access->durableBinding($principal), $order['body']['buyer']);
            self::retainedIdentity($order['body']['buyer_declarations']);
            $projection = self::orderProjection($order);
            OrderEvidence::proveRetained($rows, $order['raw']);
            $this->access->proveCurrent($principal, $buyer, $rows->current, $access);

            return $projection;
        });
        $this->access->current($principal, $buyer);

        return $result;
    }

    public static function requireOwner(array $current, array $retained): void
    {
        foreach (['origin_id', 'account_id', 'account_public_id', 'user_id', 'provenance'] as $field) {
            CheckoutException::require(($current[$field] ?? null) === ($retained[$field] ?? null), 'ownership', 403);
        }
    }

    /** The original name-only declaration, unchanged while contract identity capture is off. */
    private static function legacyDeclarations(array $buyerDeclarations): array
    {
        Evidence::keys($buyerDeclarations, ['legalName']);
        CheckoutException::require(is_string($buyerDeclarations['legalName']) && strlen($buyerDeclarations['legalName']) <= 255
            && trim($buyerDeclarations['legalName']) !== '' && mb_check_encoding($buyerDeclarations['legalName'], 'UTF-8')
            && ! preg_match('/[\x00-\x1f\x7f]/u', $buyerDeclarations['legalName']), 'invalid', 422);

        return ['legal_name' => trim($buyerDeclarations['legalName']), 'identity_meaning' => 'buyer_declared_legal_name'];
    }

    /**
     * D-30 step 8: what `initiate` requires of an order's frozen declarations before any provider call or payment intent.
     * A v2 declaration is re-verified as everywhere this checkout opens the evidence, whatever the gate. The declaration's
     * schema must then match the calling checkout's contract identity gate, in both directions (decided 2026-11-08): with the
     * gate on a name-only order refuses, so a legacy order left unpaid cannot be paid once the gate opens; with the gate off a
     * v2 order refuses, because the current paid renderers would print every input field. The one exception: a name-only
     * order already paid while the gate is on is answered by its status (returns true) and never opens or resumes a provider
     * session. Every refusal is the evidence refusal (`changed`). Reconciliation does not call this: money may already be
     * captured there, and it always records it; paid-grant finalization holds the order instead (contractIdentityHeld()).
     */
    public static function requirePayableIdentity(array $declarations, bool $contractIdentityV2, bool $paid = false): bool
    {
        self::retainedIdentity($declarations);
        $v2 = ContractIdentityDeclarationV2::isV2($declarations);
        if ($v2 === $contractIdentityV2) {
            return false;
        }
        CheckoutException::require($paid && ! $v2);

        return true;
    }

    /**
     * D-30 (decided 2026-11-08): true when an order's frozen v2 declaration no longer verifies against checkout's printable set
     * (`ContractPrintableSet::verifyRetained()`), so paid-grant finalization must hold its fulfillment for staff (the payment stays recorded). A name-only
     * declaration is never held here.
     */
    public static function contractIdentityHeld(array $declarations): bool
    {
        try {
            self::retainedIdentity($declarations);
        } catch (CheckoutException) {
            return true;
        }

        return false;
    }

    /**
     * OrderEvidence (hash-frozen) proves the review's declarations equal its request's and the order's equal the review's;
     * a v2 declaration is also re-verified here, where this checkout opens the evidence. Legacy declarations pass untouched.
     */
    private static function retainedIdentity(array $declarations): void
    {
        if (ContractIdentityDeclarationV2::isV2($declarations)) {
            ContractPrintableSet::verifyRetained($declarations, false);
        }
    }

    private static function reviewProjection(array $body): array
    {
        $declarations = $body['buyer_declarations'];
        // D-30: a contract identity review shows the buyer what the order will bind before they assent; name-only reviews are unchanged.
        $contractIdentity = ContractIdentityDeclarationV2::isV2($declarations)
            ? ['contractIdentity' => ['licenseeType' => $declarations['licensee_type'], 'legalName' => $declarations['legal_name'],
                'representativeName' => $declarations['representative_name'],
                'postalAddress' => ContractIdentityDeclarationV2::customerAddress($declarations['postal_address']),
                'statementVersion' => $declarations['legal_name_confirmation']['statement_version']]]
            : [];

        return $contractIdentity + ['reviewId' => $body['public_id'], 'reviewHash' => $body['review_hash'], 'expiresAt' => $body['expires_at'],
            'buyer' => ['legalName' => $body['buyer_declarations']['legal_name']], 'seller' => $body['seller'], 'amounts' => self::amountProjection($body['amounts']), 'assent' => $body['assent'],
            'licenses' => array_map(fn (array $line): array => ['position' => $line['position'], 'trackId' => $line['track_id'], 'offerRevisionId' => $line['offer_revision_id'],
                'trackTitle' => $line['offer_snapshot']['product']['title'], 'artist' => $line['offer_snapshot']['product']['artist'],
                'licenseVersionId' => $line['license_version_id'], 'license' => array_intersect_key($line['offer_snapshot']['license'],
                    array_flip(['name', 'version', 'authored_source', 'source_hash', 'structured_terms', 'features']))], $body['selection']['selection']['lines']),
            'fundsMode' => $body['execution_context']['funds_mode']];
    }

    public static function orderProjection(array $order): array
    {
        return ['orderId' => $order['row']['public_id'], 'createdAt' => $order['row']['created_at'], 'amounts' => self::amountProjection($order['body']['amounts']),
            'assentAccepted' => true, 'fundsMode' => $order['context']->fundsMode, 'paymentStatus' => 'unverified', 'fulfillmentStatus' => 'pending_payment'];
    }

    private static function amountProjection(array $amounts): array
    {
        return array_intersect_key($amounts, array_flip(['currency', 'subtotal_minor', 'tax_minor', 'total_minor']));
    }
}
