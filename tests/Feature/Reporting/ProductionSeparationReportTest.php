<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Reporting\OperationalHealth;
use App\Domain\Reporting\SalesReport;
use App\Filament\Pages\SalesReportPage;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\Support\RefundResolutionFixtures;
use Tests\TestCase;

/**
 * Production checkout figures and test commerce figures stay in separate, labelled sections and are never summed. Paid
 * exceptions are reported apart from net. Empty periods say so instead of showing zeros as fact.
 */
class ProductionSeparationReportTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        RefundResolutionFixtures::configure();
        R::configure();
        Queue::fake();
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
    }

    /** One synthetic production-chain payment with its order and line; shapes satisfy the table guards, references are not real. */
    private function production(int $n, string $fundsMode, string $outcome, int $amount, int $licenseVersionId, string $at): void
    {
        $evidence = fn (): array => ['public_id' => (string) Str::uuid(), 'created_at' => $at, 'payload_ciphertext' => 'SYNTHETIC', 'payload_hash' => str_repeat('a', 64),
            'canonicalization_version' => CanonicalJson::VERSION];
        DB::table('production_checkout_orders')->insert($evidence() + ['review_id' => $n, 'buyer_origin_id' => (string) Str::uuid(), 'request_key' => str_repeat((string) ($n % 10), 64),
            'request_hash' => str_repeat('b', 64), 'total_minor' => $amount, 'line_count' => 1]);
        $order = DB::table('production_checkout_orders')->max('id');
        DB::table('production_checkout_order_lines')->insert($evidence() + ['order_id' => $order, 'position' => 1, 'track_id' => $n, 'offer_revision_id' => $n,
            'license_version_id' => $licenseVersionId, 'amount_minor' => $amount, 'line_hash' => str_repeat('c', 64)]);
        DB::table('production_checkout_payments')->insert($evidence() + ['intent_id' => $n, 'session_id' => $n, 'order_id' => $order, 'account_id' => 'acct_SYNTHETICPROD',
            'funds_mode' => $fundsMode, 'provider_payment_id' => 'pi_SYNTHETIC'.$n, 'amount_minor' => $amount, 'observed_at' => $at, 'outcome' => $outcome]);
    }

    public function test_production_and_test_figures_are_separate_and_labelled_and_paid_exceptions_stay_out_of_net(): void
    {
        $exception = RefundResolutionFixtures::exception(R::bind($this->app)[0]);
        $paid = $exception['payment']->amount_minor;
        $day = $exception['record']->finalized_at->utc()->format('Y-m-d');
        $license = LicenseFixtures::published($this->admin);
        $at = $exception['record']->finalized_at->utc()->format('Y-m-d\TH:i:s\Z');
        Schema::disableForeignKeyConstraints();
        $this->production(1, 'live', 'on_time', 5000, $license->id, $at);
        $this->production(2, 'live', 'on_time', 2500, $license->id, $at);
        $this->production(3, 'live', 'paid_exception', 700, $license->id, $at);
        $this->production(4, 'test', 'on_time', 1200, $license->id, $at);
        Schema::enableForeignKeyConstraints();

        $report = app(SalesReport::class)->build($this->admin, $day, $day, 'day');
        // Test commerce: the only test payment is a paid exception, so it earns no revenue and nothing production leaks in.
        $this->assertSame(['USD' => ['orders' => 0, 'gross_minor' => 0, 'refunded_minor' => 0, 'net_minor' => 0, 'exception_orders' => 1, 'exception_gross_minor' => $paid]],
            $report['test']['currencies']);
        $this->assertSame($paid, $report['test']['periods'][0]['exception_gross_minor']);
        $this->assertSame('TEST (synthetic, Stripe test mode)', $report['test']['label']);
        // Production: live funds and the rehearsal's test funds are separate sections, each USD minor units, exceptions apart.
        $production = $report['production'];
        $this->assertTrue($production['available']);
        $this->assertSame(['live', 'rehearsal'], array_keys($production['modes']));
        $live = $production['modes']['live'];
        $this->assertSame('PRODUCTION (live funds)', $live['label']);
        $this->assertSame([2, 7500, 1, 700, 'USD'], [$live['orders'], $live['gross_minor'], $live['exception_orders'], $live['exception_gross_minor'], $live['currency']]);
        $this->assertSame([['tier' => 'NONBINDING TEST FIXTURE', 'tier_type' => 'non-exclusive', 'lines' => 2, 'gross_minor' => 7500]],
            array_map(fn (array $row): array => array_diff_key($row, ['template_id' => 1]), $live['tiers']));
        $rehearsal = $production['modes']['rehearsal'];
        $this->assertSame(['PRODUCTION CHAIN (test funds, rehearsal)', 1, 1200], [$rehearsal['label'], $rehearsal['orders'], $rehearsal['gross_minor']]);
        $this->assertSame(['orders_prepared' => 4, 'payments_on_time' => 3, 'payments_after_expiry' => 1], $production['funnel']);

        // The CSV labels every row's environment; production rows say refunds are not recorded.
        $rows = app(SalesReport::class)->csvRows($report);
        $environments = array_values(array_unique(array_column($rows, 0)));
        sort($environments);
        $this->assertSame(['PRODUCTION (live funds)', 'PRODUCTION CHAIN (all funds modes)', 'PRODUCTION CHAIN (test funds, rehearsal)', 'TEST (synthetic, Stripe test mode)'], $environments);
        foreach ($rows as $row) {
            if (str_starts_with($row[0], 'PRODUCTION (live') && $row[1] === 'period') {
                $this->assertSame('refunds are not recorded for production orders', $row[12]);
            }
        }
        Livewire::test(SalesReportPage::class)->set('from', $day)->set('to', $day)->assertSee('PRODUCTION (live funds)')->assertSee('PRODUCTION CHAIN (test funds, rehearsal)')
            ->assertSee('Refunds and disputes are not recorded for production orders')->assertSee('Synthetic test orders. Not revenue.');

        // The exception is awaiting review; marking it for further review is reflected, and a resolved one would leave the count.
        $health = fn (): array => collect(app(OperationalHealth::class)->section('commerce', $this->admin)['metrics'])->keyBy('key')->map(fn (array $m) => $m['value'])->all();
        $this->assertSame([1, 0], [$health()['payment_exceptions'], $health()['payment_exceptions_flagged']]);
        app(TestPaymentExceptionOperations::class)->disposition($exception['record']->public_id, $this->admin, (string) Str::uuid(), 0, 'needs_review');
        $this->assertSame([1, 1], [$health()['payment_exceptions'], $health()['payment_exceptions_flagged']]);
        app(TestPaymentExceptionOperations::class)->disposition($exception['record']->public_id, $this->admin, (string) Str::uuid(), 1, 'acknowledged');
        $this->assertSame([1, 0], [$health()['payment_exceptions'], $health()['payment_exceptions_flagged']]);

        // An empty period is reported as empty, not as zero revenue; a missing production table is unavailable, not zero.
        $empty = app(SalesReport::class)->build($this->admin, '2020-01-01', '2020-01-31', 'month');
        $this->assertSame([[], [], []], [$empty['test']['currencies'], $empty['test']['periods'], $empty['production']['modes']]);
        Livewire::test(SalesReportPage::class)->set('from', '2020-01-01')->set('to', '2020-01-31')->set('granularity', 'month')
            ->assertSee('No paid test orders were recorded in this period.')->assertSee('No production checkout payments were recorded in this period.');
        // Payments retained under a different Stripe account are not in scope for the configured test account.
        $account = config('payments.stripe.account_id');
        config(['payments.stripe.account_id' => 'acct_OTHERACCOUNT1']);
        $other = app(SalesReport::class)->build($this->admin, $day, $day, 'day');
        $this->assertSame([[], 0], [$other['test']['currencies'], $health()['payment_exceptions']]);
        config(['payments.stripe.account_id' => $account]);
        $this->assertSame(1, $health()['payment_exceptions']);
        Schema::disableForeignKeyConstraints();
        Schema::drop('production_checkout_payments');
        $missing = app(SalesReport::class)->build($this->admin, $day, $day, 'day')['production'];
        $this->assertSame([false, 'The production checkout tables are not installed.'], [$missing['available'], $missing['reason']]);
        $this->assertTrue(app(SalesReport::class)->build($this->admin, $day, $day, 'day')['test']['available']);
    }
}
