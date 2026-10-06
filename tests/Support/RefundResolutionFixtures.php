<?php

namespace Tests\Support;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\RefundResolution\RefundResolutionPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

final class RefundResolutionFixtures
{
    public static function configure(): void
    {
        FinalizationFixtures::configure();
        config(['refund-resolution.enabled' => true,
            'refund-resolution.policy' => json_encode(RefundResolutionPolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
    }

    public static function exception(object $payments, bool $promoted = true): array
    {
        $f = PaymentFixtures::started($payments, true, $promoted);
        Carbon::setTestNow($f['order']->attempt()->sole()->expires_at->addSecond());
        $f = FinalizationFixtures::confirm($f);
        if (app(FinalizeTestPayment::class)->handle($f['payment']->id) !== 'paid_exception') {
            throw new \LogicException('Expected an unfulfilled synthetic exception.');
        }
        Queue::fake();

        return $f + ['record' => OrderFinalization::where('order_id', $f['order']->id)->sole(), 'admin' => LicenseFixtures::admin()];
    }

    public static function fullRefund(array $source): array
    {
        foreach (['charge_before', 'charge_after'] as $key) {
            $source[$key]['amount_refunded'] = $source[$key]['amount'];
            $source[$key]['refunded'] = true;
        }
        $source['refunds']['data'] = [PaymentFinancialFixtures::item('refund', 'succeeded', $source['payment']['amount'])];

        return $source;
    }
}
