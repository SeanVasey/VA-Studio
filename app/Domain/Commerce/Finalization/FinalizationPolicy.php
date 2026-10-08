<?php

namespace App\Domain\Commerce\Finalization;

use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Throwable;

/** An explicitly selected nonbinding test contract, never a production rights policy. */
final class FinalizationPolicy
{
    public const CONTRACT = ['schema_version' => 1, 'purpose' => 'test_order_finalization',
        'version' => 'test-order-finalization-v1', 'eligibility' => 'confirmation_observed_before_attempt_expiry',
        'exception_resources' => 'retain_pending', 'grant_effective_time' => 'finalization_time',
        'buyer_identity' => 'unverified_guest'];

    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (config('payments.stripe.finalization_enabled') !== true || ! TestEnvironment::admitsTestCommerce()
            || config('payments.stripe.mode') !== 'test' || ! is_string($account)
            || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account) !== 1) {
            throw new FinalizationException('unavailable');
        }

        return $account;
    }

    public function current(): array
    {
        $this->account();
        try {
            $raw = config('payments.stripe.finalization_policy');
            if (! is_string($raw) || strlen($raw) > 4096) { throw new FinalizationException('unavailable'); }

            return self::validate(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) { throw new FinalizationException('unavailable'); }
    }

    /** Historical validation does not consult current flags or configuration. */
    public static function validate(array $policy): array
    {
        if (CanonicalJson::encode($policy) !== CanonicalJson::encode(self::CONTRACT)) {
            throw new FinalizationException('changed');
        }

        return $policy;
    }
}
