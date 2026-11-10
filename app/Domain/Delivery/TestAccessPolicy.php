<?php

namespace App\Domain\Delivery;

use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Throwable;

/** Technical limits for internal synthetic delivery tests; never a license usage budget. */
final class TestAccessPolicy
{
    public const V1 = 'test-owner-delivery-v1';

    public const V2 = 'test-owner-delivery-v2';

    /** Retained: v1 authorizations and their history verify under this literal forever. One POST stream, no ranges. */
    public const CONTRACT = [
        'schema_version' => 1, 'purpose' => 'test_owner_delivery', 'version' => 'test-owner-delivery-v1',
        'scope' => 'activated_order_owner', 'storage' => 'private_local', 'verification' => 'fresh_sha256',
        'token_bytes' => 32, 'authorization_ttl_seconds' => 60, 'new_authorizations_per_order60_seconds' => 3,
        'stream_attempts' => 1, 'ranges' => 'disabled', 'pending_entitlements' => 'preserve',
        'buyer_identity' => 'unverified_guest',
    ];

    /**
     * FP-077 (Sean, 2026-11-09). The token POST answers 303 to a session-bound GET that serves single ranges. A transfer
     * counts once, when distinct served bytes cover the file. The numbers behind the last two mechanisms are operational
     * configuration (`delivery.test_resume_*`), frozen per authorization in its evidence; they are not license terms.
     */
    public const CONTRACT_V2 = [
        'schema_version' => 1, 'purpose' => 'test_owner_delivery', 'version' => 'test-owner-delivery-v2',
        'scope' => 'activated_order_owner', 'storage' => 'private_local', 'verification' => 'fresh_sha256',
        'token_bytes' => 32, 'authorization_ttl_seconds' => 60, 'new_authorizations_per_order60_seconds' => 3,
        'transport' => 'token_post_303_session_bound_get', 'ranges' => 'single_range_strong_if_range',
        'counting' => 'distinct_bytes_cover_file_once', 'resume_window' => 'size_tiered_from_first_byte',
        'incomplete_authorizations' => 'per_grant_rolling_24h_cap', 'pending_entitlements' => 'preserve',
        'buyer_identity' => 'unverified_guest',
    ];

    public const MIB = 1048576;

    /** Documented defaults; each is replaceable through config within the bounds below. */
    public const DEFAULT_RESUME_WINDOWS = ['below_512_mib' => 3600, 'from_512_mib' => 21600, 'from_4_gib' => 86400];

    public const DEFAULT_INCOMPLETE_PER_GRANT_24H = 10;

    public const MIN_RESUME_SECONDS = 60;

    public const MAX_RESUME_SECONDS = 604800;

    /** Blocking remains available after access policy withdrawal, within the same test environment/account. */
    public function environmentAccount(): string
    {
        $account = config('payments.stripe.account_id');
        if (! TestEnvironment::admitsTestCommerce() || config('payments.stripe.mode') !== 'test'
            || ! is_string($account) || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) !== 1) {
            throw new DeliveryException('unavailable');
        }

        return $account;
    }

    public function account(): string
    {
        if (config('delivery.test_access_enabled') !== true) {
            throw new DeliveryException('unavailable');
        }

        return $this->environmentAccount();
    }

    public function current(): array
    {
        $this->account();
        $raw = config('delivery.test_access_policy');
        if (! is_string($raw) || strlen($raw) > 4096) {
            throw new DeliveryException('unavailable');
        }
        try {
            $policy = self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new DeliveryException('unavailable');
        }
        if ($policy['version'] === self::V2) {
            // A v2 policy with unusable operational numbers is unavailable, never silently defaulted.
            $this->resumeWindows();
            $this->incompleteCap();
        }

        return $policy;
    }

    /** Exactly one of the two approved literals. */
    public static function validate(array $policy): array
    {
        foreach ([self::CONTRACT, self::CONTRACT_V2] as $contract) {
            if (CanonicalJson::encode($policy) === CanonicalJson::encode($contract)) {
                return $policy;
            }
        }
        throw new DeliveryException('changed');
    }

    /** The literal an authorization was issued under; its evidence is verified against this, never the current policy. */
    public static function contract(mixed $version): array
    {
        return match ($version) {
            self::V1 => self::CONTRACT,
            self::V2 => self::CONTRACT_V2,
            default => throw new DeliveryException('changed'),
        };
    }

    /** @return array{below_512_mib: int, from_512_mib: int, from_4_gib: int} */
    public function resumeWindows(): array
    {
        $configured = config('delivery.test_resume_window_seconds');
        if (! is_array($configured) || array_keys($configured) !== array_keys(self::DEFAULT_RESUME_WINDOWS)) {
            throw new DeliveryException('unavailable');
        }
        $windows = [];
        foreach ($configured as $tier => $value) {
            $windows[$tier] = self::integer($value, self::MIN_RESUME_SECONDS, self::MAX_RESUME_SECONDS);
        }
        // Larger files never get a shorter resume window than smaller ones.
        if ($windows['from_512_mib'] < $windows['below_512_mib'] || $windows['from_4_gib'] < $windows['from_512_mib']) {
            throw new DeliveryException('unavailable');
        }

        return $windows;
    }

    public function incompleteCap(): int
    {
        return self::integer(config('delivery.test_incomplete_authorizations_per_grant_24h'), 1, 1000);
    }

    /** Pure tier selection: below 512 MiB, from 512 MiB, from 4 GiB. */
    public static function resumeWindowSeconds(int $sizeBytes, array $windows): int
    {
        if ($sizeBytes < 1) {
            throw new DeliveryException('changed');
        }

        return match (true) {
            $sizeBytes >= 4096 * self::MIB => $windows['from_4_gib'],
            $sizeBytes >= 512 * self::MIB => $windows['from_512_mib'],
            default => $windows['below_512_mib'],
        };
    }

    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new DeliveryException('unavailable');
        }

        return $value;
    }
}
