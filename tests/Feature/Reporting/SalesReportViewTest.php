<?php

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\SalesReport;
use App\Filament\Pages\SalesReportPage;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * The tier section shows its warning counts and unattributed refunds whenever it is available, including when every paid
 * order is excluded from the tier table (Codex P2 r4418610931). The read model is replaced by a fixed report so the view
 * alone is under test; SalesReportTest and RefundNettingReportTest cover the figures themselves.
 */
class SalesReportViewTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function report(array $tiers): array
    {
        return ['period' => ['granularity' => 'month'], 'notes' => ['Synthetic note.'],
            'test' => ['available' => true, 'label' => SalesReport::ENVIRONMENTS['test'], 'reason' => null,
                'periods' => [['period' => '2026-10', 'currency' => 'USD', 'orders' => 2, 'gross_minor' => 5000, 'refunded_minor' => 1500, 'net_minor' => 3500,
                    'exception_orders' => 0, 'exception_gross_minor' => 0]],
                'currencies' => ['USD' => ['orders' => 2, 'gross_minor' => 5000, 'refunded_minor' => 1500, 'net_minor' => 3500, 'exception_orders' => 0, 'exception_gross_minor' => 0]],
                'tiers' => $tiers + ['available' => true, 'reason' => null],
                'funnel' => ['counts' => ['quotes_created' => 2, 'orders_prepared' => 2, 'paid' => 2, 'paid_exception' => 0, 'released_unpaid' => 0], 'conversion_bps' => 10000]],
            'production' => ['available' => false, 'reason' => 'Synthetic: not installed.', 'modes' => [], 'funnel' => []]];
    }

    private function serve(array $report): void
    {
        $this->app->instance(SalesReport::class, new class($report)
        {
            public function __construct(private readonly array $report) {}

            public function build(): array
            {
                return $this->report;
            }
        });
    }

    public function test_warnings_and_unattributed_refunds_render_when_every_paid_order_is_excluded_from_the_tier_table(): void
    {
        $this->withoutVite();
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);

        // Every paid order is excluded: one failed pricing verification, one refunded order could not be read.
        $this->serve($this->report(['rows' => [], 'unattributed_refund_minor' => ['USD' => 1500], 'unverified_orders' => 2, 'unreadable_refund_orders' => 1]));
        Livewire::test(SalesReportPage::class)
            ->assertDontSee('Test lines by license tier')
            ->assertSee('Succeeded refunds not attributed to a tier (USD): 1500 minor units')
            ->assertSee('2 paid order(s) are in the totals above but not in the tier table')
            ->assertSee('1 refunded order(s) could not be read for tier refunds');

        // With rows the table is shown as well, alongside the same warnings.
        $row = ['tier' => 'Synthetic Lease', 'tier_type' => 'non-exclusive', 'currency' => 'USD', 'lines' => 1, 'base_minor' => 2500, 'discount_minor' => 0,
            'tax_minor' => 0, 'paid_minor' => 2500, 'refunded_minor' => 0, 'net_minor' => 2500];
        $this->serve($this->report(['rows' => [$row], 'unattributed_refund_minor' => ['USD' => 1500], 'unverified_orders' => 1, 'unreadable_refund_orders' => 0]));
        Livewire::test(SalesReportPage::class)
            ->assertSee('Test lines by license tier')->assertSee('Synthetic Lease')
            ->assertSee('Succeeded refunds not attributed to a tier (USD): 1500 minor units')
            ->assertSee('1 paid order(s) are in the totals above but not in the tier table')
            ->assertDontSee('could not be read for tier refunds');

        // An unavailable tier section shows its reason and no warning figures.
        $this->serve($this->report(['available' => false, 'reason' => 'Synthetic cap reached.', 'rows' => [], 'unattributed_refund_minor' => [], 'unverified_orders' => 0,
            'unreadable_refund_orders' => 0]));
        Livewire::test(SalesReportPage::class)->assertSee('License tier breakdown unavailable: Synthetic cap reached.')
            ->assertDontSee('Succeeded refunds not attributed');
    }
}
