<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Sealed commit admission for a NEW hosted-provider intent (Codex P1 r4208264406).
 *
 * Retains the exact raw buyer identity, catalog selection graph, capability/source history, the
 * order graph (review, basis, authority, order, lines, attempt) and the new intent with its empty
 * session/observation/payment sets. An offer deactivated or a capability closed by a committing
 * listener changes a fixed plan, so the whole original physical transaction rolls back and
 * initiate() never reaches provider I/O.
 */
final class CheckoutIntentAdmission implements CheckoutCommitAdmission
{
    private function __construct(private readonly CheckoutRawPlans $plans, private readonly FreshCheckoutPolicy $fresh) {}

    /** Last step of prepare(create) after a NEW intent insert; every callback-capable proof has already completed. */
    public static function capture(Records $rows, array $identity, User $buyer, FreshCheckoutPolicy $fresh,
        array $order, array $intent, array $current, array $selection, array $basis): self
    {
        $frame = $rows->commandFrame();
        $frame->proveAnchor();
        CheckoutException::require($intent['session'] === null && $intent['observations'] === [] && $intent['payment'] === null
            && $intent['row']['order_id'] === $order['row']['id'] && $basis['row']['id'] === $order['raw']['basis']['id'], 'write_frame');

        // Authenticated temporal bounds are converted once against the ORIGINAL command budget, never at commit.
        $until = [$order['attempt']['expires_at'], $basis['body']['request']['attestation']['effective_until']];
        $until[] = Evidence::open($basis['authority'], 'production_checkout_exemption_authority')['policy']['effective_until'];
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
        $expires = min(array_map(fn (string $value): float => (float) CarbonImmutable::parse($value, 'UTC')->format('U.u'), $until));
        $remaining = $expires - (float) CarbonImmutable::now('UTC')->format('U.u');
        CheckoutException::require($remaining > 0, 'expired');
        $frame->capDeadline(hrtime(true) + (int) floor($remaining * 1_000_000_000));

        $plans = new CheckoutRawPlans($frame);
        $plans->actor($buyer);
        $plans->identity($identity);
        $plans->selection($selection);
        $plans->history($current['raw']);
        foreach (['authority', 'basis', 'review', 'order', 'attempt'] as $kind) {
            $plans->row($kind, $order['raw'][$kind]);
        }
        $orderId = $order['row']['id'];
        $plans->selector('line', 'order_id = ?', [$orderId], 11, $order['lines']);
        $plans->selector('attempt', 'order_id = ?', [$orderId], 2, [$order['attempt']]);
        $plans->row('intent', $intent['row']);
        $plans->selector('intent', 'order_id = ?', [$orderId], 2, [$intent['row']]);
        $plans->selector('session', 'intent_id = ?', [$intent['row']['id']], 2, []);
        $plans->selector('observation', 'intent_id = ?', [$intent['row']['id']], 129, []);
        $plans->selector('payment', 'intent_id = ?', [$intent['row']['id']], 2, []);

        $admission = new self($plans, $fresh);
        // No resolver, decryptor, clock factory, renderer or identity verifier follows this original raw proof.
        $admission->proveCurrent();
        $admission->proveFresh();
        $frame->register($admission);

        return $admission;
    }

    public function belongsTo(CheckoutCommandFrame $frame): bool
    {
        return $this->plans->belongsTo($frame);
    }

    public function proveCurrent(): void
    {
        $this->plans->proveCurrent();
    }

    public function proveFresh(): void
    {
        $this->fresh->prove();
    }

    private static function one(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        CheckoutException::require(false);
    }

    public function __serialize(): never
    {
        throw new \LogicException('Checkout intent admission is an internal server capability.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_checkout_new_intent_admission'];
    }
}
