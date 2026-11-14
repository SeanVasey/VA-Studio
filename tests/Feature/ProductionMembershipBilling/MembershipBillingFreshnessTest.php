<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingFreshnessWindow;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipBillingEvidence;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceProof;
use App\Domain\Memberships\Production\MembershipPaidInvoiceToken;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

class MembershipBillingFreshnessTest extends TestCase
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

    public function test_original_native_window_is_only_returned_for_the_same_issued_customer_frame(): void
    {
        $fixture = $this->awardFixture();
        $authority = new SealedMembershipPaidInvoice;
        $buyer = $fixture['buyer'];
        $proof = DB::transaction(function () use ($fixture, $authority, $buyer): MembershipPaidInvoiceProof {
            $rows = new MembershipRows;
            $proof = $authority->lock($fixture['invoice']['id'], $buyer['principal'], $buyer['user'], $rows);
            $window = $authority->freshnessWindow($proof, $buyer['principal'], $buyer['user'], $rows);
            $deadline = $window->deadlineNs();
            $this->assertSame($window, $authority->freshnessWindow($proof, $buyer['principal'], $buyer['user'], $rows));
            $this->assertLessThanOrEqual($deadline, $window->deadlineNs());
            $this->assertLessThanOrEqual($window->originalDeadlineNs, $window->deadlineNs());
            $window->assertDeadline();
            $this->refused(fn () => (new SealedMembershipPaidInvoice)->freshnessWindow($proof, $buyer['principal'], $buyer['user'], $rows), 'foreign_invoice_proof');
            $this->refused(fn () => $authority->freshnessWindow(MembershipPaidInvoiceToken::value($proof->binding()),
                $buyer['principal'], $buyer['user'], $rows), 'foreign_invoice_proof');

            return $proof;
        });
        DB::transaction(fn () => $this->refused(fn () => $authority->freshnessWindow($proof, $buyer['principal'], $buyer['user'], new MembershipRows),
            'foreign_or_expired_invoice_proof'));
        $this->assertSame(0, DB::table(MembershipPeriodRecords::PERIODS)->count());
    }

    public function test_retained_billing_freshness_uses_native_time_independently_of_the_worker_carbon_clock(): void
    {
        $fixture = $this->awardFixture();
        $at = CarbonImmutable::now('UTC');
        DB::transaction(function () use ($fixture, $at): void {
            $rows = new MembershipRows;
            $configuration = (new BillingPolicy)->current();
            $evidence = MembershipBillingEvidence::lock($rows, $fixture['invoice']['id'], $configuration, hrtime(true) + 60_000_000_000);
            $window = $evidence['freshness'];
            $firstCap = $window->deadlineNs();
            foreach ([$at->addDays(2), $at->subDays(2)] as $workerTime) {
                CarbonImmutable::setTestNow($workerTime);
                $snapshot = MembershipBillingEvidence::currentSnapshot($rows, $evidence['binding'], $configuration, $window);
                $this->assertSame($window, $snapshot['freshness']);
                MembershipBillingEvidence::assertCurrentSnapshot($rows, $snapshot);
                $this->assertLessThanOrEqual($firstCap, $window->deadlineNs());
            }
        });
        // This isolates billing time; it makes no claim that identity/business clocks ignore Carbon.
        $this->assertSame(0, DB::table(MembershipPeriodRecords::PERIODS)->count());
    }

    /** Explicit synthetic cap consumption over the real issuer's original window; no elapsed-time claim. */
    #[DataProvider('boundaries')]
    public function test_owned_context_carries_the_original_cap_through_precommit_and_committed_admission(string $operation, bool $committed): void
    {
        if ($operation === 'award') {
            $fixture = $this->awardFixture();
            $this->awardTestCapabilities();
        } else {
            $fixture = $this->reservationFixture();
        }
        $authority = $this->captureIssuerWindow();
        $callbackCount = 0;
        $contextReason = null;
        $sameWindow = false;
        $effectCounts = [];
        $originalUnchanged = false;
        DB::connection()->getEventDispatcher()->listen($committed ? TransactionCommitted::class : TransactionCommitting::class,
            function () use ($authority, &$callbackCount, &$contextReason, &$sameWindow, &$effectCounts, &$originalUnchanged): void {
                $callbackCount++;
                $window = $authority->window;
                $original = $window->originalDeadlineNs;
                // Only shorten the actual issued object's private cap. No identity, row or original cap is replaced.
                (new ReflectionProperty($window, 'deadline'))->setValue($window, hrtime(true) - 1);
                $originalUnchanged = $original === $window->originalDeadlineNs;
                $observer = DB::connection()->getEventDispatcher();
                $context = (new ReflectionProperty($observer, 'context'))->getValue($observer);
                $sameWindow = (new ReflectionProperty($context, 'invoiceFreshness'))->getValue($context) === $window;
                // Direct terminal admission isolates Context propagation from an earlier producer reproof.
                try {
                    (new ReflectionMethod($context, 'admit'))->invoke($context);
                } catch (BillingException $error) {
                    $contextReason = $error->reason;
                }
                $effectCounts = [DB::table(MembershipPeriodRecords::PERIODS)->count(),
                    DB::table(MembershipPeriodRecords::EVENTS)->count(), DB::table(MembershipReservationRecords::TABLE)->count()];
            });
        $this->refused(fn () => $operation === 'award'
            ? (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user'])
            : $this->reserveFixture($fixture), $committed ? 'committed_receipt_refused' : 'operation_refused');
        $this->assertSame(1, $callbackCount);
        $this->assertTrue($sameWindow);
        $this->assertTrue($originalUnchanged);
        $this->assertSame('expired_invoice_proof', $contextReason);
        $this->assertSame($operation === 'award' ? [1, 1, 0] : [1, 2, 1], $effectCounts);
        $this->assertSame($committed || $operation === 'reserve' ? 1 : 0, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame($operation === 'award' ? (int) $committed : 1 + (int) $committed, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertSame($operation === 'reserve' && $committed ? 1 : 0, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function boundaries(): array
    {
        return ['award before commit' => ['award', false], 'award after commit' => ['award', true],
            'reserve before commit' => ['reserve', false], 'reserve after commit' => ['reserve', true]];
    }

    private function captureIssuerWindow(): MembershipPaidInvoiceAuthority
    {
        $authority = new class(new SealedMembershipPaidInvoice) implements MembershipPaidInvoiceAuthority
        {
            public ?BillingFreshnessWindow $window = null;

            public function __construct(private readonly SealedMembershipPaidInvoice $delegate) {}

            public function lock(string $locator, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipPaidInvoiceProof
            {
                return $this->delegate->lock($locator, $principal, $actor, $rows);
            }

            public function proveCurrent(MembershipPaidInvoiceProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
            {
                $this->delegate->proveCurrent($proof, $principal, $actor, $rows);
            }

            public function freshnessWindow(MembershipPaidInvoiceProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): BillingFreshnessWindow
            {
                return $this->window = $this->delegate->freshnessWindow($proof, $principal, $actor, $rows);
            }
        };
        $this->app->instance(MembershipPaidInvoiceAuthority::class, $authority);

        return $authority;
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected '.$reason);
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
