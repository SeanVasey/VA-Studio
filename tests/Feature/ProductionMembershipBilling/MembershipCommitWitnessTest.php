<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

/**
 * A genuine ordinary afterCommit callback (or committed delegate) can throw after the owned physical commit but
 * before Connection::commit() returns. The durable effect must be reported as a committed receipt refusal, never
 * as a generic pre-commit refusal that a caller would treat as "nothing happened".
 */
class MembershipCommitWitnessTest extends TestCase
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

    public function test_throwing_after_commit_callback_reports_committed_reservation_and_exact_retry_replays_it(): void
    {
        $fixture = $this->reservationFixture();
        $calls = $this->throwAfterCommitOnce();
        $this->refused(fn () => $this->reserveFixture($fixture), 'committed_receipt_refused');
        $this->assertSame(1, $calls->value);
        // The refused response retained the physical effect: one redemption and its reserve event.
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $retained = (array) DB::table(MembershipReservationRecords::TABLE)->sole();
        // The same request key replays the committed reservation instead of writing a second one.
        $replayed = $this->reserveFixture($fixture);
        $this->assertSame($retained['id'], $replayed['redemption_id']);
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(['available' => 2, 'reserved' => 1, 'consumed' => 0, 'expired' => 0], $this->ledgerSnapshot($fixture)['balances']);
    }

    public function test_throwing_committed_delegate_reports_committed_reservation(): void
    {
        $fixture = $this->reservationFixture();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$calls): void {
            if ($calls++ === 0) {
                throw new RuntimeException('Synthetic committed delegate failure.');
            }
        });
        $this->refused(fn () => $this->reserveFixture($fixture), 'committed_receipt_refused');
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_throwing_after_commit_callback_reports_committed_release_and_retry_replays_it(): void
    {
        $fixture = $this->reservationFixture();
        $saved = $this->reserveFixture($fixture);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($saved['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        $release = fn () => (new MembershipReservationOperations)->release($fixture['invoice']['id'], $saved['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $calls = $this->throwAfterCommitOnce();
        // Release also holds the native workflow lock; its cleanup must keep the committed classification.
        $this->refused($release, 'committed_receipt_refused');
        $this->assertSame(1, $calls->value);
        $this->assertSame(3, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $released = $release();
        $this->assertSame('released', $released['status']);
        $this->assertSame(3, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(['available' => 3, 'reserved' => 0, 'consumed' => 0, 'expired' => 0], $released['balances']);
    }

    public function test_throwing_after_commit_callback_reports_committed_award_and_replay_returns_it(): void
    {
        $fixture = $this->reservationFixture(award: false);
        $calls = $this->throwAfterCommitOnce();
        $award = fn () => (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->refused($award, 'committed_receipt_refused');
        $this->assertSame(1, $calls->value);
        $period = (array) DB::table(MembershipPeriodRecords::PERIODS)->sole();
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $replayed = $award();
        $this->assertSame($period['id'], $replayed['period_id']);
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_throwing_callback_before_physical_commit_still_rolls_back_and_stays_a_generic_refusal(): void
    {
        $fixture = $this->reservationFixture();
        $calls = 0;
        // A beginning delegate that throws precedes any write: nothing commits and no committed claim is made.
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$calls): void {
            if ($calls++ === 0) {
                throw new RuntimeException('Synthetic beginning delegate failure.');
            }
        });
        $this->refused(fn () => $this->reserveFixture($fixture), 'operation_refused');
        $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    /** Registers exactly one genuine Laravel afterCommit callback inside the next owned transaction; it throws. */
    private function throwAfterCommitOnce(): object
    {
        $calls = new class
        {
            public int $value = 0;

            public bool $armed = true;
        };
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($calls): void {
            if (! $calls->armed) {
                return;
            }
            $calls->armed = false;
            DB::afterCommit(function () use ($calls): void {
                $calls->value++;
                throw new RuntimeException('Synthetic ordinary afterCommit failure.');
            });
        });

        return $calls;
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
