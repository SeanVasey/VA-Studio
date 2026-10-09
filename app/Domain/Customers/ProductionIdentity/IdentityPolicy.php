<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Customers\CustomerIdentityPolicy;
use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

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
            // Rehearsal is local/testing only; staging is a test installation and never admits production identity.
            && (app()->environment('local', 'testing') ? $scope === self::REHEARSAL
                : $scope === self::PRODUCTION && ! TestEnvironment::refusesProductionOnly());
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

    /**
     * Key candidates for verifying stored identity digests: the current `app.key` first, then each distinct
     * `app.previous_keys` entry of at least 32 bytes. A key in neither list never verifies, so retiring a
     * previous key refuses every digest it wrote. Stored digests are immutable evidence and are never re-keyed.
     *
     * @return non-empty-list<string>
     */
    public static function keys(): array
    {
        $current = config('app.key');
        if (! is_string($current) || $current === '') {
            throw new IdentityException;
        }
        $previous = config('app.previous_keys', []);
        $keys = [$current];
        foreach (is_array($previous) ? $previous : [] as $key) {
            if (is_string($key) && strlen($key) >= 32 && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Every new digest is written with the current key only. */
    public static function digest(string $purpose, #[SensitiveParameter] string $value): string
    {
        return self::mac($purpose, $value, self::keys()[0]);
    }

    /** Constant-time verification of a stored digest under every configured key; no candidate short-circuits the others. */
    public static function matches(string $purpose, #[SensitiveParameter] string $value, string $expected): bool
    {
        $matched = false;
        foreach (self::keys() as $key) {
            $matched = hash_equals(self::mac($purpose, $value, $key), $expected) || $matched;
        }

        return $matched;
    }

    /**
     * Candidate digests, current key first, for indexed lookups of a stored digest column (one exact `= ?`
     * lookup per candidate keeps single-key lock shapes). Callers must refuse when candidates select more than one row.
     *
     * @return non-empty-list<string>
     */
    public static function digests(string $purpose, #[SensitiveParameter] string $value): array
    {
        return array_map(fn (string $key): string => self::mac($purpose, $value, $key), self::keys());
    }

    private static function mac(string $purpose, #[SensitiveParameter] string $value, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', self::VERSION."\0".$purpose."\0".$value, $key);
    }
}
