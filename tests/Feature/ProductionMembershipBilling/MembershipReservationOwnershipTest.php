<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Production\MembershipAwardConfiguration;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipReservationContext;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\MembershipRows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

class MembershipReservationOwnershipTest extends TestCase
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

    public function test_direct_context_construction_cannot_mutate_even_with_real_source_capabilities(): void
    {
        $fixture = $this->reservationFixture();
        DB::transaction(function () use ($fixture): void {
            $rows = new MembershipRows;
            $policy = new MembershipPolicy;
            $membership = $policy->current();
            $billingPolicy = new BillingPolicy;
            $billing = $billingPolicy->current();
            $configuration = new MembershipAwardConfiguration(requireComplete: true);
            $this->expectRefusal(fn () => new MembershipReservationContext($rows, $configuration, $policy, $membership,
                $billingPolicy, $billing, $fixture['buyer']['principal'], $fixture['buyer']['user'], hrtime(true) + 60_000_000_000,
                'reserve', $fixture['invoice']['id'], ['selection' => $fixture['selection'],
                    'expected_revision' => $fixture['expected_revision'], 'request_key' => 'direct-context']), 'owned_invocation_required');
            $this->assertSame(1, DB::transactionLevel());
        });
        $this->assertNoReservation();
    }

    public function test_committing_configuration_change_rolls_back_redemption_and_event(): void
    {
        $fixture = $this->reservationFixture();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$calls): void {
            $calls++;
            config(['production-memberships.enabled' => false]);
        });
        $this->expectRefusal(fn () => $this->reserveFixture($fixture), 'changed_policy');
        $this->assertSame(1, $calls);
        $this->assertNoReservation();
    }

    public function test_after_commit_configuration_change_refuses_response_but_preserves_durable_effects(): void
    {
        $fixture = $this->reservationFixture();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$calls): void {
            $calls++;
            config(['production-memberships.enabled' => false]);
        });
        $this->expectRefusal(fn () => $this->reserveFixture($fixture), 'committed_receipt_refused');
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_committing_at_honor_deadline_cannot_commit_new_reservation(): void
    {
        $fixture = $this->reservationFixture(honorOverrides: ['duration_seconds' => 60]);
        $at = CarbonImmutable::now('UTC');
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($at): void {
            CarbonImmutable::setTestNow($at->addSeconds(60));
        });
        $this->expectRefusal(fn () => $this->reserveFixture($fixture));
        $this->assertNoReservation();
    }

    public function test_reservation_proof_is_issuer_owned_and_cannot_be_serialized_or_adopted_by_a_new_frame(): void
    {
        $fixture = $this->reservationFixture();
        $reserved = $this->reserveFixture($fixture);
        $issuer = new MembershipReservations;
        $proof = DB::transaction(function () use ($issuer, $fixture, $reserved) {
            $rows = new MembershipRows;
            $proof = $issuer->lock($reserved['redemption_id'], $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            $issuer->proveCurrent($proof, $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows);
            $this->assertSame($reserved['reservation_event_id'], $proof->binding()->reservationId);
            $this->expectRefusal(fn () => (new MembershipReservations)->proveCurrent($proof,
                $fixture['buyer']['principal'], $fixture['buyer']['user'], $rows), 'foreign_reservation_proof');
            $this->expectRefusal(fn () => serialize($proof), 'serialization_refused');
            $this->expectRefusal(fn () => clone $proof, 'clone_refused');

            return $proof;
        });
        DB::transaction(function () use ($issuer, $fixture, $proof): void {
            $this->expectRefusal(fn () => $issuer->proveCurrent($proof, $fixture['buyer']['principal'],
                $fixture['buyer']['user'], new MembershipRows), 'foreign_reservation_proof');
        });
        $this->assertSame(1, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(2, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    private function assertNoReservation(): void
    {
        $this->assertSame(0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
    }

    private function expectRefusal(callable $operation, ?string $reason = null): void
    {
        try {
            $operation();
            $this->fail('Operation unexpectedly succeeded.');
        } catch (MembershipException $error) {
            if ($reason !== null) {
                $this->assertSame($reason, $error->reason);
            } else {
                $this->assertNotSame('', $error->reason);
            }
        }
    }
}
