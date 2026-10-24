<?php

namespace Tests\Support;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\RefundDispute\RefundDisputeLedger;
use App\Domain\Contracts\ContractRenderer;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic A5 provider facts and policy values. Nothing here reaches Stripe, configures real credentials or
 * selects Sean's policy: each test chooses the supported values it exercises.
 */
final class RefundDisputeFixtures
{
    public const POLICY = ['refund_authority' => 'authoritative_provider_operator_review', 'partial_refunds' => 'exact_line_allocation',
        'dispute_access' => 'temporarily_block_while_reconciling', 'exclusive_reopening' => 'qualified_operator_review'];

    public const TABLES = ['refund_dispute_work', 'refund_dispute_observations', 'refund_dispute_objects', 'refund_dispute_statuses',
        'refund_line_allocations', 'refund_dispute_effects', 'refund_dispute_holds'];

    public static function configure(array $overrides = []): void
    {
        DeliveryFixtures::configure();
        config(['refund-dispute.enabled' => true, 'refund-dispute.policy' => array_replace(self::POLICY, $overrides)]);
    }

    /** Binds the synthetic checkout/payment gateway and a programmable financial gateway; returns both. */
    public static function bind(Application $app): array
    {
        $payments = PaymentFixtures::gateway();
        $financial = PaymentFinancialFixtures::gateway($payments);
        $app->instance(StripeCheckoutGateway::class, $payments);
        $app->instance(StripePaymentGateway::class, $payments);
        $app->instance(StripeFinancialInspectionGateway::class, $financial);
        $app->instance(ContractRenderer::class, ContractFixtures::renderer());

        return [$payments, $financial];
    }

    /**
     * Makes the financial gateway report these complete refund and dispute lists. Items are [id, amount, status]
     * with optional currency. The Charge aggregate counts every non-failed refund, capped at the charge.
     */
    public static function provider(object $financial, array $refunds = [], array $disputes = [], array $chargeOverrides = []): void
    {
        $financial->onInspect = function (array $source) use ($refunds, $disputes, $chargeOverrides): array {
            $item = fn (string $kind, array $spec): array => ['id' => $spec[0], 'object' => $kind, 'payment_intent' => PaymentFixtures::PAYMENT,
                'charge' => 'ch_SYNTHETICCHARGE', 'currency' => $spec[3] ?? 'usd', 'amount' => $spec[1], 'status' => $spec[2],
                'metadata' => ['private' => 'private-financial@example.test']]
                + ($kind === 'dispute' ? ['livemode' => false, 'evidence' => ['customer_email_address' => 'private-financial@example.test']] : []);
            $amount = $source['charge_before']['amount'];
            $refunded = min($amount, array_sum(array_map(fn (array $spec): int => in_array($spec[2], ['pending', 'requires_action', 'succeeded'], true) ? $spec[1] : 0, $refunds)));
            $charge = array_replace($source['charge_before'], ['amount_refunded' => $refunds === [] ? 0 : $refunded,
                'refunded' => $refunds !== [] && $refunded === $amount, 'disputed' => $disputes !== []], $chargeOverrides);
            $source['charge_before'] = $source['charge_after'] = $charge;
            $source['refunds']['data'] = array_map(fn (array $spec): array => $item('refund', $spec), $refunds);
            $source['disputes']['data'] = array_map(fn (array $spec): array => $item('dispute', $spec), $disputes);

            return $source;
        };
    }

    public static function sequence(string $orderPublicId, User $actor): int
    {
        return app(RefundDisputeLedger::class)->review($orderPublicId, $actor)['sequence'];
    }

    public static function observe(string $orderPublicId, User $actor, ?string $requestId = null): array
    {
        return app(RefundDisputeLedger::class)->observe($orderPublicId, $actor, $requestId ?? (string) Str::uuid(), self::sequence($orderPublicId, $actor));
    }

    public static function counts(): array
    {
        return array_combine(self::TABLES, array_map(fn (string $table): int => DB::table($table)->count(), self::TABLES));
    }

    /** Original business evidence that A5 must never change. */
    public static function originals(): array
    {
        $rows = DeliveryFixtures::retained();
        foreach (['test_delivery_controls', 'exclusive_activations', 'rights_scopes', 'inventory_reservations', 'promotion_uses', 'license_versions'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }
}
