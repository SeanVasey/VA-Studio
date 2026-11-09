<?php

namespace Tests\Unit;

use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\TestAccessPolicy;
use App\Domain\Delivery\TestDeliveryCoverageLedger;
use App\Domain\Delivery\TransferTestDelivery;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FP-077 v2 terms without multi-GB files: the size -> resume-window tiers, the window bound at each tier's edge, the
 * start window, completion and response caps, and the distinct-interval arithmetic that decides counting.
 */
class TestDeliveryResumeTermsTest extends TestCase
{
    private const MIB = 1048576;

    public static function tiers(): array
    {
        return [
            '1 byte' => [1, 3600], '512 MiB - 1' => [512 * self::MIB - 1, 3600], '512 MiB' => [512 * self::MIB, 21600],
            '4 GiB - 1' => [4096 * self::MIB - 1, 21600], '4 GiB' => [4096 * self::MIB, 86400], '64 GiB' => [65536 * self::MIB, 86400],
        ];
    }

    #[DataProvider('tiers')]
    public function test_file_size_selects_the_documented_default_tier(int $size, int $seconds): void
    {
        $this->assertSame($seconds, TestAccessPolicy::resumeWindowSeconds($size, TestAccessPolicy::DEFAULT_RESUME_WINDOWS));
    }

    #[DataProvider('tiers')]
    public function test_each_tier_admits_resume_until_the_last_second_of_its_window_and_never_after(int $size, int $seconds): void
    {
        $window = TestAccessPolicy::resumeWindowSeconds($size, TestAccessPolicy::DEFAULT_RESUME_WINDOWS);
        $issued = CarbonImmutable::parse('2026-11-09T10:00:00Z');
        $started = $issued->addSeconds(30);
        [$authorization, $control, $redemption] = $this->models($issued, $started);
        $terms = ['resume_window_seconds' => $window, 'session_binding_hash' => str_repeat('a', 64)];
        $coverage = ['responses' => 1, 'intervals' => [[0, 0]], 'covered' => 1, 'completed' => false];
        $transfer = app(TransferTestDelivery::class);
        $transfer->eligible($authorization, $control, $started->addSeconds($seconds - 1), $redemption, $terms, $coverage);
        $this->assertSame('expired', $this->reason(fn () => $transfer->eligible($authorization, $control, $started->addSeconds($seconds), $redemption, $terms, $coverage)));
        // Long after the 60-second start window, resume is still admitted inside the window; an unstarted one is not.
        $transfer->eligible($authorization, $control, $issued->addSeconds(61), $redemption, $terms, $coverage);
        $this->assertSame('expired', $this->reason(fn () => $transfer->eligible($authorization, $control, $issued->addSeconds(60), null, $terms, $coverage)));
        $transfer->eligible($authorization, $control, $issued->addSeconds(59), null, $terms, $coverage);
    }

    public function test_completed_or_exhausted_transfers_admit_nothing_more(): void
    {
        $issued = CarbonImmutable::parse('2026-11-09T10:00:00Z');
        [$authorization, $control, $redemption] = $this->models($issued, $issued);
        $terms = ['resume_window_seconds' => 3600, 'session_binding_hash' => str_repeat('a', 64)];
        $transfer = app(TransferTestDelivery::class);
        $this->assertSame('redeemed', $this->reason(fn () => $transfer->eligible($authorization, $control, $issued->addSecond(), $redemption, $terms,
            ['responses' => 1, 'intervals' => [[0, 9]], 'covered' => 10, 'completed' => true])));
        $this->assertSame('resume_exhausted', $this->reason(fn () => $transfer->eligible($authorization, $control, $issued->addSecond(), $redemption, $terms,
            ['responses' => TestDeliveryCoverageLedger::MAX_RESPONSES, 'intervals' => [[0, 0]], 'covered' => 1, 'completed' => false])));
        $transfer->eligible($authorization, $control, $issued->addSecond(), $redemption, $terms,
            ['responses' => TestDeliveryCoverageLedger::MAX_RESPONSES - 1, 'intervals' => [[0, 0]], 'covered' => 1, 'completed' => false]);
        $this->assertSame(TestDeliveryCoverageLedger::HARD_MAX_SEQUENCE, 2 * TestDeliveryCoverageLedger::MAX_RESPONSES);
    }

