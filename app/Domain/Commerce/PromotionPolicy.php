<?php

namespace App\Domain\Commerce;

use App\Support\Environment\TestEnvironment;
use App\Support\Money\AllocateDiscount;
use App\Support\Money\MinorUnits;
use InvalidArgumentException;
use Throwable;

/** Explicit server-owned test campaigns; never inferred from client amounts. */
final class PromotionPolicy
{
    public static function validCode(mixed $code): bool
    {
        return is_string($code) && (bool) preg_match('/\A[A-Z0-9][A-Z0-9_-]{2,31}\z/D', $code);
    }

    public function current(string $code): array
    {
        if (! TestEnvironment::admitsTestCommerce()) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 503);
        }
        $selected = app(PromotionAdministration::class)->currentPolicy($code);
        try {
            if ($selected === null) {
                foreach ($this->configuredPolicies() as $policy) {
                    if ($policy['code'] === $code) { $selected = $policy; }
                }
            }
        } catch (Throwable) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 503);
        }
        if ($selected === null) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 409);
        }
        try {
            app(PricingPolicy::class)->activeAt($selected, now()->toImmutable());
        } catch (InvalidArgumentException) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 409);
        }

        return $selected;
    }

    /** The compatibility reader stays strict; only authoring permits absent configuration. */
    public function configuredPolicies(bool $allowUnconfigured = false): array
    {
        $json = config('commerce.test_promotions');
        if ($allowUnconfigured && ($json === null || (is_string($json) && trim($json) === ''))) { return []; }
        if (! is_string($json) || strlen($json) > 65536) {
            throw new InvalidArgumentException('Test promotions are unavailable.');
        }
        try {
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || count($decoded) > 50) {
                throw new InvalidArgumentException('Invalid promotion configuration.');
            }
            $policies = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Invalid promotion configuration.', 0, $exception);
        }
        $keys = $codes = [];
        foreach ($policies as $input) {
            $policy = $this->validate($input);
            if (isset($keys[$policy['key']]) || isset($codes[$policy['code']])) {
                throw new InvalidArgumentException('Ambiguous promotion identity.');
            }
            $keys[$policy['key']] = $codes[$policy['code']] = true;
        }

        return $policies;
    }

    public function validate(mixed $policy): array
    {
        PricingPolicy::keys($policy, ['schema_version', 'scope', 'key', 'version', 'code', 'currency',
            'effective_from', 'effective_until', 'eligibility', 'minimum_subtotal_minor', 'discount',
            'stacking', 'allocation', 'max_uses', 'release']);
        if ($policy['schema_version'] !== 1 || $policy['scope'] !== 'test' || $policy['currency'] !== 'USD' ||
            ! is_string($policy['key']) || ! preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $policy['key']) ||
            ! is_int($policy['version']) || $policy['version'] < 1 || $policy['version'] > 2147483647 || ! self::validCode($policy['code']) ||
            $policy['stacking'] !== 'none' || $policy['allocation'] !== AllocateDiscount::ALGORITHM ||
            $policy['release'] !== 'unstarted_at_expiry' || ! is_int($policy['max_uses']) || $policy['max_uses'] < 1 || $policy['max_uses'] > 10000) {
            throw new InvalidArgumentException('Unsupported promotion policy.');
        }
        $dates = app(PricingPolicy::class);
        if ($dates->timestamp($policy['effective_until'])->lessThanOrEqualTo($dates->timestamp($policy['effective_from']))) {
            throw new InvalidArgumentException('Invalid promotion interval.');
        }
        MinorUnits::amount($policy['minimum_subtotal_minor']);
        $eligibility = $policy['eligibility'];
        $all = is_array($eligibility) && ($eligibility['mode'] ?? null) === 'all_non_exclusive';
        PricingPolicy::keys($eligibility, $all ? ['mode'] : ['mode', 'offer_revision_ids']);
        if (! $all) {
            $ids = $eligibility['offer_revision_ids'];
            if ($eligibility['mode'] !== 'offer_revisions' || ! is_array($ids) || ! array_is_list($ids) || count($ids) < 1 || count($ids) > 100) {
                throw new InvalidArgumentException('Invalid promotion eligibility.');
            }
            $last = 0;
            foreach ($ids as $id) {
                if (MinorUnits::amount($id) <= $last) {
                    throw new InvalidArgumentException('Eligibility requires unique, sorted revision identities.');
                }
                $last = $id;
            }
        }
        $discount = $policy['discount'];
        $fixed = is_array($discount) && ($discount['type'] ?? null) === 'fixed';
        PricingPolicy::keys($discount, $fixed ? ['type', 'amount_minor'] : ['type', 'rate_bps', 'max_discount_minor']);
        if ($fixed) {
            if (MinorUnits::amount($discount['amount_minor']) < 1) {
                throw new InvalidArgumentException('A fixed promotion must have a positive amount.');
            }
        } elseif ($discount['type'] !== 'percentage' || MinorUnits::rate($discount['rate_bps']) < 1 || MinorUnits::amount($discount['max_discount_minor']) < 1) {
            throw new InvalidArgumentException('Unsupported percentage promotion.');
        }

        return $policy;
    }

    public function calculate(array $policy, array $lines): array
    {
        $eligible = [];
        foreach ($lines as $line) {
            if ($policy['eligibility']['mode'] === 'all_non_exclusive' || in_array($line['offer_revision_id'], $policy['eligibility']['offer_revision_ids'], true)) {
                $eligible[] = ['offer_revision_id' => $line['offer_revision_id'], 'base_minor' => $line['base_minor']];
            }
        }
        $basis = MinorUnits::sum(array_column($eligible, 'base_minor'));
        $rule = $policy['discount'];
        $rounding = $rule['type'] === 'percentage' ? MinorUnits::fraction($basis, $rule['rate_bps']) : null;
        $discount = $rounding === null ? $rule['amount_minor'] : min($rounding['rounded_minor'], $rule['max_discount_minor']);
        if ($basis < $policy['minimum_subtotal_minor'] || $discount < 1 || $discount > $basis) {
            throw new QuoteException('PROMOTION_NOT_ELIGIBLE', 409);
        }

        return app(AllocateDiscount::class)->handle($eligible, $discount) + ['percentage_rounding' => $rounding];
    }
}
