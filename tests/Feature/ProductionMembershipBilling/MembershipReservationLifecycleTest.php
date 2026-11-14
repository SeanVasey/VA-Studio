<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

class MembershipReservationLifecycleTest extends TestCase
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

    public function test_real_sources_reserve_once_and_exact_retry_and_award_replay_preserve_the_retained_identity(): void
    {
        $fixture = $this->reservationFixture(creditAmount: 2);
        $saved = $this->reserveFixture($fixture);
        $this->assertSame($saved, $this->reserveFixture($fixture));
        $this->assertSame(2, $saved['credit_amount']);
        $this->assertSame($fixture['award'], $this->replayAward($fixture));
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(['available' => 1, 'reserved' => 2, 'consumed' => 0, 'expired' => 0], $this->ledgerSnapshot($fixture)['balances']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_last_credit_refusal_keeps_exactly_the_first_redemption_and_reserve_event(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['credit_allowance' => 1]);
        $saved = $this->reserveFixture($fixture, 'first-synthetic-key');
        $this->refused(fn () => $this->reserveFixture($fixture, 'second-synthetic-key'), 'insufficient_credit');
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => 0, 'reserved' => 1, 'consumed' => 0, 'expired' => 0], $audit['balances']);
        $this->assertSame([$saved['redemption_id']], array_keys($audit['reservations']));
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_reused_key_cannot_adopt_another_eligible_selection(): void
    {
        $fixture = $this->reservationFixture(selectionCount: 2);
        $saved = $this->reserveFixture($fixture);
        $other = [...$fixture, 'selection' => (string) $fixture['catalogs'][1]['offer']->id,
            'expected_revision' => (int) $fixture['catalogs'][1]['revision']->id];
        $this->refused(fn () => $this->reserveFixture($other), 'conflicting_request');
        $this->assertSame($saved, $this->reserveFixture($fixture));
        $this->assertCount(1, $this->ledgerSnapshot($fixture)['reservations']);
    }

    public function test_reused_key_cannot_adopt_another_actually_awarded_paid_period(): void
    {
        $first = $this->reservationFixture();
        $saved = $this->reserveFixture($first);
        $second = $this->reservationNextPeriod($first);
        $this->assertNotSame($first['award']['period_id'], $second['award']['period_id']);
        $this->refused(fn () => $this->reserveFixture($second), 'conflicting_request');
        $this->assertSame($saved['redemption_id'], DB::table(MembershipReservationRecords::TABLE)->sole()->id);
        $next = $this->reserveFixture($second, 'next-synthetic-period-key');
        $this->assertSame($second['award']['period_id'], $next['period_id']);
        $this->assertCount(1, $this->ledgerSnapshot($second)['reservations']);
    }

    public function test_release_requires_honor_expiry_then_is_idempotent_and_preserves_award_replay(): void
    {
        $fixture = $this->reservationFixture();
        $saved = $this->reserveFixture($fixture);
        $operations = new MembershipReservationOperations;
        $release = fn () => $operations->release($fixture['invoice']['id'], $saved['redemption_id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($saved['honor_deadline'], 'UTC')->subSecond());
        $this->refreshReservationObservation($fixture);
        $this->refused($release, 'honor_not_expired');
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
        CarbonImmutable::setTestNow(CarbonImmutable::parse($saved['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        $released = $release();
        $this->assertSame($released, $release());
        $this->assertSame(['available' => 3, 'reserved' => 0, 'consumed' => 0, 'expired' => 0], $this->ledgerSnapshot($fixture)['balances']);
        $this->assertSame(3, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame($fixture['award'], $this->replayAward($fixture));
        $this->refused(fn () => $this->reserveFixture($fixture), 'reservation_terminal');
    }

    public function test_available_expiry_preserves_reserved_credit_and_a_later_release_never_restores_it(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['award_terms' => [
            'expiry' => ['mode' => 'period_end', 'seconds' => 0], 'late_payment' => ['mode' => 'allow', 'seconds' => 0]]],
            honorOverrides: ['duration_seconds' => 120, 'expiry_mode' => 'allow_beyond_credit_expiry']);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(BillingStripeFixtures::PERIOD_END - 60));
        $this->refreshReservationObservation($fixture);
        $saved = $this->reserveFixture($fixture);
        $operations = new MembershipReservationOperations;
        $this->refused(fn () => $operations->expire($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']), 'credit_not_expired');
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(BillingStripeFixtures::PERIOD_END));
        $this->refreshReservationObservation($fixture);
        $expired = $operations->expire($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame($expired, $operations->expire($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']));
        $this->assertSame(['available' => 0, 'reserved' => 1, 'consumed' => 0, 'expired' => 2], $this->ledgerSnapshot($fixture)['balances']);
        $this->refused(fn () => $this->reserveFixture($fixture, 'new-after-expiry'), 'credit_expired');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($saved['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        $released = $operations->release($fixture['invoice']['id'], $saved['redemption_id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 0, 'expired' => 3], $released['balances']);
        $this->assertSame($released, $operations->release($fixture['invoice']['id'], $saved['redemption_id'], $fixture['buyer']['principal'], $fixture['buyer']['user']));
        $this->assertSame($fixture['award'], $this->replayAward($fixture));
        $this->assertSame(4, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    private function replayAward(array $fixture): array
    {
        return (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected explicit lifecycle refusal.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
