<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\RefundDispute\RefundDisputeSource;
use App\Domain\Commerce\RefundDispute\StaffCommerceDirectory;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Reporting\OperationalHealth;
use App\Domain\Reporting\ReportingQueries;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

/**
 * BL-42 follow-up (P2-2): the ledger holds two chains whose order numbers overlap, so every reader of the test chain filters on
 * `order_source`. Each filter is exercised here by planting production tax rows under the SAME order number as a test order,
 * shaped so that dropping any single `order_source` predicate changes a value asserted below (the sequences, states, holds,
 * allocations and effects differ from the test chain's own, and the planted sequences straddle the test chain's).
 */
class SourceFilteredReadersTest extends TestCase
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
        $this->travelTo(now()->startOfSecond());
        // Operator review records an open dispute without placing a hold, so the test chain itself has no hold or reversal.
        R::configure(['dispute_access' => 'operator_review']);
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

    /** One test order with its own ledger: sequence 1 observed (partial refund, open dispute), sequence 2 attention. */
    private function ownChain(): array
    {
        $f = DeliveryFixtures::ready($this->payments, true);
        $id = $f['order']->public_id;
        R::provider($this->financial, [['re_TESTCHAIN', 100, 'succeeded']], [['du_TESTCHAIN', 200, 'needs_response']]);
        $this->assertSame('observed', R::observe($id, $this->admin)['outcome']);
        R::provider($this->financial, [['re_TESTCHAIN', 100, 'succeeded', 'eur']]);
        $this->assertSame('attention', R::observe($id, $this->admin)['outcome']);

        return $f;
    }

    /** Production tax rows that carry the test order's own number, with sequences below, equal to and above the test chain's. */
    private function plantProductionChain(array $f): void
    {
        $order = $f['order']->id;
        $template = (array) DB::table('refund_dispute_observations')->where('order_source', 'test')->where('order_id', $order)->where('sequence', 1)->first();
        unset($template['id']);
        $now = now();
        $observation = function (int $sequence, string $outcome, array $states = []) use ($template, $order): int {
            return (int) DB::table('refund_dispute_observations')->insertGetId(array_replace($template, [
                'public_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'order_id' => $order, 'order_source' => 'production_tax',
                'sequence' => $sequence, 'outcome' => $outcome,
                'refund_state' => null, 'dispute_state' => null, 'succeeded_refund_minor' => null, 'pending_refund_minor' => null], $states));
        };
        $full = ['refund_state' => 'full', 'dispute_state' => 'lost', 'succeeded_refund_minor' => $template['paid_minor'], 'pending_refund_minor' => 0];
        $clean = ['refund_state' => 'none', 'dispute_state' => 'none', 'succeeded_refund_minor' => 0, 'pending_refund_minor' => 0];
        $first = $observation(1, 'observed', $full);
        $observation(2, 'attention');
        $observation(9, 'observed', $clean);
        $effect = fn (string $key, string $kind): int => (int) DB::table('refund_dispute_effects')->insertGetId(['public_id' => (string) Str::uuid(), 'order_id' => $order,
            'order_source' => 'production_tax', 'sequence' => 1, 'effect_key' => $key, 'kind' => $kind, 'policy_hash' => str_repeat('a', 64), 'context' => '{}', 'created_at' => $now]);
        $hold = $effect('planted-hold', 'dispute_hold_applied');
        $effect('planted-reversal', 'refund_reversed_review_required');
        DB::table('refund_dispute_holds')->insert(['public_id' => (string) Str::uuid(), 'order_id' => $order, 'order_source' => 'production_tax', 'license_grant_id' => null,
            'reason' => 'dispute_reconciling', 'applied_effect_id' => $hold, 'created_at' => $now]);
        $object = (int) DB::table('refund_dispute_objects')->insertGetId(['public_id' => (string) Str::uuid(), 'order_id' => $order, 'order_source' => 'production_tax',
            'kind' => 'refund', 'object_hash' => str_repeat('b', 64), 'amount_minor' => 100, 'currency' => 'USD', 'first_observation_id' => $first, 'created_at' => $now]);
        // Allocates everything the test chain's succeeded refund would need; if it leaked, the test order would no longer be unallocated.
        DB::table('refund_line_allocations')->insert(['public_id' => (string) Str::uuid(), 'order_id' => $order, 'order_source' => 'production_tax',
            'refund_dispute_object_id' => $object, 'order_line_id' => 1, 'amount_minor' => 100, 'currency' => 'USD', 'source' => 'staff',
            'actor_id' => $this->admin->id, 'sequence' => 1, 'created_at' => $now]);
    }

    public function test_every_test_chain_reader_ignores_production_rows_that_share_its_order_number(): void
    {
        $f = $this->ownChain();
        $order = $f['order']->id;
        $own = $this->health();
        // The test chain's own picture, before anything is planted: latest observed is sequence 1 (partial refund, open dispute),
        // the later attention is the newest entry, nothing is held, allocated or reversed.
        $this->assertSame(['refund_attention' => 1, 'refund_open_disputes' => 1, 'refund_lost_disputes' => 0, 'refund_holds' => 0, 'refund_unallocated' => 1,
            'refund_reversals' => 0], array_intersect_key($own, array_flip(['refund_attention', 'refund_open_disputes', 'refund_lost_disputes', 'refund_holds',
                'refund_unallocated', 'refund_reversals'])));
        $directory = app(StaffCommerceDirectory::class);
        $listed = fn () => (array) $directory->orders()->where('order_finalizations.id', $f['finalization']->id)->first()->getAttributes();
        $before = $listed();
        $detail = $directory->orderFor($f['finalization']->id, $this->admin);
        $customers = (array) $directory->customers()->first()->getAttributes();
        $joined = fn () => DB::table('order_finalizations as f')->leftJoin('refund_dispute_observations as r', ReportingQueries::latestObserved(...))
            ->where('f.order_id', $order)->get(['r.id', 'r.order_source', 'r.sequence', 'r.refund_state', 'r.dispute_state'])->map(fn ($row): array => (array) $row)->all();
        $ownJoin = $joined();
        $this->assertCount(1, $ownJoin);
        $this->assertSame(['test', 1, 'partial', 'open'], [$ownJoin[0]['order_source'], $ownJoin[0]['sequence'], $ownJoin[0]['refund_state'], $ownJoin[0]['dispute_state']]);
        $this->assertSame(['partial', 'open', 0], [$before['refund_state'], $before['dispute_state'], (int) $before['active_holds']]);
        $this->assertSame(0, (int) $customers['active_holds']);

        $this->plantProductionChain($f);

        // ReportingQueries::latestObserved: exactly the test chain's row, once.
        $this->assertSame($ownJoin, $joined());
        // OperationalHealth: every metric unchanged.
        $this->assertSame($own, $this->health());
        // StaffCommerceDirectory: list row, customer row and the order detail are the test chain's alone.
        $this->assertSame($before, $listed());
        $this->assertSame(0, (int) ((array) $directory->customers()->first()->getAttributes())['active_holds']);
        $after = $directory->orderFor($f['finalization']->id, $this->admin);
        $this->assertSame($detail, $after);
        $this->assertSame([], $after['holds']);
        // The newest entry is the test chain's own attention (sequence 2), not a planted production sequence 9.
        $this->assertSame([2, 'attention'], [$after['latestObservation']['sequence'] ?? null, $after['latestObservation']['outcome'] ?? null]);
        $this->assertEqualsCanonicalizing(['refund', 'dispute'], array_column($after['providerObjects'], 'kind'));
        $this->assertNotContains('refund_reversed_review_required', array_column($after['effects'], 'kind'));
        // The planted rows are still there and untouched by any reader.
        $this->assertSame(3, DB::table('refund_dispute_observations')->where('order_source', RefundDisputeSource::PRODUCTION_TAX)->count());
    }
}
