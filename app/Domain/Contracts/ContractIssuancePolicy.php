<?php

namespace App\Domain\Contracts;

use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ContractIssuancePolicy
{
    public const V1_CONTRACT = [
        'schema_version' => 1, 'purpose' => 'test_contract_issuance', 'version' => 'test-contract-issuance-v1',
        'profile' => 'test-buyer-pdf-v1', 'originals' => 'preserve_first_committed',
        'missing_original' => 'restore_only', 'buyer_identity' => 'unverified_guest', 'entitlements' => 'pending',
        'lease_seconds' => 300, 'max_attempts' => 5, 'retry_seconds' => 60,
    ];

    public const V2_CONTRACT = [
        'schema_version' => 1, 'purpose' => 'test_contract_issuance', 'version' => 'test-contract-issuance-v2',
        'profile' => 'test-buyer-pdf-v2', 'originals' => 'preserve_first_committed',
        'missing_original' => 'restore_only', 'buyer_identity' => 'unverified_guest', 'entitlements' => 'pending',
        'lease_seconds' => 300, 'max_attempts' => 5, 'retry_seconds' => 60,
    ];

    public const CONTRACT = self::V2_CONTRACT;

    private const RETAINED_POLICIES = [
        'test-contract-issuance-v1' => self::V1_CONTRACT,
        'test-contract-issuance-v2' => self::V2_CONTRACT,
    ];

    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (config('contracts.test_issuance_enabled') !== true || ! TestEnvironment::admitsTestCommerce()
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
        if (! is_string($raw) || strlen($raw) > 4096) {
            throw new ContractIssuanceException('unavailable');
        }
        try {
            $policy = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            self::validate($policy);
            if (CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) {
                throw new ContractIssuanceException('unavailable');
            }

            return $policy;
        } catch (Throwable) {
            throw new ContractIssuanceException('unavailable');
        }
    }

    public static function validate(array $policy): array
    {
        $version = $policy['version'] ?? null;
        if (! is_string($version) || ! isset(self::RETAINED_POLICIES[$version])
            || CanonicalJson::encode($policy) !== CanonicalJson::encode(self::RETAINED_POLICIES[$version])) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $policy;
    }

    public static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new ContractIssuanceException('unavailable');
            }
        }
    }
}
