<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeEffect;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Commerce\RefundDispute\RefundDisputeLedger;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

class RefundDisputeLifecycleTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private object $financial;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        R::configure();
        Queue::fake();
        [$this->payments, $this->financial] = R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        $this->staff = LicenseFixtures::admin();
    }

    private function order(bool $mixed = false): array
    {
        $fixture = DeliveryFixtures::ready($this->payments, $mixed);
        $fixture['paid'] = $fixture['payment']->amount_minor;
        $fixture['lines'] = $fixture['original']['lines'];

        return $fixture;
    }

    private function refused(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('The refund/dispute operation unexpectedly succeeded.');
        } catch (RefundDisputeException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    private function deliveryRefused(callable $action): void
    {
        try {
            $action();
            $this->fail('Delivery unexpectedly succeeded.');
        } catch (DeliveryException $error) {
            $this->assertSame('blocked', $error->reason);
        }
    }

    private function issue(array $f, LicenseGrant $grant, string $kind = 'contract')
    {
        return app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $grant->public_id, $kind, (string) Str::uuid());
    }

    private function sequence(array $f): int
    {
        return R::sequence($f['order']->public_id, $this->staff);
    }

    private function act(string $method, array $f, ...$arguments): array
    {
        return app(RefundDisputeActions::class)->{$method}($f['order']->public_id, $this->staff, (string) Str::uuid(), $this->sequence($f), ...$arguments);
    }

    public function test_every_write_refuses_while_disabled_or_any_policy_value_is_unset_or_unknown(): void
    {
        $f = $this->order();
        R::provider($this->financial, [['re_FULL', $f['paid'], 'succeeded']]);
        $cases = [
            'disabled' => fn () => config(['refund-dispute.enabled' => false]),
            'string flag' => fn () => config(['refund-dispute.enabled' => 'true']),
            'policy missing' => fn () => config(['refund-dispute.policy' => null]),
        ];
        foreach (array_keys(R::POLICY) as $key) {
            $cases[$key.' unset'] = fn () => config(['refund-dispute.policy.'.$key => null]);
            $cases[$key.' empty'] = fn () => config(['refund-dispute.policy.'.$key => '']);
            $cases[$key.' unknown'] = fn () => config(['refund-dispute.policy.'.$key => 'revoke_license']);
        }
        $cases['extra key'] = fn () => config(['refund-dispute.policy.refund_window_days' => '14']);
        $cases['production'] = fn () => $this->app['env'] = 'production';
        $cases['live mode'] = fn () => config(['payments.stripe.mode' => 'live']);
        $before = R::counts();
        $originals = R::originals();
        foreach ($cases as $name => $change) {
            R::configure();
            $this->app['env'] = 'testing';
            $change();
            $expected = str_contains($name, 'unknown') || $name === 'extra key' ? 'policy_unknown'
                : (str_contains($name, 'unset') || str_contains($name, 'empty') || $name === 'policy missing' ? 'policy_unset' : 'disabled');
            $this->refused($expected, fn () => app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, (string) Str::uuid(), 0));
            $this->refused($expected, fn () => app(RefundDisputeActions::class)->placeDisputeHold($f['order']->public_id, $this->staff, (string) Str::uuid(), 0));
        }
        $this->app['env'] = 'testing';
        $this->assertSame([], $this->financial->calls);
        $this->assertSame($before, R::counts());
        $this->assertSame($originals, R::originals());
    }

    public function test_full_refund_allocates_each_line_exactly_and_records_review_without_changing_access_or_originals(): void
    {
        $f = $this->order(true);
        $originals = R::originals();
        $grants = LicenseGrant::where('order_finalization_id', $f['finalization']->id)->orderBy('order_line_id')->get();
        $this->assertCount(2, $grants);
        R::provider($this->financial, [['re_FIRST', 1000, 'succeeded'], ['re_REST', $f['paid'] - 1000, 'succeeded']]);
        $result = R::observe($f['order']->public_id, $this->staff);
        $this->assertSame(['observed', 'full', 'none', $f['paid'], 0], [$result['outcome'], $result['refundState'], $result['disputeState'],
            $result['succeededRefundMinor'], $result['pendingRefundMinor']]);
        $this->assertSame(0, collect($this->financial->calls)->max('transaction_level'));
        foreach ($f['lines'] as $position => $line) {
            $lineId = $f['order']->lines()->where('position', $position)->value('id');
            $this->assertSame($line['pricing']['total_minor'], (int) RefundLineAllocation::where('order_line_id', $lineId)->sum('amount_minor'));
        }
        $this->assertSame($f['paid'], (int) RefundLineAllocation::sum('amount_minor'));
        $this->assertSame(['automatic_full_refund'], RefundLineAllocation::distinct()->pluck('source')->all());
        $kinds = RefundDisputeEffect::orderBy('id')->pluck('kind')->all();
        $this->assertSame(2, count(array_keys($kinds, 'allocation_recorded', true)));
        $this->assertSame(2, count(array_keys($kinds, 'line_refunded_review_required', true)));
        $this->assertSame(1, count(array_keys($kinds, 'exclusive_reopening_review_required', true)));
        $this->assertSame(0, RefundDisputeHold::count());
        // authoritative_provider_operator_review never changes access automatically.
        foreach ($grants as $grant) {
            $this->assertNotEmpty($this->issue($f, $grant)->authorizationId);
        }
        $this->assertSame($originals, R::originals());
        $this->assertSame(1, ExclusiveSale::count());
    }

    private function totals(array $f): array
    {
        return array_map(fn (array $line): int => $line['pricing']['total_minor'], $f['lines']);
    }

    private function grant(array $f, int $position): LicenseGrant
    {
        return LicenseGrant::where('order_line_id', $f['order']->lines()->where('position', $position)->value('id'))->sole();
    }

    public function test_partial_refund_on_a_multi_line_order_needs_an_exact_staff_allocation_with_idempotent_replay(): void
    {
        $f = $this->order(true);
        $originals = R::originals();
        $totals = $this->totals($f);
        $this->assertSame($f['order']->lines()->where('position', 0)->value('id'), ExclusiveSale::sole()->order_line_id);
        $amount = $totals[0] + 100;
        R::provider($this->financial, [['re_PART', $amount, 'succeeded']]);
        $this->assertSame('partial', R::observe($f['order']->public_id, $this->staff)['refundState']);
        $this->assertSame(0, RefundLineAllocation::count());
        $required = RefundDisputeEffect::where('kind', 'allocation_required')->sole();
        $refund = RefundDisputeObject::where('kind', 'refund')->sole();
        $this->assertSame($refund->id, $required->refund_dispute_object_id);
        foreach ([[0 => $totals[0], 1 => 99], [0 => $totals[0], 1 => 101], [0 => $amount - 1], [0 => $totals[0], 5 => 100], [0 => $amount], [0 => $totals[0] + 100, 1 => 0], ['0' => $amount], [0 => -100, 1 => $amount + 100]] as $lines) {
            try {
                $this->act('allocate', $f, $refund->public_id, $lines);
                $this->fail('An inexact allocation was accepted.');
            } catch (RefundDisputeException $error) {
                $this->assertContains($error->reason, ['invalid']);
            }
        }
        $this->assertSame(0, RefundLineAllocation::count());
        $request = (string) Str::uuid();
        $sequence = $this->sequence($f);
        $result = app(RefundDisputeActions::class)->allocate($f['order']->public_id, $this->staff, $request, $sequence, $refund->public_id, [1 => 100, 0 => $totals[0]]);
        $this->assertFalse($result['replayed']);
        $this->assertSame(['allocation_recorded', 'line_refunded_review_required', 'exclusive_reopening_review_required'], array_column($result['effects'], 'kind'));
        $this->assertSame([$totals[0], 100], RefundLineAllocation::orderBy('order_line_id')->pluck('amount_minor')->all());
        $this->assertSame(['staff'], RefundLineAllocation::distinct()->pluck('source')->all());
        $this->assertSame([$this->staff->id], RefundLineAllocation::distinct()->pluck('actor_id')->all());
        $counts = R::counts();
        $replay = app(RefundDisputeActions::class)->allocate($f['order']->public_id, $this->staff, $request, $sequence, $refund->public_id, [0 => $totals[0], 1 => 100]);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($result['effects'], $replay['effects']);
        $this->refused('conflict', fn () => app(RefundDisputeActions::class)->allocate($f['order']->public_id, $this->staff, $request, $sequence, $refund->public_id, [0 => $totals[0] - 1, 1 => 101]));
        $this->refused('stale', fn () => app(RefundDisputeActions::class)->allocate($f['order']->public_id, $this->staff, (string) Str::uuid(), $sequence, $refund->public_id, [0 => $amount - 100, 1 => 100]));
        $this->refused('already_applied', fn () => $this->act('allocate', $f, $refund->public_id, [0 => $amount - 100, 1 => 100]));
        $this->assertSame($counts, R::counts());
        // A partially refunded line keeps access and cannot be suspended; the fully refunded line can.
        $this->refused('not_qualified', fn () => $this->act('suspendLine', $f, $this->grant($f, 1)->public_id));
        $this->act('suspendLine', $f, $this->grant($f, 0)->public_id);
        $this->deliveryRefused(fn () => $this->issue($f, $this->grant($f, 0)));
        $this->assertNotEmpty($this->issue($f, $this->grant($f, 1))->authorizationId);
        $this->assertSame($originals, R::originals());
    }

    public function test_partial_refunds_summing_past_the_paid_amount_and_currency_mismatch_are_attention_without_effects(): void
    {
        $f = $this->order(true);
        $originals = R::originals();
        R::provider($this->financial, [['re_A', $f['paid'] - 100, 'succeeded'], ['re_B', 200, 'pending']]);
        $result = R::observe($f['order']->public_id, $this->staff);
        $this->assertSame(['attention', null, null], [$result['outcome'], $result['refundState'], $result['disputeState']]);
        $this->assertSame('refunds_exceed_paid', RefundDisputeEffect::where('kind', 'observation_attention')->sole()->context['reason']);
        R::provider($this->financial, [['re_EUR', 100, 'succeeded', 'eur']]);
        $this->assertSame('attention', R::observe($f['order']->public_id, $this->staff)['outcome']);
        R::provider($this->financial, [['re_OVER', $f['paid'] + 1, 'succeeded']], [], ['amount_refunded' => $f['paid'], 'refunded' => true]);
        $this->assertSame('attention', R::observe($f['order']->public_id, $this->staff)['outcome']);
        $this->assertSame(['refund_dispute_work' => 1, 'refund_dispute_observations' => 3, 'refund_dispute_objects' => 0, 'refund_dispute_statuses' => 0,
            'refund_line_allocations' => 0, 'refund_dispute_effects' => 3, 'refund_dispute_holds' => 0], R::counts());
        $this->refused('not_observed', fn () => $this->act('placeDisputeHold', $f));
        $this->assertSame($originals, R::originals());
    }

    public function test_replayed_and_duplicated_provider_observations_add_no_events_or_effects(): void
    {
        $f = $this->order();
        R::provider($this->financial, [['re_ONE', 500, 'pending']]);
        $request = (string) Str::uuid();
        $first = R::observe($f['order']->public_id, $this->staff, $request);
        $calls = count($this->financial->calls);
        $replay = app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, $request, 0);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(array_diff_key($first, ['replayed' => 1]), array_diff_key($replay, ['replayed' => 1]));
        $this->assertSame($calls, count($this->financial->calls));
        $this->refused('conflict', fn () => app(RefundDisputeLedger::class)->observe($f['order']->public_id, LicenseFixtures::admin(), $request, 0));
        R::observe($f['order']->public_id, $this->staff);
        $this->assertSame([1, 1, 0, 0], [RefundDisputeObject::count(), DB::table('refund_dispute_statuses')->count(), RefundLineAllocation::count(), RefundDisputeEffect::count()]);
        R::provider($this->financial, [['re_ONE', 500, 'succeeded']]);
        R::observe($f['order']->public_id, $this->staff);
        $after = R::counts();
        R::observe($f['order']->public_id, $this->staff);
        R::observe($f['order']->public_id, $this->staff);
        $this->assertSame([1, 2, 1], [RefundDisputeObject::count(), DB::table('refund_dispute_statuses')->count(), RefundLineAllocation::count()]);
        $this->assertSame('automatic_single_line', RefundLineAllocation::sole()->source);
        $this->assertSame($after['refund_dispute_effects'], RefundDisputeEffect::count());
        $this->assertSame($after['refund_dispute_observations'] + 2, RefundDisputeObservation::count());
        $this->assertSame(range(1, 5), RefundDisputeObservation::orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_dispute_before_refund_and_refund_after_dispute_won_follow_the_reconciling_hold_policy(): void
    {
        $f = $this->order();
        $grant = $f['grant'];
        R::provider($this->financial, [], [['du_ONE', $f['paid'], 'needs_response']]);
        $this->assertSame('open', R::observe($f['order']->public_id, $this->staff)['disputeState']);
        $hold = RefundDisputeHold::sole();
        $this->assertSame(['dispute_reconciling', null, null], [$hold->reason, $hold->license_grant_id, $hold->released_at]);
        $this->deliveryRefused(fn () => $this->issue($f, $grant));
        $this->refused('reconciling', fn () => $this->act('releaseHold', $f, $hold->public_id));
        $this->refused('policy_forbids', fn () => $this->act('placeDisputeHold', $f));
        R::provider($this->financial, [['re_PART', 1000, 'succeeded']], [['du_ONE', $f['paid'], 'under_review']]);
        R::observe($f['order']->public_id, $this->staff);
        $this->assertSame(1, RefundDisputeHold::count());
        $this->assertSame(1000, (int) RefundLineAllocation::sum('amount_minor'));
        R::provider($this->financial, [['re_PART', 1000, 'succeeded']], [['du_ONE', $f['paid'], 'won']]);
        $this->assertSame('won', R::observe($f['order']->public_id, $this->staff)['disputeState']);
        $this->assertNotNull($hold->fresh()->released_at);
        $this->assertSame('dispute_hold_released', RefundDisputeEffect::find($hold->fresh()->released_effect_id)->kind);
        $this->assertNotEmpty($this->issue($f, $grant)->authorizationId);
        R::provider($this->financial, [['re_PART', 1000, 'succeeded'], ['re_LATE', $f['paid'] - 1000, 'succeeded']], [['du_ONE', $f['paid'], 'won']]);
        $this->assertSame('full', R::observe($f['order']->public_id, $this->staff)['refundState']);
        $this->assertSame($f['paid'], (int) RefundLineAllocation::sum('amount_minor'));
        $this->assertSame(1, RefundDisputeEffect::where('kind', 'line_refunded_review_required')->count());
        $this->assertSame(0, RefundDisputeHold::whereNull('released_at')->count());
        // A second dispute after the release blocks again with a new hold.
        R::provider($this->financial, [['re_PART', 1000, 'succeeded'], ['re_LATE', $f['paid'] - 1000, 'succeeded']],
            [['du_ONE', $f['paid'], 'won'], ['du_TWO', 100, 'warning_needs_response']]);
        R::observe($f['order']->public_id, $this->staff);
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
        $this->assertSame(2, RefundDisputeHold::count());
    }

    public function test_impossible_provider_changes_are_attention_and_never_release_a_hold(): void
    {
        $f = $this->order();
        R::provider($this->financial, [['re_ONE', 600, 'succeeded']], [['du_ONE', $f['paid'], 'needs_response']]);
        R::observe($f['order']->public_id, $this->staff);
        R::provider($this->financial, [['re_ONE', 600, 'succeeded']], [['du_ONE', $f['paid'], 'lost']]);
        R::observe($f['order']->public_id, $this->staff);
        $baseline = R::counts();
        foreach ([
            'status_regressed' => [[['re_ONE', 600, 'succeeded']], [['du_ONE', $f['paid'], 'needs_response']]],
            'object_vanished' => [[], [['du_ONE', $f['paid'], 'lost']]],
            'amount_changed' => [[['re_ONE', 601, 'succeeded']], [['du_ONE', $f['paid'], 'lost']]],
            'refund_regressed' => [[['re_ONE', 600, 'pending']], [['du_ONE', $f['paid'], 'lost']]],
        ] as $reason => [$refunds, $disputes]) {
            R::provider($this->financial, $refunds, $disputes);
            $this->assertSame('attention', R::observe($f['order']->public_id, $this->staff)['outcome'], $reason);
        }
        $this->assertSame(['status_regressed', 'object_vanished', 'amount_changed', 'status_regressed'],
            RefundDisputeEffect::where('kind', 'observation_attention')->orderBy('id')->get()->map(fn ($e) => $e->context['reason'])->all());
        $this->assertSame($baseline['refund_dispute_objects'], RefundDisputeObject::count());
        $this->assertSame($baseline['refund_dispute_holds'], RefundDisputeHold::count());
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
        $this->refused('not_observed', fn () => $this->act('suspendLine', $f, $f['grant']->public_id));
        // A succeeded refund that later fails is accepted, flagged and leaves its allocation and holds for review.
        R::provider($this->financial, [['re_ONE', 600, 'failed']], [['du_ONE', $f['paid'], 'lost']]);
        $this->assertSame('observed', R::observe($f['order']->public_id, $this->staff)['outcome']);
        $this->assertSame(1, RefundDisputeEffect::where('kind', 'refund_reversed_review_required')->count());
        $this->assertSame(1, RefundLineAllocation::count());
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
    }

    public function test_lost_dispute_keeps_the_hold_and_permits_suspension_and_a_qualified_exclusive_decision_only(): void
    {
        $f = $this->order();
        $originals = R::originals();
        R::provider($this->financial, [], [['du_ONE', $f['paid'], 'needs_response']]);
        R::observe($f['order']->public_id, $this->staff);
        $this->refused('not_qualified', fn () => $this->act('qualifyExclusiveReopening', $f, $f['grant']->public_id));
        R::provider($this->financial, [], [['du_ONE', $f['paid'], 'lost']]);
        $this->assertSame('lost', R::observe($f['order']->public_id, $this->staff)['disputeState']);
        $reconciling = RefundDisputeHold::where('reason', 'dispute_reconciling')->sole();
        $this->assertNull($reconciling->released_at);
        $this->assertSame(['dispute_hold_applied', 'dispute_lost_review_required', 'exclusive_reopening_review_required'],
            RefundDisputeEffect::orderBy('id')->pluck('kind')->all());
        $this->act('suspendLine', $f, $f['grant']->public_id);
        $this->refused('already_applied', fn () => $this->act('suspendLine', $f, $f['grant']->public_id));
        $decision = $this->act('qualifyExclusiveReopening', $f, $f['grant']->public_id);
        $this->assertSame('exclusive_reopening_qualified', $decision['effects'][0]['kind']);
        $effect = RefundDisputeEffect::where('kind', 'exclusive_reopening_qualified')->sole();
        $this->assertSame(['retained', false, 'u08_owner_decision_required', 'dispute_lost'], [$effect->context['exclusive_sale'],
            $effect->context['catalog_availability_changed'], $effect->context['availability_decision'], $effect->context['cause']]);
        $this->refused('already_applied', fn () => $this->act('qualifyExclusiveReopening', $f, $f['grant']->public_id));
        $this->act('releaseHold', $f, $reconciling->public_id);
        $this->deliveryRefused(fn () => $this->issue($f, $f['grant']));
        $this->assertSame(1, ExclusiveSale::count());
        $this->assertSame($originals, R::originals());
    }

    public function test_operator_review_dispute_policy_records_review_and_lets_staff_place_and_release_a_hold(): void
    {
        R::configure(['dispute_access' => 'operator_review']);
        $f = $this->order();
        R::provider($this->financial, [], [['du_ONE', $f['paid'], 'warning_needs_response']]);
        R::observe($f['order']->public_id, $this->staff);
        $this->assertSame(0, RefundDisputeHold::count());
        $this->assertSame(['dispute_review_required'], RefundDisputeEffect::pluck('kind')->all());
        $this->assertNotEmpty($this->issue($f, $f['grant'])->authorizationId);
        $this->act('placeDisputeHold', $f);
        $this->refused('already_applied', fn () => $this->act('placeDisputeHold', $f));
        $hold = RefundDisputeHold::sole();
        $this->assertSame('dispute_staff_hold', $hold->reason);
        $this->deliveryRefused(fn () => $this->issue($f, $f['grant']));
        $this->act('releaseHold', $f, $hold->public_id);
        $this->refused('not_found', fn () => $this->act('releaseHold', $f, $hold->public_id));
        $this->assertNotEmpty($this->issue($f, $f['grant'])->authorizationId);
        R::provider($this->financial, [], [['du_ONE', $f['paid'], 'warning_closed']]);
        R::observe($f['order']->public_id, $this->staff);
        $this->refused('not_qualified', fn () => $this->act('placeDisputeHold', $f));
    }

    public function test_remain_sold_records_the_retained_sale_and_refuses_a_reopening_decision(): void
    {
        R::configure(['exclusive_reopening' => 'remain_sold']);
        $f = $this->order();
        R::provider($this->financial, [['re_FULL', $f['paid'], 'succeeded']]);
        R::observe($f['order']->public_id, $this->staff);
        $this->assertSame(['allocation_recorded', 'line_refunded_review_required', 'exclusive_remains_sold'], RefundDisputeEffect::orderBy('id')->pluck('kind')->all());
        $this->act('suspendLine', $f, $f['grant']->public_id);
        $this->refused('policy_forbids', fn () => $this->act('qualifyExclusiveReopening', $f, $f['grant']->public_id));
        $this->assertSame(1, ExclusiveSale::count());
    }

    public function test_an_exclusive_refund_leaves_the_order_s_non_exclusive_grant_and_its_downloads_untouched(): void
    {
        $f = $this->order(true);
        $originals = R::originals();
        $totals = $this->totals($f);
        R::provider($this->financial, [['re_EXCL', $totals[0], 'succeeded']]);
        R::observe($f['order']->public_id, $this->staff);
        $refund = RefundDisputeObject::where('kind', 'refund')->sole();
        $this->act('allocate', $f, $refund->public_id, [0 => $totals[0]]);
        // A reopening decision needs the refunded exclusive grant's downloads to be held first.
        $this->refused('not_qualified', fn () => $this->act('qualifyExclusiveReopening', $f, $this->grant($f, 0)->public_id));
        $this->act('suspendLine', $f, $this->grant($f, 0)->public_id);
        $decision = $this->act('qualifyExclusiveReopening', $f, $this->grant($f, 0)->public_id);
        $this->assertSame('exclusive_reopening_qualified', $decision['effects'][0]['kind']);
        $this->assertSame([], RefundDisputeEffect::where('kind', 'exclusive_reopening_qualified')->sole()->context['retained_grants']);
        $this->refused('not_qualified', fn () => $this->act('qualifyExclusiveReopening', $f, $this->grant($f, 1)->public_id));
        $this->assertSame(0, RefundDisputeEffect::where('license_grant_id', $this->grant($f, 1)->id)->count());
        $this->deliveryRefused(fn () => $this->issue($f, $this->grant($f, 0)));
        $this->assertNotEmpty($this->issue($f, $this->grant($f, 1))->authorizationId);
        $this->assertSame($originals, R::originals());
    }

    public function test_a_hold_refuses_redemption_of_an_authorization_issued_before_it(): void
    {
        $f = $this->order();
        $issued = $this->issue($f, $f['grant']);
        R::provider($this->financial, [['re_FULL', $f['paid'], 'succeeded']]);
        R::observe($f['order']->public_id, $this->staff);
        $this->act('suspendLine', $f, $f['grant']->public_id);
        $this->deliveryRefused(fn () => app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token()));
        $this->assertSame(0, DB::table('test_delivery_redemptions')->count());
        $hold = RefundDisputeHold::sole();
        $this->act('releaseHold', $f, $hold->public_id);
        $stream = app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token());
        $this->assertGreaterThan(0, $stream->sizeBytes);
        $stream->close();
    }

    public function test_staff_without_authority_or_required_mfa_are_refused_on_reads_and_writes(): void
    {
        $f = $this->order();
        R::provider($this->financial, [['re_FULL', $f['paid'], 'succeeded']]);
        $customer = User::factory()->create();
        $operations = fn (User $actor): array => [
            fn () => app(RefundDisputeLedger::class)->review($f['order']->public_id, $actor),
            fn () => app(RefundDisputeLedger::class)->observe($f['order']->public_id, $actor, (string) Str::uuid(), 0),
            fn () => app(RefundDisputeActions::class)->suspendLine($f['order']->public_id, $actor, (string) Str::uuid(), 0, $f['grant']->public_id),
        ];
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        foreach ([$customer, $this->staff] as $actor) {
            foreach ($operations($actor) as $operation) {
                try {
                    $operation();
                    $this->fail('Unauthorized refund/dispute access succeeded.');
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(array_fill_keys(R::TABLES, 0), R::counts());
        $this->staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $result = R::observe($f['order']->public_id, $this->staff->fresh());
        $this->assertSame('full', $result['refundState']);
        $this->staff->saveAppAuthenticationSecret(null);
        try {
            app(RefundDisputeActions::class)->suspendLine($f['order']->public_id, $this->staff->fresh(), (string) Str::uuid(), 1, $f['grant']->public_id);
            $this->fail('Withdrawn MFA enrollment still admitted a write.');
        } catch (AuthorizationException) {
            $this->assertSame(0, RefundDisputeHold::count());
        }
    }

    public function test_every_write_is_audited_and_provider_identifiers_stay_encrypted(): void
    {
        $f = $this->order();
        R::provider($this->financial, [['re_SECRETREFUND', $f['paid'], 'succeeded']], [['du_SECRETDISPUTE', $f['paid'], 'lost']]);
        $result = R::observe($f['order']->public_id, $this->staff);
        $this->act('suspendLine', $f, $f['grant']->public_id);
        $observation = RefundDisputeObservation::sole();
        $this->assertSame(hash('sha256', $observation->evidence_ciphertext), $observation->evidence_hash);
        $plain = Crypt::decryptString($observation->evidence_ciphertext);
        $this->assertStringContainsString('re_SECRETREFUND', $plain);
        $this->assertStringNotContainsString('private-financial@example.test', $plain);
        $public = json_encode([$result, RefundDisputeEffect::all()->toArray(), RefundDisputeObject::all()->toArray(),
            AuditEvent::where('action', 'like', 'commerce.refund_dispute.%')->get()->toArray(), RefundDisputeObservation::all()->toArray()], JSON_THROW_ON_ERROR);
        foreach (['re_SECRETREFUND', 'du_SECRETDISPUTE', 'private-financial', 'pi_SYNTHETIC', 'ch_SYNTHETIC', $observation->evidence_ciphertext] as $private) {
            $this->assertStringNotContainsString($private, $public);
        }
        $audits = AuditEvent::where('action', 'like', 'commerce.refund_dispute.%')->orderBy('id')->get();
        $this->assertSame(['commerce.refund_dispute.observed', 'commerce.refund_dispute.allocation_recorded',
            'commerce.refund_dispute.line_refunded_review_required', 'commerce.refund_dispute.exclusive_reopening_review_required',
            'commerce.refund_dispute.dispute_lost_review_required', 'commerce.refund_dispute.refund_suspension_applied'], $audits->pluck('action')->all());
        $this->assertSame($this->staff->id, $audits->first()->actor_id);
        $this->assertSame($this->staff->id, $audits->last()->actor_id);
        foreach (RefundDisputeEffect::all() as $effect) {
            $this->assertTrue($effect->context['test_only']);
        }
    }

    public function test_stale_busy_and_uncertain_commits_never_duplicate_an_observation(): void
    {
        $f = $this->order();
        R::provider($this->financial, [['re_FULL', $f['paid'], 'succeeded']]);
        $this->refused('stale', fn () => app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, (string) Str::uuid(), 3));
        $this->refused('not_found', fn () => app(RefundDisputeLedger::class)->observe((string) Str::uuid(), $this->staff, (string) Str::uuid(), 0));
        $this->refused('invalid', fn () => app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, 'not-a-uuid', 0));
        $fail = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$fail): void {
            if ($fail && $event->action === 'commerce.refund_dispute.observed') {
                throw new \RuntimeException('Synthetic commit failure.');
            }
        });
        $request = (string) Str::uuid();
        $this->refused('unavailable', fn () => app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, $request, 0));
        $this->assertSame(0, RefundDisputeObservation::count());
        $this->assertSame(0, RefundLineAllocation::count());
        $fail = false;
        $this->assertSame('busy', app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, $request, 0)['status']);
        $this->assertSame('busy', app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, (string) Str::uuid(), 0)['status']);
        $this->refused('busy', fn () => $this->act('placeDisputeHold', $f));
        $calls = count($this->financial->calls);
        $this->travel(121)->seconds();
        $result = app(RefundDisputeLedger::class)->observe($f['order']->public_id, $this->staff, $request, 0);
        $this->assertSame(['recorded', 'full', 1], [$result['status'], $result['refundState'], $result['sequence']]);
        $this->assertSame($calls + 1, count($this->financial->calls));
        $this->assertSame(1, RefundDisputeObservation::count());
        $this->assertSame(1, RefundLineAllocation::count());
    }
}
