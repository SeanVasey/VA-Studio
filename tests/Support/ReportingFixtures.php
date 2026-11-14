<?php

namespace Tests\Support;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\CheckoutIntent;

/**
 * Additional synthetic paid test orders for reporting. The shared fixtures pin one provider session and payment id, so a
 * second order in the same test needs its own. Nothing here reaches Stripe.
 */
final class ReportingFixtures
{
    /** A paid, finalized single-line non-exclusive test order with its own synthetic provider ids. */
    public static function single(object $gateway, string $suffix): array
    {
        $gateway->onCreate = fn (array $params): array => CheckoutFixtures::session($params, CheckoutFixtures::SESSION.$suffix);
        $fixture = CheckoutFixtures::prepared(false, false);
        app(HostedCheckout::class)->start($fixture['order']->public_id, InventoryFixtures::OWNER);
        $gateway->onCreate = null;
        $gateway->session['status'] = 'complete';
        $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null;
        $gateway->session['payment_intent'] = PaymentFixtures::PAYMENT.$suffix;
        $gateway->payment = array_replace(PaymentFixtures::payment($gateway->session), ['id' => PaymentFixtures::PAYMENT.$suffix]);
        $confirmed = FinalizationFixtures::confirm($fixture + ['intent' => CheckoutIntent::where('order_id', $fixture['order']->id)->sole()]);

        return ContractFixtures::finalize($confirmed);
    }
}
