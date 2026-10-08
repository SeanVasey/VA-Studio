<?php

namespace App\Domain\Notifications;

use App\Domain\Customers\CustomerAccessPolicy;
use App\Support\Environment\TestEnvironment;
use Illuminate\Support\Facades\DB;

final class TransactionalNotificationPolicy
{
    public const VERSION = 'test-transactional-notification-v1';

    public const TEMPLATE = 'test-order-ready-v1';

    public const LEASE_SECONDS = 30;

    public const MAX_ATTEMPTS = 3;

    public const MAX_CAPTURE_BYTES = 8192;

    public function requireEnabled(): void
    {
        if (! TestEnvironment::admitsTestCommerce() || ! app(CustomerAccessPolicy::class)->enabled()
            || config('transactional-notifications.test_enabled') !== true
            || config('transactional-notifications.transport') !== 'private_capture'
            || config('transactional-notifications.policy_version') !== self::VERSION) {
            throw new NotificationException;
        }
    }

    public static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new NotificationException('outer_transaction');
            }
        }
    }
}
