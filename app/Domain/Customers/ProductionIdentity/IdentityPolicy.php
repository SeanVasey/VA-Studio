<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Customers\CustomerIdentityPolicy;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** A new account-control policy; it never activates legacy fixture identity or legal buyer assertions. */
final class IdentityPolicy
{
    public const VERSION = 'personal-store-customer-identity-v1';

    public const TTL_SECONDS = 600;

    public const MAX_REQUESTS_PER_HOUR = 4;

    public const REHEARSAL = 'synthetic_rehearsal';

    public const PRODUCTION = 'verified_production';

    public function enabled(): bool
    {
        $scope = config('production-customer-identity.provenance');

        return config('production-customer-identity.enabled', false) === true
            && in_array($scope, [self::REHEARSAL, self::PRODUCTION], true)
            && (app()->environment('local', 'testing') ? $scope === self::REHEARSAL : $scope === self::PRODUCTION);
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new IdentityException;
        }
    }

    public function provenance(): string
    {
        $this->requireEnabled();

        return config('production-customer-identity.provenance');
    }

    public function hash(): string
    {
        return self::historicalHash($this->provenance());
    }

    public static function historicalHash(string $provenance): string
    {
        if (! in_array($provenance, [self::REHEARSAL, self::PRODUCTION], true)) {
            throw new IdentityException;
        }

        return CanonicalJson::hash(['version' => self::VERSION, 'provenance' => $provenance,
            'identity' => 'verified_mailbox_account_control', 'account_mode' => 'new_account_first',
            'enrollment_adopts_legacy_account' => false, 'recovery_relinks_owner' => false,
            'verified_legal_buyer_name' => false, 'marketing_consent' => 'not_asserted',
            'proof_ttl_seconds' => self::TTL_SECONDS, 'requests_per_address_hour' => self::MAX_REQUESTS_PER_HOUR]);
    }

    public function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new IdentityException('outer_transaction');
            }
        }
    }

    public static function email(string $email): string
    {
        try {
            return CustomerIdentityPolicy::email($email);
        } catch (\Throwable) {
            throw new IdentityException;
        }
    }

    public static function digest(string $purpose, string $value): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new IdentityException;
        }

        return hash_hmac('sha256', self::VERSION."\0".$purpose."\0".$value, $key);
    }
}
