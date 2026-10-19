<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipCustomerRead;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Real award/reserve/authoring writers; synthetic extra periods are sealed with the real record format. */
class MembershipCustomerIndexTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberActivationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_index_lists_the_owned_period_balance_reservation_and_applicable_definition_without_writes(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->counts();
        $index = $this->index($fixture);
        $period = (array) DB::table(MembershipPeriodRecords::PERIODS)->sole();
        $redemption = (array) DB::table(MembershipReservationRecords::TABLE)->sole();
        $this->assertSame(['schemaVersion' => 1, 'periodLimit' => 100, 'hasMore' => false, 'provenance' => 'synthetic_rehearsal',
            'periods' => [[
                'id' => $period['id'], 'planVersionId' => $fixture['plan']['id'], 'periodStart' => $period['period_start'],
                'periodEnd' => $period['period_end'], 'creditExpiresAt' => $period['credit_expires_at'], 'allowance' => 3,
                'balances' => ['available' => 2, 'reserved' => 1, 'consumed' => 0, 'expired' => 0],
                'applicableDefinition' => ['id' => $fixture['member_authored']->definitionId],
                'reservations' => [[
                    'id' => $fixture['reservation']['redemption_id'], 'offerId' => (int) $fixture['selection'],
                    'offerRevisionId' => $fixture['expected_revision'], 'creditAmount' => 1,
                    'reservedAt' => $redemption['created_at'], 'honorDeadline' => $fixture['reservation']['honor_deadline'],
                    'status' => 'reserved', 'closedAt' => null,
                ]],
            ]]], $index);
        // Customer output carries no provider or billing-ledger identifier, hash, ciphertext or seal.
        $encoded = CanonicalJson::encode($index);
        foreach ([$fixture['invoice']['id'], $fixture['provider_invoice_ref'], $period['source_invoice_hash'],
            $period['invoice_graph_hash'], $period['seal'], $redemption['seal'], $redemption['intent_hash']] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        // Replay: an unchanged state yields the identical projection.
        $this->assertSame($index, $this->index($fixture));
    }

    public function test_consumed_and_released_reservations_report_their_terminal_status(): void
    {
        $fixture = $this->memberActivationFixture();
        $activation = $this->activateMemberFixture($fixture);
        $this->assertSame($fixture['reservation']['redemption_id'], $activation->redemptionId);
        $second = $this->reserveFixture($fixture, 'second-synthetic-reservation');
        CarbonImmutable::setTestNow(CarbonImmutable::parse($second['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        (new MembershipReservationOperations)->release($fixture['invoice']['id'], $second['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $period = $this->index($fixture)['periods'][0];
        $this->assertSame(['available' => 2, 'reserved' => 0, 'consumed' => 1, 'expired' => 0], $period['balances']);
        $statuses = array_column($period['reservations'], 'status', 'id');
        $this->assertSame(['consumed', 'released'], [$statuses[$fixture['reservation']['redemption_id']], $statuses[$second['redemption_id']]]);
        foreach ($period['reservations'] as $reservation) {
            $this->assertNotNull($reservation['closedAt']);
        }
    }

    public function test_bound_is_one_hundred_periods_and_the_sentinel_only_reports_more(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->syntheticPeriods($fixture, 99);
        $full = $this->index($fixture);
        $this->assertCount(100, $full['periods']);
        $this->assertFalse($full['hasMore']);
        $this->assertSame($fixture['reservation']['period_id'], $full['periods'][0]['id']);
        $starts = array_column($full['periods'], 'periodStart');
        $sorted = $starts;
        rsort($sorted);
        $this->assertSame($sorted, $starts);
        $oldest = $this->syntheticPeriods($fixture, 1, 100)[0];
        $more = $this->index($fixture);
        $this->assertCount(100, $more['periods']);
        $this->assertTrue($more['hasMore']);
        $this->assertSame(array_column($full['periods'], 'id'), array_column($more['periods'], 'id'));
        $this->assertNotContains($oldest, array_column($more['periods'], 'id'));
    }

    public function test_sentinel_row_is_authenticated_like_every_listed_period(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->syntheticPeriods($fixture, 99);
        $sentinel = $this->syntheticPeriods($fixture, 1, 100)[0];
        // A correctly sealed but inconsistent first award in the sentinel alone refuses the whole index.
        $this->resealEvent($sentinel, ['after_available' => 2]);
        $this->refused(fn () => $this->index($fixture), 'tampered');
    }

    public function test_ledger_audit_failure_refuses_the_whole_index(): void
    {
        $fixture = $this->memberActivationFixture();
        // A correctly sealed reserve event whose balances disagree with the chain fails only the ledger audit.
        $this->resealEvent($fixture['reservation']['period_id'], ['after_reserved' => 2], 'reserve');
        $this->refused(fn () => $this->index($fixture), 'invalid_credit_balance');
    }

    public function test_a_foreign_owner_sees_only_their_own_empty_index(): void
    {
        $fixture = $this->memberActivationFixture();
        $other = $this->enrollThroughLocalSmtp('second-member@example.test');
        $this->assertSame(['schemaVersion' => 1, 'periodLimit' => 100, 'hasMore' => false, 'provenance' => 'synthetic_rehearsal',
            'periods' => []], (new MembershipCustomerIndex)->index($other['principal'], $other['user']));
        $this->assertCount(1, $this->index($fixture)['periods']);
    }

    public function test_withdrawn_owner_is_refused(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->withdraw($fixture);
        $this->refused(fn () => $this->index($fixture), 'owner_refused');
    }

    public function test_owner_withdrawal_while_the_index_commits_refuses_the_terminal_proof(): void
    {
        $fixture = $this->memberActivationFixture();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($fixture, &$calls): void {
            if ($calls++ === 0) {
                $this->withdraw($fixture);
            }
        });
        $this->refused(fn () => $this->index($fixture), 'owner_refused');
        $this->assertSame(1, $calls);
    }

    public function test_prebuilt_response_is_released_only_after_a_fresh_pre_bytes_proof(): void
    {
        $fixture = $this->memberActivationFixture();
        $read = MembershipCustomerRead::begin();
        $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        $this->assertCount(1, $index['periods']);
        $read->proveBeforeBytes();
        // One use only, and a captured read is never adopted by a second index call.
        $this->refused(fn () => $read->proveBeforeBytes(), 'foreign_customer_read');
        $this->refused(fn () => (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read), 'foreign_customer_read');
        $this->refused(fn () => serialize($read), 'serialization_refused');
        $this->refused(fn () => MembershipCustomerRead::begin()->proveBeforeBytes(), 'foreign_customer_read');
    }

    public function test_owner_withdrawal_after_the_index_refuses_the_pre_bytes_proof(): void
    {
        $fixture = $this->memberActivationFixture();
        $read = MembershipCustomerRead::begin();
        (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        $this->withdraw($fixture);
        $this->refused(fn () => $read->proveBeforeBytes(), 'owner_refused');
    }

    public function test_ledger_change_or_gate_withdrawal_after_the_index_refuses_the_pre_bytes_proof(): void
    {
        $fixture = $this->memberActivationFixture();
        $read = MembershipCustomerRead::begin();
        (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        // A genuine later reservation changes the visible balance: the prebuilt body is stale.
        $this->reserveFixture($fixture, 'second-synthetic-reservation');
        $this->refused(fn () => $read->proveBeforeBytes(), 'changed_before_bytes');
        $gated = MembershipCustomerRead::begin();
        (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $gated);
        config(['member-grants.enabled' => false]);
        $this->refused(fn () => $gated->proveBeforeBytes(), 'index_refused');
    }

    public function test_default_off_gates_refuse(): void
    {
        $fixture = $this->memberActivationFixture();
        config(['member-grants.enabled' => false]);
        $this->refused(fn () => $this->index($fixture), 'index_refused');
        config(['member-grants.enabled' => true, 'production-memberships.enabled' => false]);
        $this->refused(fn () => $this->index($fixture), 'disabled');
        config(['production-memberships.enabled' => true]);
        DB::transaction(fn () => $this->refused(fn () => $this->index($fixture), 'transaction_forbidden'));
        $this->assertCount(1, $this->index($fixture)['periods']);
    }

    private function index(array $fixture): array
    {
        return (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    /** Older adjacent synthetic periods in the exact retained format, inserted below the real writers' newest one. */
    private function syntheticPeriods(array $fixture, int $count, int $offset = 0): array
    {
        $real = (array) DB::table(MembershipPeriodRecords::PERIODS)->where('id', $fixture['reservation']['period_id'])->sole();
        $award = (array) DB::table(MembershipPeriodRecords::EVENTS)->where('period_id', $real['id'])->where('kind', 'award')->sole();
        $plan = (array) DB::table(MembershipRecords::TABLE)->where('id', $real['plan_version_id'])->sole();
        $policy = MembershipPolicyVersions::document($plan)->binding($plan['id']);
        $originId = (int) $real['identity_origin_id'];
        $invoice = MembershipPeriodRecords::historicalInvoice($real, $award, $policy, $originId);
        $ids = [];
        for ($index = 1; $index <= $count; $index++) {
            $shift = ($offset + $index) * 2678400;
            $move = static fn (?string $at): ?string => $at === null ? null : gmdate('Y-m-d H:i:s', strtotime($at.' UTC') - $shift);
            $copy = new MembershipInvoiceBinding(hash('sha256', 'synthetic-index-invoice-'.($offset + $index)),
                hash('sha256', 'synthetic-index-graph-'.($offset + $index)), $invoice->providerScopeHash, $invoice->amountMinor,
                $invoice->currency, $move($invoice->periodStart), $move($invoice->periodEnd), $invoice->planPolicyHash,
                $invoice->originalTermsHash, $invoice->originalBuyerBinding, $invoice->provenance, $move($invoice->paidAt), $invoice->planVersionId);
            [$period, $event] = MembershipPeriodRecords::create($copy, $policy, $originId, $fixture['buyer']['principal']->durableBinding());
            DB::table(MembershipPeriodRecords::PERIODS)->insert($period);
            DB::table(MembershipPeriodRecords::EVENTS)->insert($event);
            $ids[] = $period['id'];
        }

        return $ids;
    }

    /** SQLite test-only edit: the immutable-row guard is restored byte for byte after one resealed event change. */
    private function resealEvent(string $periodId, array $changes, string $kind = 'award'): void
    {
        $pdo = DB::connection()->getPdo();
        $guard = 'production_membership_credit_events_update';
        $statement = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?");
        $statement->execute([$guard]);
        $sql = $statement->fetchColumn();
        $this->assertIsString($sql);
        $row = (array) DB::table(MembershipPeriodRecords::EVENTS)->where('period_id', $periodId)->where('kind', $kind)->sole();
        $row = [...$row, ...$changes];
        $row['seal'] = MembershipPeriodRecords::seal(MembershipPeriodRecords::EVENTS, $row);
        $pdo->exec('DROP TRIGGER '.$guard);
        try {
            DB::table(MembershipPeriodRecords::EVENTS)->where('id', $row['id'])->update([...$changes, 'seal' => $row['seal']]);
        } finally {
            $pdo->exec($sql);
        }
    }

    private function withdraw(array $fixture): void
    {
        DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->update([
            'active' => false, 'access_version' => DB::raw('access_version + 1'), 'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function counts(): array
    {
        $counts = [];
        foreach (['production_membership_paid_periods', 'production_membership_credit_events', 'production_membership_redemptions',
            'production_member_origins', 'production_member_activations', 'production_member_definitions'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
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
