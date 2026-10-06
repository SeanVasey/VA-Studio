<?php

namespace App\Domain\Commerce\RefundResolution;

use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;
use App\Support\CanonicalJson;
use RuntimeException;
use Throwable;

/** A technical test resource disposition, never a production refund or rights policy. */
final class RefundResolutionPolicy
{
    public const CONTRACT = ['schema_version' => 1, 'purpose' => 'test_refunded_exception_release',
        'version' => 'test-refunded-exception-release-v1', 'provider' => 'stripe', 'mode' => 'test',
        'eligible' => 'paid_exception_without_grants', 'proof' => 'complete_succeeded_refunds_equal_original_charge_no_disputes',
        'resources' => 'pending_to_released', 'original_payment' => 'preserved', 'rights' => 'unchanged',
        'provider_writes' => 'forbidden', 'observation_max_age_seconds' => 60, 'request_max_age_seconds' => 120];

    public function account(): string
    {
        return app(UnpaidReleasePolicy::class)->account();
    }

    public function current(): array
    {
        $this->account();
        try {
            $raw = config('refund-resolution.policy');
            if (config('refund-resolution.enabled') !== true || ! is_string($raw) || strlen($raw) > 4096) {
                throw new RuntimeException;
            }

            return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new RuntimeException('Test refund resolution unavailable.');
        }
    }

    public static function validate(array $policy): array
    {
        if (CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) {
            throw new RuntimeException('Test refund resolution evidence changed.');
        }

        return $policy;
    }
}
