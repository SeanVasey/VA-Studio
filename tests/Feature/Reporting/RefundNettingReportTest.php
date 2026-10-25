<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Reporting\OperationalHealth;
use App\Domain\Reporting\SalesReport;
use App\Filament\Widgets\CommerceAttentionWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/**
 * Refunds are netted through the A5 ledger, status-aware: a refund that succeeded and then failed stops counting even
 * though its line allocation is retained. A raw sum of allocations would keep subtracting it.
 */
class RefundNettingReportTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private object $financial;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        R::configure();
        Queue::fake();
        [$this->payments, $this->financial] = R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
    }

    private function health(): array
    {
        return collect(app(OperationalHealth::class)->section('commerce', $this->admin)['metrics'])->keyBy('key')
            ->map(fn (array $metric) => $metric['value'])->all();
    }

    private function test(): array
    {
        return app(SalesReport::class)->build($this->admin, '2026-09-30', '2026-10-06', 'month')['test'];
    }

    public function test_succeeded_refunds_net_by_order_and_by_tier_and_a_later_failure_reverses_them_while_health_follows_the_ledger(): void
    {
        // The synthetic provider answers for the order created last, so the refunded order is created after the plain one.
        $b = ReportingFixtures::single($this->payments, 'B');
        $a = DeliveryFixtures::ready($this->payments, true);
        $paidA = $a['payment']->amount_minor;
        $gross = $paidA + $b['payment']->amount_minor;
        $totals = array_map(fn (array $line): int => $line['pricing']['total_minor'], $a['original']['lines']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $observe = fn () => R::observe($a['order']->public_id, $this->admin);

        // Nothing observed: every refund and dispute metric is a readable, ok zero.
        $this->assertSame(['payment_exceptions' => 0, 'payment_exceptions_flagged' => 0, 'refund_attention' => 0, 'refund_open_disputes' => 0, 'refund_lost_disputes' => 0,
            'refund_holds' => 0, 'refund_unallocated' => 0, 'refund_reversals' => 0], $this->health());
        $this->assertSame(0, $this->test()['currencies']['USD']['refunded_minor']);

        // 1. A succeeded partial refund of the exclusive line. Order-level netting is exact; no tier is guessed.
        R::provider($this->financial, [['re_REPORT', $totals[0], 'succeeded']]);
        $this->assertSame('partial', $observe()['refundState']);
        $report = $this->test();
        $this->assertSame(['orders' => 2, 'gross_minor' => $gross, 'refunded_minor' => $totals[0], 'net_minor' => $gross - $totals[0], 'exception_orders' => 0, 'exception_gross_minor' => 0],
            $report['currencies']['USD']);
        $this->assertSame([], array_filter(array_column($report['tiers']['rows'], 'refunded_minor')));
        $this->assertSame(['USD' => $totals[0]], $report['tiers']['unattributed_refund_minor']);
        $this->assertSame(1, $this->health()['refund_unallocated']);

        // 2. Staff allocate it to the exclusive line: that tier now carries the refund and nothing is unattributed.
        $refund = RefundDisputeObject::where('kind', 'refund')->sole();
        app(RefundDisputeActions::class)->allocate($a['order']->public_id, $this->admin, (string) Str::uuid(), R::sequence($a['order']->public_id, $this->admin),
            $refund->public_id, [0 => $totals[0]]);
        $report = $this->test();
        $this->assertSame($totals[0], $report['currencies']['USD']['refunded_minor']);
        $refundedTiers = array_values(array_filter($report['tiers']['rows'], fn (array $row): bool => $row['refunded_minor'] > 0));
        $this->assertCount(1, $refundedTiers);
        $this->assertSame(['exclusive', $totals[0], $totals[0] - $totals[0]], [$refundedTiers[0]['tier_type'], $refundedTiers[0]['refunded_minor'], $refundedTiers[0]['net_minor'] - ($refundedTiers[0]['paid_minor'] - $totals[0])]);
        $this->assertSame([], $report['tiers']['unattributed_refund_minor']);
        $this->assertSame($gross - $totals[0], array_sum(array_column($report['tiers']['rows'], 'net_minor')));
        $this->assertSame(0, $this->health()['refund_unallocated']);

        // 3. The provider now reports that refund as failed. The allocation row stays, but the refund no longer counts.
        R::provider($this->financial, [['re_REPORT', $totals[0], 'failed']]);
        $observe();
        $this->assertSame($totals[0], (int) RefundLineAllocation::sum('amount_minor'), 'The reversed refund keeps its allocation.');
        $report = $this->test();
        $this->assertSame(['refunded_minor' => 0, 'net_minor' => $gross], array_intersect_key($report['currencies']['USD'], ['refunded_minor' => 1, 'net_minor' => 1]));
        $this->assertSame([0, 0], [array_sum(array_column($report['tiers']['rows'], 'refunded_minor')), array_sum($report['tiers']['unattributed_refund_minor'])]);
        $this->assertSame($gross, array_sum(array_column($report['tiers']['rows'], 'net_minor')));
        $this->assertSame(1, $this->health()['refund_reversals']);

        // 3b. A second refund succeeds for the other line. The reversed refund's retained allocation must not leak into the
        // exclusive tier: only status-aware netting shows the exclusive tier at zero and the plain tier refunded.
        $refunds = [['re_REPORT', $totals[0], 'failed'], ['re_SECOND', $totals[1], 'succeeded']];
        R::provider($this->financial, $refunds);
        $observe();
        $second = RefundDisputeObject::where('kind', 'refund')->get()->first(fn (RefundDisputeObject $object): bool => $object->amount_minor === $totals[1]);
        app(RefundDisputeActions::class)->allocate($a['order']->public_id, $this->admin, (string) Str::uuid(), R::sequence($a['order']->public_id, $this->admin),
            $second->public_id, [1 => $totals[1]]);
        $this->assertSame($totals[0] + $totals[1], (int) RefundLineAllocation::sum('amount_minor'));
        $report = $this->test();
        $this->assertSame([$totals[1], $gross - $totals[1]], [$report['currencies']['USD']['refunded_minor'], $report['currencies']['USD']['net_minor']]);
        $byType = [];
        foreach ($report['tiers']['rows'] as $row) {
            $byType[$row['tier_type']] = ($byType[$row['tier_type']] ?? 0) + $row['refunded_minor'];
        }
        $this->assertSame(['exclusive' => 0, 'non-exclusive' => $totals[1]], $byType);
        $this->assertSame([], $report['tiers']['unattributed_refund_minor']);
        $this->assertSame($gross - $totals[1], array_sum(array_column($report['tiers']['rows'], 'net_minor')));

        // 4. Disputes: an open dispute holds downloads under the configured policy; a lost one is counted separately.
        R::provider($this->financial, $refunds, [['du_REPORT', $paidA, 'needs_response']]);
        $observe();
        $this->assertSame([1, 0, 1], [$this->health()['refund_open_disputes'], $this->health()['refund_lost_disputes'], $this->health()['refund_holds']]);
        R::provider($this->financial, $refunds, [['du_REPORT', $paidA, 'lost']]);
        $observe();
        $this->assertSame([0, 1], [$this->health()['refund_open_disputes'], $this->health()['refund_lost_disputes']]);
        // Evidence the state machine rejects (the refund amount changed) is attention, and never changes the accepted state.
        R::provider($this->financial, [['re_REPORT', $totals[0] + 1, 'failed'], $refunds[1]], [['du_REPORT', $paidA, 'lost']]);
        $this->assertSame('attention', $observe()['outcome']);
        $health = $this->health();
        $this->assertSame([1, 0, 1], [$health['refund_attention'], $health['refund_open_disputes'], $health['refund_lost_disputes']]);
        $this->assertSame($gross - $totals[1], $this->test()['currencies']['USD']['net_minor'], 'A rejected observation does not move the figures.');

        Livewire::test(CommerceAttentionWidget::class)->assertSee('Refund or dispute evidence needing attention')
            ->assertSeeHtml('data-metric="refund_reversals" data-state="attention"')->assertSeeHtml('data-metric="refund_lost_disputes" data-state="attention"');
    }
}