    public function test_configured_windows_are_bounded_and_ordered(): void
    {
        $policy = app(TestAccessPolicy::class);
        foreach ([
            ['below_512_mib' => 60, 'from_512_mib' => 60, 'from_4_gib' => 604800],
            ['below_512_mib' => '120', 'from_512_mib' => '7200', 'from_4_gib' => '7200'],
        ] as $valid) {
            config(['delivery.test_resume_window_seconds' => $valid]);
            $this->assertSame(array_map('intval', $valid), $policy->resumeWindows());
        }
        foreach ([
            ['below_512_mib' => 59, 'from_512_mib' => 3600, 'from_4_gib' => 3600],
            ['below_512_mib' => 3600, 'from_512_mib' => 3599, 'from_4_gib' => 86400],
            ['below_512_mib' => 3600, 'from_512_mib' => 21600, 'from_4_gib' => 604801],
            ['below_512_mib' => '3600 ', 'from_512_mib' => 21600, 'from_4_gib' => 86400],
            ['below_512_mib' => 3600.0, 'from_512_mib' => 21600, 'from_4_gib' => 86400],
            ['from_512_mib' => 21600, 'below_512_mib' => 3600, 'from_4_gib' => 86400],
            ['below_512_mib' => 3600, 'from_512_mib' => 21600],
        ] as $invalid) {
            config(['delivery.test_resume_window_seconds' => $invalid]);
            $this->assertSame('unavailable', $this->reason(fn () => $policy->resumeWindows()));
        }
        foreach ([0, 1001, '10x', null] as $invalid) {
            config(['delivery.test_incomplete_authorizations_per_grant_24h' => $invalid]);
            $this->assertSame('unavailable', $this->reason(fn () => $policy->incompleteCap()));
        }
        config(['delivery.test_incomplete_authorizations_per_grant_24h' => '1000']);
        $this->assertSame(1000, $policy->incompleteCap());
    }

    public function test_policy_validation_admits_exactly_the_two_literals(): void
    {
        $this->assertSame(TestAccessPolicy::CONTRACT, TestAccessPolicy::validate(TestAccessPolicy::CONTRACT));
        $this->assertSame(TestAccessPolicy::CONTRACT_V2, TestAccessPolicy::validate(TestAccessPolicy::CONTRACT_V2));
        $this->assertSame(TestAccessPolicy::CONTRACT, TestAccessPolicy::contract('test-owner-delivery-v1'));
        $this->assertSame(TestAccessPolicy::CONTRACT_V2, TestAccessPolicy::contract('test-owner-delivery-v2'));
        $hybrid = ['ranges' => 'single_range_strong_if_range'] + TestAccessPolicy::CONTRACT;
        $longer = ['authorization_ttl_seconds' => 120] + TestAccessPolicy::CONTRACT_V2;
        foreach ([$hybrid, $longer, ['version' => 'test-owner-delivery-v3'] + TestAccessPolicy::CONTRACT_V2] as $policy) {
            $this->assertSame('changed', $this->reason(fn () => TestAccessPolicy::validate($policy)));
        }
        $this->assertSame('changed', $this->reason(fn () => TestAccessPolicy::contract('test-owner-delivery-v3')));
    }

    public static function merges(): array
    {
        return [
            'first' => [[], [5, 9], [[5, 9]]],
            'disjoint before' => [[[10, 19]], [0, 4], [[0, 4], [10, 19]]],
            'adjacent joins' => [[[10, 19]], [5, 9], [[5, 19]]],
            'overlap re-serves nothing' => [[[0, 99]], [10, 20], [[0, 99]]],
            'bridges two' => [[[0, 4], [10, 14], [20, 24]], [3, 21], [[0, 24]]],
            'fills a gap exactly' => [[[0, 4], [10, 14]], [5, 9], [[0, 14]]],
            'between' => [[[0, 4], [20, 24]], [10, 12], [[0, 4], [10, 12], [20, 24]]],
            'after' => [[[0, 4]], [6, 6], [[0, 4], [6, 6]]],
        ];
    }

    #[DataProvider('merges')]
    public function test_intervals_merge_into_sorted_disjoint_distinct_coverage(array $intervals, array $range, array $expected): void
    {
        $merged = TestDeliveryCoverageLedger::merge($intervals, $range[0], $range[1]);
        $this->assertSame($expected, $merged);
        $this->assertSame($merged, TestDeliveryCoverageLedger::decode(TestDeliveryCoverageLedger::encode($merged), 1000));
        $this->assertSame(array_sum(array_map(fn ($i) => $i[1] - $i[0] + 1, $expected)), TestDeliveryCoverageLedger::covered($merged));
    }

    public function test_tampered_interval_encodings_are_refused(): void
    {
        foreach (['', '[]', '[[0,4],[5,9]]', '[[5,9],[0,1]]', '[[0,10]]', '[[-1,3]]', '[[3,2]]', '[[0,1,2]]', '[[0, 4]]', '[["0",4]]', '{"a":[0,1]}'] as $encoded) {
            $this->assertSame('changed', $this->reason(fn () => TestDeliveryCoverageLedger::decode($encoded, 10)), $encoded);
        }
    }

    /** @return array{0: TestDeliveryAuthorization, 1: TestDeliveryControl, 2: TestDeliveryRedemption} */
    private function models(CarbonImmutable $issued, CarbonImmutable $started): array
    {
        return [
            new TestDeliveryAuthorization(['issued_at' => $issued, 'expires_at' => $issued->addSeconds(60)]),
            new TestDeliveryControl(['updated_at' => $issued->subMinute()]),
            new TestDeliveryRedemption(['redeemed_at' => $started]),
        ];
    }

    private function reason(callable $operation): string
    {
        try {
            $operation();
        } catch (DeliveryException $error) {
            return $error->reason;
        }
        $this->fail('Expected a delivery refusal.');
    }
}
