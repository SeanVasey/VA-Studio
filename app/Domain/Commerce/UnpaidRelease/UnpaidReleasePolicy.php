<?php

namespace App\Domain\Commerce\UnpaidRelease;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class UnpaidReleasePolicy
{
    public const CONTRACT = ['schema_version' => 1, 'purpose' => 'test_unpaid_resource_release',
        'version' => 'test-unpaid-release-v1', 'provider' => 'stripe', 'mode' => 'test',
        'proof' => 'bound_expired_unpaid_session_and_absent_or_canceled_zero_payment',
        'recovery' => 'disabled', 'resources' => 'pending_to_released',
        'late_confirmation' => 'paid_exception_preserve_released', 'provider_writes' => 'forbidden'];

    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (! app()->environment('local', 'testing') || config('payments.stripe.mode') !== 'test'
            || ! is_string($account) || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) !== 1) {
            throw new RuntimeException('Test unpaid release unavailable.');
        }

        return $account;
    }

    public function current(): array
    {
        $this->account();
        try {
            $raw = config('unpaid-release.policy');
            if (config('unpaid-release.enabled') !== true || ! is_string($raw) || strlen($raw) > 4096) {
                throw new RuntimeException;
            }

            return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new RuntimeException('Test unpaid release unavailable.');
        }
    }

    public static function validate(array $policy): array
    {
        if (CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) {
            throw new RuntimeException('Test unpaid release evidence changed.');
        }

        return $policy;
    }

    public static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new RuntimeException('Test unpaid release requires an independent transaction.');
            }
        }
    }
}
