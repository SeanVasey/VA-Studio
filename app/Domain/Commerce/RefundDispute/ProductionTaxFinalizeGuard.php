<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Payments\PaymentFinancialEvidence;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Grants\PaidTax\PaidTaxException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * BL-42 follow-up (P2-1): finalization of a paid production tax order re-reads the provider's refund and dispute state before it
 * creates any grant origin, so an order refunded or disputed before the buyer finalizes it is not granted.
 *
 * Two steps, matching the lifecycle's lock order (provider I/O never runs inside a transaction):
 *  1. {@see self::read()} runs before the locked finalization transaction. It takes the retained account, PaymentIntent and gross
 *     total from the order's plain `reviewed` row, then makes the same authenticated own-account GET the staff observation uses
 *     (StripeFinancialInspectionGateway, validated by PaymentFinancialEvidence::capture) bound to exactly those three facts.
 *  2. {@see self::assertBound()} runs inside the locked transaction, before the first write, and proves that the authenticated
 *     paid lines carry the same account, PaymentIntent and gross total that were read, so a read of another payment can never
 *     clear this order.
 *
 * The refusal vocabulary is the existing one: HTTP 409 `refund_dispute_hold` for a refunded (committed refunds cover the gross),
 * open-disputed or lost-disputed order, and `refund_dispute_attention` (the ledger's outcome name) when the read fails, is
 * incomplete or returns foreign or inconsistent data. The order stays paid; no grant origin is created and nothing is written, so a
 * later attempt after staff resolve it finalizes normally. The guard is admitted exactly where the lifecycle itself is
 * (default-off flag, `local`/`testing`, own Stripe test account); elsewhere it does nothing and finalization is unchanged.
 */
/** Not final so a test can bind a guard whose pre-lock read differs from the locked rows and prove the locked frame refuses it. */
class ProductionTaxFinalizeGuard
{
    public const HOLD = 'refund_dispute_hold';

    public const ATTENTION = 'refund_dispute_attention';

    /** True when the lifecycle is admitted for the production tax chain on this host. Never throws. */
    public function admitted(): bool
    {
        try {
            app(RefundDisputePolicy::class)->productionAccount();

            return true;
        } catch (RefundDisputeException) {
            return false;
        }
    }

    /**
     * Outside any transaction. Null when the guard is not admitted or the order has no reviewed payment row yet (the locked read
     * then refuses an unpaid order exactly as before).
     *
     * @return ?array{account: string, payment: string, gross: int}
     */
    public function read(string $orderPublicId): ?array
    {
        if (! $this->admitted()) {
            return null;
        }
        PaidTaxException::require(DB::transactionLevel() === 0 && ! DB::getPdo()->inTransaction(), 503);
        try {
            $account = app(RefundDisputePolicy::class)->productionAccount();
            $order = DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $orderPublicId)->first(['id']);
            if ($order === null) {
                return null;
            }
            $reviewed = DB::table(TaxCheckoutSchema::TABLES['reviewed'])->where('order_id', $order->id)->limit(2)->get();
            if ($reviewed->count() === 0) {
                return null;
            }
            $row = $reviewed->count() === 1 ? $reviewed->first() : null;
            $facts = $row === null ? null : ['account' => (string) $row->account_id, 'payment' => (string) $row->provider_payment_id,
                'gross' => (int) $row->amount_total_minor];
            // The reviewed row's currency is USD by the table's insert trigger and the row is immutable, so it is not re-checked here.
            PaidTaxException::require($facts !== null && $row->funds_mode === 'test' && hash_equals($account, $facts['account'])
                && $facts['gross'] >= 1 && $facts['gross'] <= 99999999
                && preg_match('/\Api_[A-Za-z0-9]{1,120}\z/D', $facts['payment']) === 1, 409, self::ATTENTION);
        } catch (PaidTaxException $error) {
            throw $error;
        } catch (Throwable) {
            throw new PaidTaxException(409, self::ATTENTION);
        }
        $this->decide($facts);

        return $facts;
    }

    /**
     * Inside the locked transaction, before the first write. Fails closed when the guard is admitted but nothing was read, or the
     * paid lines do not carry the facts that were read.
     *
     * @param  array<int, array>  $lines  authenticated paid lines
     */
    public function assertBound(?array $read, array $lines): void
    {
        if (! $this->admitted()) {
            return;
        }
        PaidTaxException::require($read !== null && $lines !== [], 409, self::ATTENTION);
        foreach ($lines as $line) {
            PaidTaxException::require(($line['provider_account'] ?? null) === $read['account'] && ($line['provider_payment_id'] ?? null) === $read['payment']
                && ($line['tax']['order_total_minor'] ?? null) === $read['gross'] && ($line['funds_mode'] ?? null) === 'test'
                && ($line['producer'] ?? null) === TaxCheckoutPolicy::PRODUCER, 409, self::ATTENTION);
        }
    }

    private function decide(array $facts): void
    {
        try {
            $financial = app(PaymentFinancialEvidence::class)->capture(
                app(StripeFinancialInspectionGateway::class)->financialState($facts['payment']),
                app(ProductionTaxOrders::class)->expectation(['provider_account' => $facts['account'], 'paid_minor' => $facts['gross'],
                    'provider_payment_id' => $facts['payment']]));
        } catch (Throwable) {
            throw new PaidTaxException(409, self::ATTENTION);
        }
        $evaluation = RefundDisputeState::evaluate($facts['gross'], 'USD', [], app(RefundDisputeLedger::class)->normalize($financial));
        PaidTaxException::require($evaluation['valid'], 409, self::ATTENTION);
        // The charge's own refunded aggregate cross-checks the listed refunds: a list that lags the aggregate must fail toward hold,
        // so the larger of the two is the amount treated as refunded or in flight.
        $refunded = max($evaluation['succeeded_minor'] + $evaluation['pending_minor'], (int) ($financial['source']['charge_after']['amount_refunded'] ?? 0));
        PaidTaxException::require($facts['gross'] > $refunded
            && ! in_array($evaluation['dispute_state'], ['open', 'lost'], true), 409, self::HOLD);
    }
}
