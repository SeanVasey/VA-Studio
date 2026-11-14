<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipSchema;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

/** Existing award fixtures exercise admission only; no eligible reservation or v2 readiness is claimed. */
class MembershipReservationEntryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    private const OPERATIONS = ['reserve', 'release', 'expire'];

    private const SECONDARY = 'membership_reservation_entry_probe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->awardSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::purge(self::SECONDARY);
        parent::tearDown();
    }

    public function test_each_operation_requires_every_member_of_the_complete_capability_gate(): void
    {
        $fixture = $this->awardFixture(observe: false);
        $this->awardTestCapabilities();
        foreach ([MembershipPaidInvoiceAuthority::class, MembershipPolicyFactsAuthority::class,
            MembershipEligibleLicenseAuthority::class, MemberGrantAuthority::class] as $capability) {
            $source = $this->app->make($capability);
            unset($this->app[$capability]);
            try {
                foreach (self::OPERATIONS as $operation) {
                    $this->assertSame('source_capability_absent', $this->reason($operation, $fixture), $operation.' / '.$capability);
                }
            } finally {
                $this->app->instance($capability, $source);
            }
        }
        $this->assertNoEffects();
    }

    public function test_each_operation_refuses_an_existing_primary_transaction_without_rolling_it_back(): void
    {
        $fixture = $this->awardFixture(observe: false);
        $this->awardTestCapabilities();
        DB::transaction(function () use ($fixture): void {
            foreach (self::OPERATIONS as $operation) {
                $this->assertSame('transaction_forbidden', $this->reason($operation, $fixture), $operation);
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            }
        });
        $this->assertNoEffects();
    }

    public function test_each_operation_refuses_an_existing_transaction_on_another_connection(): void
    {
        $fixture = $this->awardFixture(observe: false);
        $this->awardTestCapabilities();
        $secondary = $this->secondary();
        $secondary->beginTransaction();
        try {
            foreach (self::OPERATIONS as $operation) {
                $this->assertSame('transaction_forbidden', $this->reason($operation, $fixture), $operation);
                $this->assertSame(1, $secondary->transactionLevel());
                $this->assertTrue($secondary->getPdo()->inTransaction());
                $this->assertSame(0, DB::transactionLevel());
            }
        } finally {
            $secondary->rollBack();
        }
        $this->assertNoEffects();
    }

    public function test_capability_callback_cannot_open_another_transaction_after_the_initial_admission(): void
    {
        $fixture = $this->awardFixture(observe: false);
        $secondary = $this->secondary();
        foreach (self::OPERATIONS as $operation) {
            $this->awardTestCapabilities();
            $resolutions = 0;
            $this->app->bind(MembershipPaidInvoiceAuthority::class, function () use (&$resolutions, $secondary): SealedMembershipPaidInvoice {
                // Initial gate, captured source, then the full gate against that capture.
                if (++$resolutions === 3) {
                    $secondary->beginTransaction();
                }

                return new SealedMembershipPaidInvoice;
            });
            try {
                $this->assertSame('transaction_forbidden', $this->reason($operation, $fixture), $operation);
                $this->assertSame(3, $resolutions);
                $this->assertSame(1, $secondary->transactionLevel());
                $this->assertTrue($secondary->getPdo()->inTransaction());
                $this->assertSame(0, DB::transactionLevel());
            } finally {
                if ($secondary->transactionLevel() > 0) {
                    $secondary->rollBack();
                }
            }
        }
        $this->assertNoEffects();
    }

    public function test_capability_callback_cannot_remove_a_remaining_capability_during_capture(): void
    {
        $fixture = $this->awardFixture(observe: false);
        foreach (self::OPERATIONS as $operation) {
            $this->awardTestCapabilities();
            $resolutions = 0;
            $this->app->bind(MembershipPaidInvoiceAuthority::class, function () use (&$resolutions): SealedMembershipPaidInvoice {
                if (++$resolutions === 2) {
                    $this->app->forgetInstance(MemberGrantAuthority::class);
                }

                return new SealedMembershipPaidInvoice;
            });
            $this->assertSame('changed_policy', $this->reason($operation, $fixture), $operation);
            $this->assertSame(2, $resolutions);
        }
        $this->assertNoEffects();
    }

    public function test_transient_registration_cannot_make_an_incomplete_baseline_authoritative(): void
    {
        $fixture = $this->awardFixture(observe: false);
        foreach (self::OPERATIONS as $operation) {
            $this->awardTestCapabilities();
            $grant = $this->app->make(MemberGrantAuthority::class);
            $grantResolutions = 0;
            $transientGrant = function () use ($grant, &$grantResolutions): MemberGrantAuthority {
                $grantResolutions++;
                unset($this->app[MemberGrantAuthority::class]);

                return $grant;
            };
            $this->app->bind(MemberGrantAuthority::class, $transientGrant);
            $invoiceResolutions = 0;
            $this->app->bind(MembershipPaidInvoiceAuthority::class, function () use (&$invoiceResolutions, $transientGrant): SealedMembershipPaidInvoice {
                if (++$invoiceResolutions === 3) {
                    $this->app->bind(MemberGrantAuthority::class, $transientGrant);
                }

                return new SealedMembershipPaidInvoice;
            });
            $this->assertSame('source_capability_absent', $this->reason($operation, $fixture), $operation);
            $this->assertSame(1, $grantResolutions);
            $this->assertFalse($this->app->bound(MemberGrantAuthority::class));
        }
        $this->assertNoEffects();
    }

    private function secondary(): Connection
    {
        config(['database.connections.'.self::SECONDARY => config('database.connections.'.DB::getDefaultConnection())]);

        return DB::connection(self::SECONDARY);
    }

    private function reason(string $operation, array $fixture): ?string
    {
        $service = new MembershipReservationOperations;
        $locator = $fixture['invoice']['id'];
        $principal = $fixture['buyer']['principal'];
        $actor = $fixture['buyer']['user'];
        try {
            match ($operation) {
                'reserve' => $service->reserve($locator, '1', 1, 'entry-boundary-request', $principal, $actor),
                'release' => $service->release($locator, '00000000-0000-4000-8000-000000000001', $principal, $actor),
                'expire' => $service->expire($locator, $principal, $actor),
            };

            return null;
        } catch (MembershipException $error) {
            return $error->reason;
        }
    }

    private function assertNoEffects(): void
    {
        $this->assertSame([0, 0, 0], [DB::table(MembershipPeriodRecords::PERIODS)->count(),
            DB::table(MembershipSchema::TABLES[2])->count(), DB::table(MembershipPeriodRecords::EVENTS)->count()]);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
