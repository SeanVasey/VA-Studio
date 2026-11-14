<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingProjection;
use App\Domain\Memberships\Billing\BillingSettlement;
use App\Domain\Memberships\Production\MembershipAwardTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\TestCase;

class BillingPaidTimeTest extends TestCase
{
    public function test_projection_retains_only_the_documented_payment_timestamp(): void
    {
        $payment = F::graph()['payments'][0];
        $payment['status_transitions']['unrelated_detail'] = 'not retained';
        $projected = BillingProjection::project('invoice_payment', $payment);
        $this->assertSame(['paid_at' => F::PERIOD_START], $projected['status_transitions'] ?? null);
    }

    public function test_a_late_retrieval_keeps_the_actual_on_time_payment_fact(): void
    {
        $verdict = BillingSettlement::evaluate(F::snapshots(F::graph(), F::PERIOD_END + 86400), F::expectation());
        $this->assertSame('settled', $verdict->outcome);
        $this->assertSame('membership-paid-invoice-v2', $verdict->facts['paid_facts_version'] ?? null);
        $this->assertSame('2026-10-07 00:00:00', $verdict->facts['paid_at'] ?? null);
        $terms = MembershipAwardTerms::from(['expiry' => ['mode' => 'none', 'seconds' => 0], 'late_payment' => ['mode' => 'before_period_end', 'seconds' => 0]]);
        $this->assertTrue($terms->admitsPayment($verdict->facts['line_period_end'], $verdict->facts['paid_at']));
    }

    public static function invalidTimes(): array
    {
        return ['missing' => [[]], 'null' => [['paid_at' => null]], 'string' => [['paid_at' => (string) F::PERIOD_START]],
            'zero' => [['paid_at' => 0]], 'negative' => [['paid_at' => -1]], 'out_of_range' => [['paid_at' => 253402300800]],
            'fractional' => [['paid_at' => F::PERIOD_START + 0.5]], 'after_retrieval' => [['paid_at' => F::PERIOD_START + 3601]]];
    }

    #[DataProvider('invalidTimes')]
    public function test_missing_or_invalid_payment_time_cannot_settle(array $transitions): void
    {
        $graph = F::graph(['payment' => ['status_transitions' => $transitions]]);
        $verdict = BillingSettlement::evaluate(F::snapshots($graph), F::expectation());
        $this->assertSame(['refused', 'payment_time'], [$verdict->outcome, $verdict->reason]);
    }

    public function test_an_explicit_prepayment_is_not_relabelled_as_the_service_start(): void
    {
        $graph = F::graph(['payment' => ['status_transitions' => ['paid_at' => F::PERIOD_START - 86400]]]);
        $verdict = BillingSettlement::evaluate(F::snapshots($graph), F::expectation());
        $this->assertSame('settled', $verdict->outcome);
        $this->assertSame('2026-10-06 00:00:00', $verdict->facts['paid_at'] ?? null);
    }
}
