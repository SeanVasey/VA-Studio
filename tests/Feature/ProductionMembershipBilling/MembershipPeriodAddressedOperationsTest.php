<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BillingStripeFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

/** The customer surface addresses a paid period by its listed id; the domain resolves the billing row. */
class MembershipPeriodAddressedOperationsTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipReservationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_period_addressed_reserve_release_and_expire_match_the_locator_operations(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['award_terms' => [
            'expiry' => ['mode' => 'period_end', 'seconds' => 0], 'late_payment' => ['mode' => 'allow', 'seconds' => 0]]],
            honorOverrides: ['duration_seconds' => 120, 'expiry_mode' => 'allow_beyond_credit_expiry']);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(BillingStripeFixtures::PERIOD_END - 60));
        $this->refreshReservationObservation($fixture);
        $period = $fixture['award']['period_id'];
        $operations = new MembershipReservationOperations;
        $reserved = $operations->reserveInPeriod($period, $fixture['selection'], $fixture['expected_revision'], 'period-key',
            $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame($period, $reserved['period_id']);
        // The locator operation and the period-addressed one replay the same retained reservation.
        $this->assertSame($reserved, $this->reserveFixture($fixture, 'period-key'));
        $this->assertSame($reserved, $operations->reserveInPeriod($period, $fixture['selection'], $fixture['expected_revision'], 'period-key',
            $fixture['buyer']['principal'], $fixture['buyer']['user']));
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(BillingStripeFixtures::PERIOD_END));
        $this->refreshReservationObservation($fixture);
        $expired = $operations->expireInPeriod($period, $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame($expired, $operations->expire($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($reserved['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        $released = $operations->releaseInPeriod($period, $reserved['redemption_id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame('released', $released['status']);
        $this->assertSame($released, $operations->release($fixture['invoice']['id'], $reserved['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']));
        $this->assertSame(4, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_unknown_or_malformed_period_refuses_without_writes(): void
    {
        $fixture = $this->reservationFixture();
        $operations = new MembershipReservationOperations;
        $this->refused(fn () => $operations->reserveInPeriod((string) Str::uuid(), $fixture['selection'], $fixture['expected_revision'],
            'unknown-period', $fixture['buyer']['principal'], $fixture['buyer']['user']), 'paid_period_absent');
        $this->refused(fn () => $operations->expireInPeriod('not-a-period', $fixture['buyer']['principal'], $fixture['buyer']['user']), 'invalid_value');
        $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_another_customers_period_id_is_refused_without_writes(): void
    {
        $fixture = $this->reservationFixture();
        $other = $this->enrollThroughLocalSmtp('period-other@example.test');
        $this->refused(fn () => (new MembershipReservationOperations)->reserveInPeriod($fixture['award']['period_id'], $fixture['selection'],
            $fixture['expected_revision'], 'foreign-period', $other['principal'], $other['user']), 'invoice_owner_mismatch');
        $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_redemption_of_another_period_is_refused(): void
    {
        $first = $this->reservationFixture();
        $saved = $this->reserveFixture($first);
        $second = $this->reservationNextPeriod($first);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($saved['honor_deadline'], 'UTC')->addDays(40));
        $this->refreshReservationObservation($second);
        $this->refused(fn () => (new MembershipReservationOperations)->releaseInPeriod($second['award']['period_id'], $saved['redemption_id'],
            $second['buyer']['principal'], $second['buyer']['user']), 'reservation_absent');
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->where('period_id', $first['award']['period_id'])->count());
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected an explicit membership refusal.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
