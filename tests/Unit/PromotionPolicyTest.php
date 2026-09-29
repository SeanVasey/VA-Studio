<?php

namespace Tests\Unit;

use App\Domain\Commerce\PromotionPolicy;
use App\Domain\Commerce\QuoteException;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidPolicies(): array
    {
        return [
            [['schema_version' => '1']], [['scope' => 'live']], [['currency' => 'EUR']], [['key' => ' Bad']],
            [['version' => 0]], [['version' => 1.0]], [['code' => 'synthetic']], [['code' => 'A']],
            [['stacking' => 'all']], [['allocation' => 'float']], [['release' => 'always_at_expiry']],
            [['max_uses' => 0]], [['max_uses' => 10001]], [['max_uses' => '3']], [['minimum_subtotal_minor' => -1]],
            [['effective_from' => '2026-02-30T00:00:00Z']], [['effective_until' => '2020-01-01T00:00:00Z']],
            [['eligibility' => ['mode' => 'unknown']]], [['eligibility' => ['mode' => 'all_non_exclusive', 'offer_revision_ids' => [1]]]],
            [['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => []]]],
            [['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [1, 1]]]],
            [['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [2, 1]]]],
            [['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => ['1']]]],
            [['discount' => ['type' => 'fixed', 'amount_minor' => 0]]], [['discount' => ['type' => 'fixed', 'amount_minor' => 1.0]]],
            [['discount' => ['type' => 'percentage', 'rate_bps' => 10001, 'max_discount_minor' => 1]]],
            [['discount' => ['type' => 'percentage', 'rate_bps' => 1, 'max_discount_minor' => 0]]],
            [['discount' => ['type' => 'fixed', 'amount_minor' => 1, 'rate_bps' => 1]]], [['unknown' => true]],
        ];
    }

    #[DataProvider('invalidPolicies')]
    public function test_rejects_ambiguous_and_unsupported_policy_values(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PromotionPolicy::class)->validate(PromotionFixtures::policy($changes));
    }

    public function test_percentage_caps_exact_eligibility_and_rounding(): void
    {
        $policy = PromotionFixtures::policy(['discount' => ['type' => 'percentage', 'rate_bps' => 5000, 'max_discount_minor' => 10],
            'eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [2, 3]]]);
        $rules = app(PromotionPolicy::class);
        $rules->validate($policy);
        $lines = [['offer_revision_id' => 1, 'base_minor' => 1000], ['offer_revision_id' => 2, 'base_minor' => 1], ['offer_revision_id' => 3, 'base_minor' => 2]];
        $trace = $rules->calculate($policy, $lines);
        $this->assertSame(2, $trace['discount_minor']);
        $this->assertSame(3, $trace['eligible_subtotal_minor']);
        $this->assertSame(5000, $trace['percentage_rounding']['remainder']);
        $this->assertSame([2, 3], array_column($trace['allocations'], 'offer_revision_id'));
        $policy['discount']['max_discount_minor'] = 1;
        $this->assertSame(1, $rules->calculate($policy, $lines)['discount_minor']);
    }

    public function test_no_eligible_lines_threshold_excess_and_rounded_zero_fail(): void
    {
        $lines = [['offer_revision_id' => 1, 'base_minor' => 10]];
        foreach ([['discount' => ['type' => 'fixed', 'amount_minor' => 11]],
            ['minimum_subtotal_minor' => 11, 'discount' => ['type' => 'fixed', 'amount_minor' => 1]],
            ['discount' => ['type' => 'percentage', 'rate_bps' => 1, 'max_discount_minor' => 10]],
            ['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [2]]]] as $changes) {
            try { app(PromotionPolicy::class)->calculate(PromotionFixtures::policy($changes), $lines); $this->fail('Ineligible promotion accepted.'); }
            catch (QuoteException $e) { $this->assertSame('PROMOTION_NOT_ELIGIBLE', $e->errorCode); }
        }
    }

    public function test_configuration_requires_unambiguous_test_scope_and_half_open_dates(): void
    {
        $policy = PromotionFixtures::policy();
        $policy['effective_from'] = '2026-09-14T00:00:00Z';
        $policy['effective_until'] = '2026-09-14T00:01:00Z';
        PromotionFixtures::configure([$policy]);
        $this->travelTo(new \Carbon\CarbonImmutable($policy['effective_from']));
        $this->assertSame($policy, app(PromotionPolicy::class)->current('SYNTHETIC'));
        $this->travelTo(new \Carbon\CarbonImmutable($policy['effective_until']));
        try { app(PromotionPolicy::class)->current('SYNTHETIC'); $this->fail('Expired policy accepted.'); }
        catch (QuoteException $e) { $this->assertSame(409, $e->status); }
        foreach ([null, '', '{}', '{', 'null', str_repeat(' ', 65537), json_encode([$policy, $policy])] as $json) {
            config(['commerce.test_promotions' => $json]);
            try { app(PromotionPolicy::class)->current('SYNTHETIC'); $this->fail('Invalid configuration accepted.'); }
            catch (QuoteException $e) { $this->assertSame(503, $e->status); }
        }
        PromotionFixtures::configure([$policy]);
        $this->app->instance('env', 'production');
        $this->expectException(QuoteException::class);
        app(PromotionPolicy::class)->current('SYNTHETIC');
    }
}
