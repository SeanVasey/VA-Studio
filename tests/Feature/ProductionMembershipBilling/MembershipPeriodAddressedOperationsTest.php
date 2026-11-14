<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Http\Membership\MembershipPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BillingStripeFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardMonotonicClock;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

// Install namespace resolution before fixture setup or any original billing clock call.
class_exists(MembershipAwardMonotonicClock::class);

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

    public function test_unexpected_billing_failure_keeps_unknown_outcome_private_and_rolls_back(): void
    {
        $fixture = $this->reservationFixture();
        $periods = DB::table(MembershipPeriodRecords::PERIODS)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $events = DB::table(MembershipPeriodRecords::EVENTS)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        [$failure, $calls] = $this->withBillingSampleFailure(fn (object $counter) => $this->capturedRefusal(fn () => (new MembershipReservationOperations)->reserveInPeriod($fixture['award']['period_id'], $fixture['selection'],
            $fixture['expected_revision'], 'unexpected-billing', $fixture['buyer']['principal'], $fixture['buyer']['user'])));
        $this->assertSame(1, $calls, 'The real billing-evidence path must reach the failure.');
        $this->assertSame('operation_refused', $failure->reason);
        $response = MembershipPrivacy::refusal($failure, true);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['code' => 'MEMBERSHIP_OUTCOME_UNKNOWN', 'refresh' => true, 'retry' => 'same_request'],
            json_decode($response->getContent(), true, 8, JSON_THROW_ON_ERROR));
        $this->assertSame('1', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($periods, DB::table(MembershipPeriodRecords::PERIODS)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame($events, DB::table(MembershipPeriodRecords::EVENTS)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        // Same public request is still operative after fault removal; no success was fabricated by the failure path.
        $saved = (new MembershipReservationOperations)->reserveInPeriod($fixture['award']['period_id'], $fixture['selection'],
            $fixture['expected_revision'], 'unexpected-billing', $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame($fixture['award']['period_id'], $saved['period_id']);
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
    }

    public function test_known_foreign_period_reads_billing_before_owner_refusal_but_unknown_period_does_not(): void
    {
        $fixture = $this->reservationFixture();
        $other = $this->enrollThroughLocalSmtp('period-ordering@example.test');
        $operations = new MembershipReservationOperations;
        $request = fn (string $period): array => $operations->reserveInPeriod($period, $fixture['selection'],
            $fixture['expected_revision'], 'foreign-ordering', $other['principal'], $other['user']);
        [$failures, $calls] = $this->withBillingSampleFailure(function (object $counter) use ($request, $fixture): array {
            $unknown = $this->capturedRefusal(fn () => $request((string) Str::uuid()));
            $this->assertSame(0, $counter->calls, 'An absent period must not read any invoice evidence.');
            $foreign = $this->capturedRefusal(fn () => $request($fixture['award']['period_id']));

            return [$unknown, $foreign];
        });
        $this->assertSame(1, $calls, 'The retained foreign period currently reaches billing before sameOwner.');
        $this->assertSame('paid_period_absent', $failures[0]->reason);
        $this->assertSame('operation_refused', $failures[1]->reason);
        $unknown = MembershipPrivacy::refusal($failures[0], true);
        $foreign = MembershipPrivacy::refusal($failures[1], true);
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame(['code' => 'MEMBERSHIP_NOT_FOUND'], json_decode($unknown->getContent(), true, 8, JSON_THROW_ON_ERROR));
        $this->assertSame(503, $foreign->getStatusCode());
        $this->assertSame(['code' => 'MEMBERSHIP_OUTCOME_UNKNOWN', 'refresh' => true, 'retry' => 'same_request'],
            json_decode($foreign->getContent(), true, 8, JSON_THROW_ON_ERROR));
        // Removing the fault reaches the real owner comparison; no foreign reservation may be issued.
        $this->refused(fn () => $request($fixture['award']['period_id']), 'invoice_owner_mismatch');
        $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    /** Throw at the original clock sample after native-PDO checks, outside decrypt's tamper catch. */
    private function withBillingSampleFailure(callable $work): array
    {
        $counter = (object) ['calls' => 0];
        $prior = MembershipAwardMonotonicClock::$onBillingReadSample;
        MembershipAwardMonotonicClock::$onBillingReadSample = static function () use ($counter): never {
            $counter->calls++;

            throw new RuntimeException('SYNTHETIC private billing failure: never a public response body');
        };
        try {
            $result = $work($counter);
        } finally {
            MembershipAwardMonotonicClock::$onBillingReadSample = $prior;
        }

        return [$result, $counter->calls];
    }

    private function capturedRefusal(callable $work): MembershipException
    {
        try {
            $work();
            $this->fail('The writer must refuse rather than invent a receipt.');
        } catch (MembershipException $error) {
            return $error;
        }
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
