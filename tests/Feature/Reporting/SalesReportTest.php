<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Reporting\ReportingException;
use App\Domain\Reporting\SalesReport;
use App\Filament\Pages\SalesReportPage;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** Sales and conversion figures: exact minor-unit sums per currency, period buckets, tiers, funnel and an audited export. */
class SalesReportTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        R::configure();
        Queue::fake();
        [$this->payments] = R::bind($this->app);
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
    }

    private function report(string $from, string $to, string $granularity): array
    {
        return app(SalesReport::class)->build($this->admin, $from, $to, $granularity);
    }

    public function test_orders_sum_exactly_in_minor_units_by_period_and_license_tier_and_the_export_is_audited(): void
    {
        $a = DeliveryFixtures::ready($this->payments, true);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $b = ReportingFixtures::single($this->payments, 'B');
        $this->travelTo(CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC'));
        $c = ReportingFixtures::single($this->payments, 'C');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $paid = fn (array $f): int => $f['payment']->amount_minor;
        $gross = $paid($a) + $paid($b) + $paid($c);
        $this->assertSame($gross, (int) VerifiedPayment::sum('amount_minor'));
        $this->assertGreaterThan(0, ExclusiveSale::count());

        $month = $this->report('2026-09-01', '2026-10-31', 'month');
        $this->assertTrue($month['test']['available']);
        $this->assertSame([['period' => '2026-09', 'currency' => 'USD', 'orders' => 1, 'gross_minor' => $paid($a), 'refunded_minor' => 0, 'net_minor' => $paid($a),
            'exception_orders' => 0, 'exception_gross_minor' => 0],
            ['period' => '2026-10', 'currency' => 'USD', 'orders' => 2, 'gross_minor' => $paid($b) + $paid($c), 'refunded_minor' => 0, 'net_minor' => $paid($b) + $paid($c),
                'exception_orders' => 0, 'exception_gross_minor' => 0]], $month['test']['periods']);
        $this->assertSame(['USD' => ['orders' => 3, 'gross_minor' => $gross, 'refunded_minor' => 0, 'net_minor' => $gross, 'exception_orders' => 0, 'exception_gross_minor' => 0]],
            $month['test']['currencies']);
        $day = $this->report('2026-09-29', '2026-10-03', 'day');
        $this->assertSame([['2026-09-30', $paid($a)], ['2026-10-01', $paid($b)], ['2026-10-03', $paid($c)]],
            array_map(fn (array $row): array => [$row['period'], $row['gross_minor']], $day['test']['periods']));
        // Half-open UTC day boundaries: the order finalized exactly at midnight belongs to the 3rd, not to a report that ends on the 2nd
        // (and a report starting on the 3rd has it), so adjacent periods never double count.
        $this->assertSame($paid($a) + $paid($b), $this->report('2026-09-30', '2026-10-02', 'day')['test']['currencies']['USD']['gross_minor']);
        $this->assertSame($paid($c), $this->report('2026-10-03', '2026-10-04', 'day')['test']['currencies']['USD']['gross_minor']);
        $this->assertSame([], $this->report('2026-08-01', '2026-08-31', 'month')['test']['currencies']);

        // Tier totals come from the hash-verified pricing evidence and equal the independent read of each verified original.
        $expected = [];
        foreach ([$a, $b, $c] as $f) {
            $original = app(ReadOrder::class)->verify($f['order']);
            foreach ($f['grants'] as $grant) {
                $position = $grant->orderLine->position ?? DB::table('order_lines')->where('id', $grant->order_line_id)->value('position');
                $exclusive = ExclusiveSale::where('license_grant_id', $grant->id)->exists();
                $line = $original['lines'][$position]['pricing'];
                $expected[$exclusive ? 'exclusive' : 'non-exclusive'][] = $line['total_minor'];
            }
        }
        $tiers = $month['test']['tiers'];
        $this->assertTrue($tiers['available']);
        $this->assertSame(0, $tiers['unverified_orders']);
        $this->assertSame($gross, array_sum(array_column($tiers['rows'], 'paid_minor')));
        foreach (['exclusive', 'non-exclusive'] as $type) {
            $rows = array_filter($tiers['rows'], fn (array $row): bool => $row['tier_type'] === $type);
            $this->assertSame(count($expected[$type]), array_sum(array_column($rows, 'lines')), $type);
            $this->assertSame(array_sum($expected[$type]), array_sum(array_column($rows, 'paid_minor')), $type);
        }
        foreach ($tiers['rows'] as $row) {
            $this->assertSame($row['paid_minor'], $row['base_minor'] - $row['discount_minor'] + $row['tax_minor']);
            $this->assertSame(0, $row['refunded_minor']);
        }
        $counts = $month['test']['funnel']['counts'];
        // The mixed cart's fixture builds a first quote of its own, so quotes exceed orders by one; no bot or replay filter is applied.
        $this->assertSame([4, 3, 3, 0, 0], [$counts['quotes_created'], $counts['orders_prepared'], $counts['paid'], $counts['paid_exception'], $counts['released_unpaid']]);
        $this->assertSame(10000, $month['test']['funnel']['conversion_bps']);
        $this->assertFalse($month['production']['available'] && $month['production']['modes'] !== [], 'No production payment exists.');

        // The page renders the same figures; the export is audited, hash-bound.
        $page = Livewire::test(SalesReportPage::class)->set('from', '2026-09-29')->set('to', '2026-10-03')->set('granularity', 'day')
            ->assertSee('TEST (synthetic, Stripe test mode)')->assertSee('Synthetic test orders. Not revenue.')->assertSee((string) $gross);
        $page->callAction('exportCsv')->assertFileDownloaded('sales-report-2026-09-29-to-2026-10-03.csv');
        $audit = AuditEvent::where('action', 'reporting.sales_exported')->sole();
        $csv = app(SalesReport::class)->export($this->admin, '2026-09-29', '2026-10-03', 'day');
        $this->assertSame(2, AuditEvent::where('action', 'reporting.sales_exported')->count());
        $this->assertSame($csv['sha256'], AuditEvent::where('action', 'reporting.sales_exported')->orderByDesc('id')->first()->context['sha256']);
        $this->assertSame(hash('sha256', $csv['csv']), $audit->context['sha256']);
        $this->assertSame($this->admin->id, $audit->actor_id);
        // MySQL's JSON column normalises key order, so compare the context as a set of pairs.
        $this->assertEqualsCanonicalizing(['from' => '2026-09-29', 'to' => '2026-10-03', 'granularity' => 'day'], array_intersect_key($audit->context, array_flip(['from', 'to', 'granularity'])));
        $lines = array_map('str_getcsv', explode("\n", trim($csv['csv'])));
        $this->assertSame(['environment', 'record', 'period', 'currency', 'tier', 'tier_type', 'count', 'gross_minor', 'discount_minor', 'tax_minor', 'refunded_minor', 'net_minor', 'note'], $lines[0]);
        $periodRows = array_values(array_filter($lines, fn (array $row): bool => $row[1] === 'period'));
        $this->assertSame([(string) $paid($a), (string) $paid($b), (string) $paid($c)], array_column($periodRows, 7));
        $this->assertSame(['TEST (synthetic, Stripe test mode)'], array_values(array_unique(array_column(array_slice($lines, 1), 0))));

        foreach ([['2026-10-03', '2026-10-01', 'day', 'period_order'], ['2026-13-01', '2026-10-01', 'day', 'invalid_period'], ['2025-01-01', '2026-10-01', 'month', 'period_too_long'],
            ['2026-01-01', '2026-10-01', 'day', 'day_buckets_too_many'], ['2026-10-01', '2026-10-02', 'hour', 'granularity']] as [$from, $to, $granularity, $reason]) {
            try {
                $this->report($from, $to, $granularity);
                $this->fail('An invalid period was accepted.');
            } catch (ReportingException $error) {
                $this->assertSame($reason, $error->reason);
            }
        }
        $this->assertSame(3, OrderFinalization::count());
    }
}
