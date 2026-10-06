<?php

namespace Tests\Support;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Customers\CustomerPurchaseClaims;
use App\Domain\Notifications\TestTransactionalNotifications;
use App\Domain\Notifications\TransactionalNotificationPolicy;

/** Synthetic private notice evidence only; no provider/email or production record is used. */
final class TransactionalNotificationFixtures
{
    public static function configure(): void
    {
        CustomerFixtures::configure();
        config(['transactional-notifications.test_enabled' => true,
            'transactional-notifications.transport' => 'private_capture',
            'transactional-notifications.policy_version' => TransactionalNotificationPolicy::VERSION]);
    }

    public static function ready(bool $enqueue = true, string $suffix = 'ONE'): array
    {
        self::configure();
        $customer = CustomerFixtures::account(['email' => 'notification-'.strtolower($suffix).'@example.invalid']);
        $paid = CustomerFixtures::ready($customer['user'], 'NOTIFICATION'.$suffix);
        $result = $customer + $paid;
        if ($enqueue) {
            $result['notice'] = app(TestTransactionalNotifications::class)->enqueueOrderReady($paid['order']->public_id, $customer['principal']);
        }

        return $result;
    }

    public static function claimed(bool $enqueue = true): array
    {
        self::configure();
        config(['customer.test_purchase_claims_enabled' => true]);
        $customer = CustomerFixtures::account(['email' => 'notification-owner@example.invalid']);
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        app()->instance(StripeCheckoutGateway::class, $gateway);
        app()->instance(StripePaymentGateway::class, $gateway);
        app()->instance(ContractRenderer::class, ContractFixtures::renderer());
        $paid = DeliveryFixtures::ready($gateway);
        $claims = app(CustomerPurchaseClaims::class);
        $marker = $claims->bind($claims->stage($paid['order']->public_id, $paid['order']->owner_key), $customer['principal']);
        $claim = $claims->complete($marker, $paid['order']->public_id, $customer['principal'], $customer['user']);
        $result = $customer + $paid + compact('claim');
        if ($enqueue) {
            $result['notice'] = app(TestTransactionalNotifications::class)->enqueueOrderReady($paid['order']->public_id, $customer['principal']);
        }

        return $result;
    }
}
