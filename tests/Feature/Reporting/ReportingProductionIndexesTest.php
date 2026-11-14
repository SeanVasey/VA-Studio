<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchemaInstaller;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Reporting\SalesReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

class ReportingProductionIndexesTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_03_269100_reporting_period_indexes.php');
    }

    private function ddlDuring(callable $operation): array
    {
        $ddl = [];
        $active = true;
        DB::listen(function ($query) use (&$ddl, &$active): void {
            if ($active && preg_match('/\A\s*(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        try {
            $operation();
        } finally {
            $active = false;
        }

        return $ddl;
    }

    private function retained(): array
    {
        $rows = [];
        foreach (CheckoutSchema::TABLES as $table) {
            $rows[$table] = [DB::table($table)->orderBy('id')->get()->toJson(), Schema::getColumns($table), Schema::getForeignKeys($table)];
        }

        return $rows;
    }

    public function test_real_retained_production_history_upgrades_without_changing_totals_and_rollback_is_not_current_admission(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
        $fixture = $this->payable();
        $fixture['hosted']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
        $fixture['gateway']->paid = true;
        $paid = $fixture['hosted']->reconcile($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);
        $this->assertSame(1, DB::table('production_checkout_orders')->count());
        $this->assertSame(1, DB::table('production_checkout_payments')->count());
        $staff = LicenseFixtures::admin();
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $date = now()->utc()->format('Y-m-d');
        $report = fn (): array => app(SalesReport::class)->build($staff, $date, $date, 'day')['production'];
        $before = [$this->retained(), $report()];
        $this->assertTrue($before[1]['available']);
        $this->assertSame(1, $before[1]['modes']['rehearsal']['orders']);
        $this->assertSame(4999, $before[1]['modes']['rehearsal']['gross_minor']);
        $this->assertSame([], $this->ddlDuring(fn () => (new CheckoutSchemaInstaller)->up()));
        $this->migration()->down();
        $this->assertSame($before, [$this->retained(), $report()]);
        $this->assertSame([], $this->ddlDuring(fn () => (new CheckoutSchemaInstaller)->assertReportingUpgrade()));
        $this->assertSame([], $this->ddlDuring(function (): void {
            try {
                (new CheckoutSchemaInstaller)->up();
                $this->fail('Missing reporting indexes must not become normal current admission.');
            } catch (LogicException|CheckoutException) {
            }
        }));
        $this->migration()->up();
        $this->assertSame($before, [$this->retained(), $report()]);
        $this->assertSame([], $this->ddlDuring(fn () => (new CheckoutSchemaInstaller)->up()));
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->up()));
    }

    public function test_unknown_and_malformed_production_indexes_refuse_both_normal_and_upgrade_admission_before_ddl(): void
    {
        $table = 'production_checkout_payments';
        $owned = 'pco_payments_reporting_observed_at_index';
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('amount_minor', 'rep_foreign_index'));
        $this->assertAdmissionRefused();
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex('rep_foreign_index'));
        Schema::table($table, function (Blueprint $blueprint) use ($owned): void {
            $blueprint->dropIndex($owned);
            $blueprint->index('amount_minor', $owned);
        });
        $this->assertAdmissionRefused();
        foreach (['up', 'down'] as $direction) {
            $before = $this->retained();
            $this->assertSame([], $this->ddlDuring(function () use ($direction): void {
                try {
                    $this->migration()->{$direction}();
                    $this->fail('A malformed owned production index must refuse before migration DDL.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('Reporting index definition differs', $error->getMessage());
                }
            }));
            $this->assertSame($before, $this->retained());
        }
    }

    private function assertAdmissionRefused(): void
    {
        foreach (['up', 'assertReportingUpgrade'] as $method) {
            $before = $this->retained();
            $this->assertSame([], $this->ddlDuring(function () use ($method): void {
                try {
                    (new CheckoutSchemaInstaller)->{$method}();
                    $this->fail('Unknown or malformed index was admitted.');
                } catch (LogicException|CheckoutException) {
                }
            }));
            $this->assertSame($before, $this->retained());
        }
    }
}
