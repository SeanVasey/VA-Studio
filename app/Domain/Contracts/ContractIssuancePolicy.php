<?php

namespace App\Domain\Contracts;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ContractIssuancePolicy
{
    public const CONTRACT = [
        'schema_version' => 1, 'purpose' => 'test_contract_issuance', 'version' => 'test-contract-issuance-v1',
        'profile' => 'test-buyer-pdf-v1', 'originals' => 'preserve_first_committed',
        'missing_original' => 'restore_only', 'buyer_identity' => 'unverified_guest', 'entitlements' => 'pending',
        'lease_seconds' => 300, 'max_attempts' => 5, 'retry_seconds' => 60,
    ];

    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (config('contracts.test_issuance_enabled') !== true || ! app()->environment('local', 'testing')
            || config('payments.stripe.mode') !== 'test' || ! is_string($account)
            || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account)) {
            throw new ContractIssuanceException('unavailable');
        }

        return $account;
    }

    public function current(): array
    {
        $this->account();
        $raw = config('contracts.test_issuance_policy');
        if (! is_string($raw) || strlen($raw) > 4096) { throw new ContractIssuanceException('unavailable'); }
        try {
            $policy = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            return self::validate($policy);
        } catch (Throwable) { throw new ContractIssuanceException('unavailable'); }
    }

    public static function validate(array $policy): array
    {
        if (CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $policy;
    }

    public static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) { throw new ContractIssuanceException('unavailable'); }
        }
    }
}
