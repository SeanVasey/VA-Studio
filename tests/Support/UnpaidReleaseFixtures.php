<?php

namespace Tests\Support;

use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;

/** Synthetic transport and existing domain-created orders; no provider writes beyond fixture checkout. */
final class UnpaidReleaseFixtures
{
    public static function configure(): void
    {
        FinalizationFixtures::configure();
        config(['unpaid-release.enabled' => true, 'unpaid-release.policy' => json_encode(UnpaidReleasePolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
    }

    public static function started(object $gateway, bool $promoted = true, bool $paymentIntent = true): array
    {
        $fixture = PaymentFixtures::started($gateway, true, $promoted);
        $gateway->session['status'] = 'expired';
        $gateway->session['payment_status'] = 'unpaid';
        $gateway->session['url'] = null;
        $gateway->session['after_expiration'] = null;
        $gateway->session['recovered_from'] = null;
        $gateway->session['payment_intent'] = $paymentIntent ? PaymentFixtures::PAYMENT : null;
        $gateway->payment['status'] = 'canceled';
        $gateway->payment['amount_received'] = 0;
        $gateway->payment['amount_capturable'] = 0;

        return $fixture + ['admin' => LicenseFixtures::admin(),
            'session' => CheckoutSession::where('checkout_intent_id', $fixture['intent']->id)->sole()];
    }
}
