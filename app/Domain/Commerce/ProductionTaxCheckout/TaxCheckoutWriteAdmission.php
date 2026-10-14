<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandFrame;
use App\Domain\Commerce\ProductionCheckout\CheckoutCommitAdmission;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutRawPlans;
use App\Domain\Commerce\ProductionCheckout\CurrentSelection;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/** Server-authenticated NEW Tax255 write, with phase-specific raw proof in its original physical frame. */
final readonly class TaxCheckoutWriteAdmission implements CheckoutCommitAdmission
{
    private function __construct(
        private CheckoutCommandFrame $frame,
        private FreshTaxCheckoutPolicy $fresh,
        private TaxCheckoutRawPlans $family,
        private ?CheckoutRawPlans $current,
    ) {}

    /** NEW order/request requires the exact current principal, approved candidate, catalog and original bounds. */
    public static function current(Records $rows, FreshTaxCheckoutPolicy $fresh, ProductionCustomerAccess $access,
        ProductionCustomerPrincipal $principal, User $buyer, string $kind, string $publicId): self
    {
        CheckoutException::require(in_array($kind, ['order', 'request'], true), 'write_frame');
        $frame = $rows->commandFrame();
        $frame->proveAnchor();
        $fresh->prove();
        $identity = $access->lock($principal, $buyer, $rows->current);
        $tax = TaxCheckoutRecords::writer($rows);
        $record = $tax->one($kind, $publicId);
        $order = TaxCheckoutEvidence::order($tax, $kind === 'order' ? $record : $tax->byId('order', $record['order_id']));
        ProductionCheckout::requireOwner($access->durableBinding($principal), $order['body']['buyer']);
        $candidate = TaxCandidate::load($rows->current, $order['row']['candidate_id']);
        Evidence::same($order['body']['candidate'], $candidate['binding']);
        Evidence::same($order['context']->binding(), $candidate['context']->binding());
        $selection = CurrentSelection::load($rows->current, $order['body']['request']['items'], CarbonImmutable::now('UTC'));
        Evidence::same($order['body']['selection'], $selection);
        CurrentSelection::proveBytes($selection);
        CurrentSelection::proveCurrent($rows->current, $selection, CarbonImmutable::now('UTC'));
        TaxCandidate::proveCurrent($rows->current, $candidate);
        $access->proveCurrent($principal, $buyer, $rows->current, $identity);

        $until = [$order['body']['expires_at']];
        $requests = $tax->selector('request', 'order_id = ?', [$order['row']['id']]);
        CheckoutException::require(count($requests) <= 1, 'write_source_changed');
        if ($kind === 'request') {
            CheckoutException::require(count($requests) === 1 && $requests[0]['public_id'] === $publicId, 'write_source_changed');
            $intent = TaxCheckoutEvidence::intent($tax, $order, $requests[0]);
            $until[] = $intent['body']['retry_before'];
            $until[] = CarbonImmutable::parse($order['body']['expires_at'], 'UTC')
                ->subSeconds($order['context']->providerLifetimeSeconds)->format('Y-m-d\TH:i:s\Z');
        }
        foreach ($selection['graph']['offers'] as $offer) {
            if ((string) $offer['is_active'] !== '1') {
                continue;
            }
            $revision = self::one($selection['graph']['revisions'], $offer['current_revision_id']);
            $license = self::one($selection['graph']['licenses'], $revision['license_version_id']);
            if ($license['effective_until'] !== null) {
                $until[] = $license['effective_until'];
            }
        }
        self::cap($frame, $until);
        $current = new CheckoutRawPlans($frame);
        $current->actor($buyer);
        $current->identity($identity);
        $current->selection($selection);
        $current->history($candidate['raw']);
        $family = self::family($frame, $tax, $order, $requests);

        return self::seal($frame, $fresh, $family, $current);
    }

    /**
     * NEW binding/reviewed evidence belongs to its authenticated historical order, not today's customer/catalog.
     * The service prelocks this locator's historical ownership before opening its family rows.
     */
    public static function retained(Records $rows, FreshTaxCheckoutPolicy $fresh, ProductionTaxPaidOrderLocatorV2 $locator,
        string $kind, string $publicId): self
    {
        CheckoutException::require(in_array($kind, ['binding', 'reviewed'], true), 'write_frame');
        $frame = $rows->commandFrame();
        $frame->proveAnchor();
        $fresh->prove();
        $historical = (new ProductionCustomerAccess)->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
        $tax = TaxCheckoutRecords::writer($rows);
        $order = TaxCheckoutEvidence::order($tax, $tax->one('order', $locator->orderId));
        CheckoutException::require($order['row']['payload_hash'] === $locator->orderHash, 'write_source_changed');
        Evidence::same($locator->historicalBuyerBinding(), $order['body']['buyer']);
        $requests = $tax->selector('request', 'order_id = ?', [$order['row']['id']]);
        CheckoutException::require(count($requests) === 1, 'write_source_changed');
        $intent = TaxCheckoutEvidence::intent($tax, $order, $requests[0]);
        CheckoutException::require(($intent[$kind]['public_id'] ?? null) === $publicId, 'write_source_changed');
        $family = self::family($frame, $tax, $order, $requests);
        // Stable original ownership only. Password/access_version/active/current profile are not historical authority.
        $family->plan('users', 'id = ?', [$historical['user_id']], 2, [['id' => $historical['user_id']]], ['id']);
        $account = $historical['account'];
        $family->plan('customer_accounts', 'id = ?', [$account['id']], 2,
            [['id' => $account['id'], 'public_id' => $account['public_id'], 'user_id' => $account['user_id']]], ['id', 'public_id', 'user_id']);
        $origin = $historical['origin'];
        $family->plan('production_identity_origins', 'id = ?', [$origin['id']], 2, [$origin]);
        $prefix = $historical['verification_prefix'];
        CheckoutException::require($prefix !== [] && count($prefix) <= 128, 'write_source_changed');
        $last = $prefix[array_key_last($prefix)];
        $family->plan('production_identity_verifications', 'origin_id = ? AND sequence <= ?', [$origin['id'], $last['sequence']], 129, $prefix);
        foreach ($historical['challenges'] as $challenge) {
            $family->plan('production_identity_challenges', 'id = ?', [$challenge['id']], 2, [$challenge]);
        }

        // No historical order/license/retry/provider/challenge expiry cap: observedAt already fixes its outcome.
        return self::seal($frame, $fresh, $family, null);
    }

    private static function family(CheckoutCommandFrame $frame, TaxCheckoutRecords $tax, array $order, array $requests): TaxCheckoutRawPlans
    {
        $family = new TaxCheckoutRawPlans($frame);
        $row = $order['row'];
        $family->plan(TaxCheckoutSchema::TABLES['order'], 'public_id = ?', [$row['public_id']], 2, [$row]);
        $family->plan(TaxCheckoutSchema::TABLES['order'], 'buyer_origin_id = ? AND request_key = ?',
            [$row['buyer_origin_id'], $row['request_key']], 2, [$row]);
        $family->plan(TaxCheckoutSchema::TABLES['request'], 'order_id = ?', [$row['id']], 2, $requests);
        foreach ($requests as $request) {
            $intent = TaxCheckoutEvidence::intent($tax, $order, $request);
            $family->plan(TaxCheckoutSchema::TABLES['request'], 'id = ?', [$request['id']], 2, [$request]);
            $family->plan(TaxCheckoutSchema::TABLES['binding'], 'request_id = ?', [$request['id']], 2, $intent['raw']['binding']);
            $family->plan(TaxCheckoutSchema::TABLES['reviewed'], 'request_id = ?', [$request['id']], 2, $intent['raw']['reviewed']);
        }

        return $family;
    }

    private static function cap(CheckoutCommandFrame $frame, array $until): void
    {
        $expires = min(array_map(fn (string $value): float => (float) CarbonImmutable::parse($value, 'UTC')->format('U.u'), $until));
        $remaining = $expires - (float) CarbonImmutable::now('UTC')->format('U.u');
        CheckoutException::require($remaining > 0, 'expired');
        $frame->capDeadline(hrtime(true) + (int) floor($remaining * 1_000_000_000));
    }

    private static function one(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        throw new CheckoutException('write_source_changed');
    }

    private static function seal(CheckoutCommandFrame $frame, FreshTaxCheckoutPolicy $fresh, TaxCheckoutRawPlans $family,
        ?CheckoutRawPlans $current): self
    {
        $admission = new self($frame, $fresh, $family, $current);
        $admission->proveCurrent();
        $admission->proveFresh();
        $frame->register($admission);

        return $admission;
    }

    public function belongsTo(CheckoutCommandFrame $frame): bool
    {
        return $frame === $this->frame;
    }

    public function proveCurrent(): void
    {
        $this->frame->prove(1);
        $this->current?->proveCurrent();
        $this->family->proveCurrent();
        $this->frame->prove(1);
    }

    public function proveFresh(): void
    {
        $this->fresh->prove();
    }

    public function __serialize(): never
    {
        throw new LogicException('Tax checkout write admissions are internal server capabilities.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax checkout write admissions cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_tax_checkout_write_admission'];
    }
}
