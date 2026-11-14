<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingDatabaseClock;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

class MembershipFirstAwardTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->awardSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owned_award_commits_one_period_and_one_event_and_replays_the_original_result(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $first = $this->award($fixture);
        $this->assertSame(3, $first['allowance']);
        $this->assertSame('synthetic_rehearsal', $first['provenance']);
        $this->assertSame(gmdate('Y-m-d H:i:s', F::PERIOD_END + 86400), $first['credit_expires_at']);
        $period = (array) DB::table(MembershipPeriodRecords::PERIODS)->sole();
        $event = (array) DB::table(MembershipPeriodRecords::EVENTS)->sole();
        $this->assertSame([1, 'award', 3, 0, 3, str_repeat('0', 64)],
            [(int) $event['sequence'], $event['kind'], (int) $event['amount'], (int) $event['before_available'], (int) $event['after_available'], $event['prior_seal']]);
        $this->assertSame($period['created_at'], $event['created_at']);
        $this->assertSame($first, $this->award($fixture));
        $this->assertSame($period, (array) DB::table(MembershipPeriodRecords::PERIODS)->sole());
        $this->assertSame($event, (array) DB::table(MembershipPeriodRecords::EVENTS)->sole());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_fresh_identical_retrieval_replays_original_award_but_changed_paid_facts_refuse(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $first = $this->award($fixture);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(1));
        $this->awardObservation($fixture);
        $this->assertSame($first, $this->award($fixture));
        $this->awardObservation($fixture, graph: ['payment' => ['status_transitions' => ['paid_at' => F::PERIOD_START + 1, 'canceled_at' => null]]]);
        $this->refused(fn () => $this->award($fixture), 'conflicting_invoice');
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_provider_paid_time_controls_late_payment_even_when_retrieval_is_after_period_end(): void
    {
        $fixture = $this->awardFixture(['award_terms' => ['expiry' => ['mode' => 'period_end', 'seconds' => 0],
            'late_payment' => ['mode' => 'before_period_end', 'seconds' => 0]]], observe: false);
        $this->awardTestCapabilities();
        // Service period already ended, but the provider-paid time was inside that period.
        // Keep the provider payment in the elapsed past of the actual database clock.
        $end = BillingDatabaseClock::sample(DB::connection()->getPdo(), DB::getDriverName())->seconds() - 3600;
        $this->awardObservation($fixture, graph: ['line' => ['period' => ['start' => $end - 86400, 'end' => $end]],
            'payment' => ['status_transitions' => ['paid_at' => $end - 1, 'canceled_at' => null]]]);
        $result = $this->award($fixture);
        $this->assertSame(gmdate('Y-m-d H:i:s', $end), $result['credit_expires_at']);
        $this->assertSame(3, $result['allowance']);
    }

    public function test_payment_at_exclusive_late_boundary_records_no_award(): void
    {
        $fixture = $this->awardFixture(['award_terms' => ['expiry' => ['mode' => 'none', 'seconds' => 0],
            'late_payment' => ['mode' => 'before_period_end', 'seconds' => 0]]], observe: false);
        $this->awardTestCapabilities();
        // Keep the provider payment in the elapsed past of the actual database clock.
        $end = BillingDatabaseClock::sample(DB::connection()->getPdo(), DB::getDriverName())->seconds() - 3600;
        $this->awardObservation($fixture, graph: ['line' => ['period' => ['start' => $end - 86400, 'end' => $end]],
            'payment' => ['status_transitions' => ['paid_at' => $end, 'canceled_at' => null]]]);
        $this->refused(fn () => $this->award($fixture), 'late_payment');
        $this->assertNoAward();
    }

    public function test_missing_complete_runtime_capabilities_and_nested_transactions_cannot_award(): void
    {
        $fixture = $this->awardFixture();
        $this->refused(fn () => $this->award($fixture), 'source_capability_absent');
        $this->awardTestCapabilities();
        DB::transaction(function () use ($fixture): void {
            $this->refused(fn () => $this->award($fixture), 'transaction_forbidden');
            $this->assertSame(1, DB::transactionLevel());
        });
        $this->assertNoAward();
    }

    public function test_committing_callback_policy_withdrawal_rolls_back_both_effects(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$calls): void {
            $calls++;
            config(['production-memberships.enabled' => false]);
        });
        $this->refused(fn () => $this->award($fixture), 'changed_policy');
        $this->assertSame(1, $calls);
        $this->assertNoAward();
    }

    public function test_after_commit_owner_change_refuses_success_but_does_not_claim_rollback(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($fixture, &$calls): void {
            DB::afterCommit(function () use ($fixture, &$calls): void {
                $calls++;
                DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->update([
                    'active' => false, 'access_version' => DB::raw('access_version + 1'), 'updated_at' => CarbonImmutable::now('UTC'),
                ]);
            });
        });
        $this->refused(fn () => $this->award($fixture), 'committed_receipt_refused');
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertFalse((bool) DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->value('active'));
    }

    public function test_committed_callback_uncovered_hint_refuses_success_with_durable_award(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$calls): void {
            if ($calls++ === 0) {
                $this->awardHint();
            }
        });
        $this->refused(fn () => $this->award($fixture), 'committed_receipt_refused');
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    public function test_direct_pdo_replacement_in_beginning_does_not_adopt_or_rollback_foreign_transaction(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $pdo = DB::connection()->getPdo();
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($pdo): void {
            $pdo->commit();
            $pdo->beginTransaction();
        });
        try {
            $this->refused(fn () => $this->award($fixture));
            $this->assertTrue($pdo->inTransaction());
            $this->assertNoAward();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            DB::connection()->setPdo($pdo);
        }
    }

    private function award(array $fixture): array
    {
        return (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    private function assertNoAward(): void
    {
        $this->assertSame(0, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(0, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    private function refused(callable $work, ?string $reason = null): void
    {
        try {
            $work();
            $this->fail('Expected owned award refusal.');
        } catch (MembershipException $error) {
            $reason === null ? $this->assertNotEmpty($error->reason) : $this->assertSame($reason, $error->reason);
        }
    }
}
