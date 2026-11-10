<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeEffect;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Domain\Commerce\RefundDispute\RefundDisputeAccess;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Commerce\RefundDispute\RefundDisputeLedger;
use App\Domain\Commerce\RefundDispute\RefundDisputePolicy;
use App\Domain\Commerce\RefundDispute\RefundDisputeSource;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxDownloads;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxSchema;
use App\Domain\Merch\Fulfillment\ActiveOrderHolds;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionTaxFinancialFixtures;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\Support\RefundDisputeFixtures;
use Tests\TestCase;

/**
 * BL-42: the refund and dispute lifecycle on the production tax chain. Synthetic orders only (Stripe test mode, synthetic rehearsal
 * provenance); the "provider" is a programmable read-only fixture, so no refund is sent and nothing reaches Stripe.
 */
class ProductionTaxRefundDisputeTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    private const LEDGER = ['refund_dispute_work', 'refund_dispute_observations', 'refund_dispute_objects', 'refund_dispute_statuses',
        'refund_line_allocations', 'refund_dispute_effects', 'refund_dispute_holds'];

    private const ORIGINALS = ['production_tax_checkout_orders', 'production_tax_checkout_requests', 'production_tax_checkout_bindings',
        'production_tax_checkout_reviewed_sessions', 'paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_document_work',
        'paid_tax_originals', 'paid_tax_fulfillments'];

    /** The refund choices of the machine policy the next synthetic order retains (DD-16 unless a test overrides them). */
    private static array $refundChoices = [];

    private User $staff;

    private object $financial;

    protected function setUp(): void
    {
        parent::setUp();
        self::$refundChoices = RefundDisputePolicy::PRODUCTION_CONFIRMED;
        $this->preparePaidTaxFixture();
        config(['paid-tax-grants.downloads_enabled' => true, 'refund-dispute.enabled' => true, 'payments.stripe.mode' => 'test',
            'payments.stripe.account_id' => 'acct_SYNTHETIC']);
        $this->staff = LicenseFixtures::admin();
    }

    /** Same synthetic machine as the shared fixture, with the refund choices this test retains in its order. */
    protected static function taxMachine(string $behavior = 'exclusive', int $ceiling = 2500): array
    {
        $machine = ProductionTrackCapabilitiesFixtures::machine();
        $machine['version'] = 'synthetic-tax-checkout-machine-v1';
        $machine['choices']['tax_calculation'] = ['strategy' => 'provider_calculated', 'behavior' => $behavior, 'maximum_rate_bps' => $ceiling, 'rounding' => 'provider_exact'];
        $machine['choices']['reservation_and_exclusives']['late_time_basis'] = 'application_verified_observation_time';
        $machine['choices']['reservation_and_exclusives']['reservation_seconds'] = 7200;
        $machine['choices']['reservation_and_exclusives']['provider_lifetime_seconds'] = 3600;
        $machine['choices']['refunds_and_disputes'] = self::$refundChoices;

        return $machine;
    }

    private function order(bool $two = false, bool $prepare = false): array
    {
        $f = $this->paidTaxOrder(twoLines: $two);
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        if ($prepare) {
            $f['batch'] = (new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        }
        $f['id'] = $f['batch']['id'];
        $f['gross'] = $f['batch']['lines'][0]['tax']['order_total_minor'];
        $f['totals'] = array_column(array_column($f['batch']['lines'], 'tax'), 'line_total_minor');
        $f['payment'] = (string) DB::table('paid_tax_order_origins')->where('public_id', $f['id'])->value('provider_payment_id');
        $this->financial = ProductionTaxFinancialFixtures::gateway('acct_SYNTHETIC', $f['payment'], $f['gross']);
        $this->app->instance(StripeFinancialInspectionGateway::class, $this->financial);

        return $f;
    }

    private function sequence(array $f): int
    {
        return app(RefundDisputeLedger::class)->review($f['id'], $this->staff)['sequence'];
    }

    private function observe(array $f, ?string $request = null): array
    {
        return app(RefundDisputeLedger::class)->observe($f['id'], $this->staff, $request ?? (string) Str::uuid(), $this->sequence($f));
    }

    private function act(string $method, array $f, ...$arguments): array
    {
        return app(RefundDisputeActions::class)->{$method}($f['id'], $this->staff, (string) Str::uuid(), $this->sequence($f), ...$arguments);
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

    private function held(callable $action): void
    {
        try {
            $action();
            $this->fail('A held order issued or redeemed a download.');
        } catch (PaidTaxException $error) {
            $this->assertSame([409, 'refund_dispute_hold'], [$error->status, $error->reason]);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
    }

    private function authorize(array $f, int $line = 0, string $kind = 'master_wav'): array
    {
        $row = $f['batch']['lines'][$line];

        return (new PaidTaxDownloads)->authorize($f['id'], $row['id'], ['requestKey' => (string) Str::uuid(), 'originHash' => $row['originHash'],
            'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))], $f['buyer']['principal'], $f['buyer']['user']);
    }

    private function redeem(array $f, array $authorization): int
    {
        $transfer = (new PaidTaxDownloads)->redeem($f['id'], $authorization['id'], $authorization['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $bytes = 0;
        $transfer->writeTo(static function (string $chunk) use (&$bytes): void {
            $bytes += strlen($chunk);
        });

        return $bytes;
    }

    private function dlStatus(array $f): array
    {
        return (new PaidTaxDownloads)->status($f['id'], $f['buyer']['principal'], $f['buyer']['user']);
    }

    /** Per line (by position): whether an active hold covers it, read the way an owner listing would. */
    private function heldLines(array $f): array
    {
        $batch = (int) $this->batchRow($f)->id;
        $held = RefundDisputeAccess::heldGrantIds($batch, RefundDisputeSource::PRODUCTION_TAX);

        return array_map(fn (int $origin): bool => $held['order'] || in_array($origin, $held['grants'], true),
            DB::table('paid_tax_grant_origins')->where('batch_id', $batch)->orderBy('position')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    private function counts(): array
    {
        return array_combine(self::LEDGER, array_map(fn (string $table): int => DB::table($table)->count(), self::LEDGER));
    }

    /** Original business evidence that the lifecycle must never change. */
    private function originals(): array
    {
        $rows = [];
        foreach (self::ORIGINALS as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    private function kinds(): array
    {
        return RefundDisputeEffect::orderBy('id')->pluck('kind')->all();
    }

    private function batchRow(array $f): object
    {
        return DB::table('paid_tax_order_origins')->where('public_id', $f['id'])->first();
    }

    public function test_every_write_refuses_unless_enabled_in_local_or_testing_on_the_own_account(): void
    {
        $f = $this->order();
        $this->financial->disputes = [['du_ONE', $f['gross'], 'needs_response']];
        $cases = [
            'flag off' => [fn () => config(['refund-dispute.enabled' => false]), 'disabled'],
            'flag string' => [fn () => config(['refund-dispute.enabled' => 'true']), 'disabled'],
            'staging' => [fn () => $this->app['env'] = 'staging', 'disabled'],
            'production' => [fn () => $this->app['env'] = 'production', 'disabled'],
            'live mode' => [fn () => config(['payments.stripe.mode' => 'live']), 'disabled'],
            'no account' => [fn () => config(['payments.stripe.account_id' => null]), 'disabled'],
            'another account' => [fn () => config(['payments.stripe.account_id' => 'acct_ANOTHER']), 'not_found'],
        ];
        $before = $this->counts();
        $originals = $this->originals();
        foreach ($cases as $name => [$change, $reason]) {
            config(['refund-dispute.enabled' => true, 'payments.stripe.mode' => 'test', 'payments.stripe.account_id' => 'acct_SYNTHETIC']);
            $this->app['env'] = 'testing';
            $change();
            $this->refused($reason, fn () => app(RefundDisputeLedger::class)->observe($f['id'], $this->staff, (string) Str::uuid(), 0));
            $this->refused($reason, fn () => app(RefundDisputeActions::class)->placeDisputeHold($f['id'], $this->staff, (string) Str::uuid(), 0));
            if (! in_array($name, ['staging', 'production'], true)) {
                // Staff authority is checked first, and staging/production demand MFA this fixture user does not have.
                $this->refused($reason, fn () => app(RefundDisputeLedger::class)->review($f['id'], $this->staff));
            }
        }
        $this->app['env'] = 'testing';
        $this->assertSame([], $this->financial->calls);
        $this->assertSame($before, $this->counts());
        $this->assertSame($originals, $this->originals());
    }

    public function test_only_the_dd16_values_are_accepted_from_the_retained_machine_policy(): void
    {
        $policy = app(RefundDisputePolicy::class);
        $machine = static function (array $change = []): array {
            $machine = ProductionTrackCapabilitiesFixtures::machine();
            $machine['choices']['refunds_and_disputes'] = array_replace(RefundDisputePolicy::PRODUCTION_CONFIRMED, $change);

            return $machine;
        };
        $ok = $policy->productionTax($machine());
        $this->assertSame(RefundDisputePolicy::PRODUCTION_CONFIRMED, $ok['values']);
        $this->assertSame('production_tax', $ok['boundary']['chain']);
        $this->assertSame(['refund_authority' => 'authoritative_provider_operator_review', 'partial_refunds' => 'exact_line_allocation',
            'dispute_access' => 'temporarily_block_while_reconciling', 'exclusive_reopening' => 'qualified_operator_review'], $ok['values']);
        foreach (array_keys(RefundDisputePolicy::PRODUCTION_CONFIRMED) as $key) {
            foreach ([null, ''] as $unset) {
                $changed = $machine([$key => $unset]);
                $this->refused('policy_unset', fn () => $policy->productionTax($changed));
            }
            $missing = $machine();
            unset($missing['choices']['refunds_and_disputes'][$key]);
            $this->refused('policy_unset', fn () => $policy->productionTax($missing));
            $this->refused('policy_unknown', fn () => $policy->productionTax($machine([$key => 'revoke_license'])));
        }
        // Values MachinePolicyV1 admits but DD-16 did not confirm stay test-chain only.
        $this->refused('policy_unknown', fn () => $policy->productionTax($machine(['dispute_access' => 'operator_review'])));
        $this->refused('policy_unknown', fn () => $policy->productionTax($machine(['exclusive_reopening' => 'remain_sold'])));
        $extra = $machine();
        $extra['choices']['refunds_and_disputes']['refund_window_days'] = '14';
        $this->refused('policy_unknown', fn () => $policy->productionTax($extra));
        $this->refused('unavailable', fn () => $policy->productionTax(['choices' => ['refunds_and_disputes' => RefundDisputePolicy::PRODUCTION_CONFIRMED]]));
        $this->refused('policy_unset', fn () => $policy->productionTax(['choices' => []]));
        // The machine policy, not `refund-dispute.policy`, supplies the values: the test chain's config is irrelevant here.
        config(['refund-dispute.policy' => ['refund_authority' => 'something_else']]);
        $this->assertSame(RefundDisputePolicy::PRODUCTION_CONFIRMED, $policy->productionTax($machine())['values']);
    }

    public function test_an_order_retaining_unconfirmed_refund_choices_refuses_every_write_and_reads_nothing(): void
    {
        self::$refundChoices = ['dispute_access' => 'operator_review', 'exclusive_reopening' => 'remain_sold'] + RefundDisputePolicy::PRODUCTION_CONFIRMED;
        $f = $this->order();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $this->refused('policy_unknown', fn () => app(RefundDisputeLedger::class)->observe($f['id'], $this->staff, (string) Str::uuid(), 0));
        $this->refused('policy_unknown', fn () => app(RefundDisputeActions::class)->suspendLine($f['id'], $this->staff, (string) Str::uuid(), 0, $f['batch']['lines'][0]['id']));
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(array_fill_keys(self::LEDGER, 0), $this->counts());
    }

    public function test_a_full_refund_allocates_each_taxed_line_exactly_and_records_review_without_changing_access_or_originals(): void
    {
        $f = $this->order(two: true);
        $this->assertCount(2, $f['totals']);
        $this->assertSame($f['gross'], array_sum($f['totals']));
        $originals = $this->originals();
        $this->financial->refunds = [['re_FIRST', 1000, 'succeeded'], ['re_REST', $f['gross'] - 1000, 'succeeded']];
        $result = $this->observe($f);
        $this->assertSame(['observed', 'full', 'none', $f['gross'], 0], [$result['outcome'], $result['refundState'], $result['disputeState'],
            $result['succeededRefundMinor'], $result['pendingRefundMinor']]);
        $this->assertSame(0, collect($this->financial->calls)->max('transaction_level'));
        $grants = DB::table('paid_tax_grant_origins')->orderBy('position')->pluck('id', 'position')->all();
        foreach ($f['totals'] as $index => $total) {
            $this->assertSame($total, (int) RefundLineAllocation::where('order_line_id', $grants[$index + 1])->sum('amount_minor'), 'line '.($index + 1));
        }
        $this->assertSame($f['gross'], (int) RefundLineAllocation::sum('amount_minor'));
        $this->assertSame(['automatic_full_refund'], RefundLineAllocation::distinct()->pluck('source')->all());
        $this->assertSame(['production_tax'], RefundLineAllocation::distinct()->pluck('order_source')->all());
        $kinds = $this->kinds();
        $this->assertSame(2, count(array_keys($kinds, 'line_refunded_review_required', true)));
        $this->assertSame(0, RefundDisputeHold::count());
        $this->assertSame([], array_values(array_filter($kinds, fn (string $kind): bool => str_starts_with($kind, 'exclusive_'))));
        // authoritative_provider_operator_review never changes access or evidence by itself.
        $this->assertSame($originals, $this->originals());
        $this->assertSame([false, false], $this->heldLines($f));
    }

    public function test_a_partial_refund_needs_an_exact_staff_allocation_and_each_command_is_idempotent(): void
    {
        $f = $this->order(two: true);
        $this->financial->refunds = [['re_PART', $f['totals'][0], 'succeeded']];
        $this->assertSame('partial', $this->observe($f)['refundState']);
        $this->assertSame(['allocation_required'], $this->kinds());
        $this->assertSame(0, RefundLineAllocation::count());
        $refund = RefundDisputeObject::where('kind', 'refund')->sole()->public_id;
        $first = $f['totals'][0];
        $this->refused('invalid', fn () => $this->act('allocate', $f, $refund, [1 => $first - 1]));
        $this->refused('invalid', fn () => $this->act('allocate', $f, $refund, [1 => $first + 1]));
        $this->refused('invalid', fn () => $this->act('allocate', $f, $refund, [3 => $first]));
        $this->refused('not_found', fn () => $this->act('allocate', $f, (string) Str::uuid(), [1 => $first]));
        $this->assertSame(0, RefundLineAllocation::count());
        $request = (string) Str::uuid();
        $sequence = $this->sequence($f);
        $actions = app(RefundDisputeActions::class);
        $done = $actions->allocate($f['id'], $this->staff, $request, $sequence, $refund, [1 => $first]);
        $this->assertFalse($done['replayed']);
        $this->assertSame(['allocation_recorded', 'line_refunded_review_required'], array_column($done['effects'], 'kind'));
        $counts = $this->counts();
        $replay = $actions->allocate($f['id'], $this->staff, $request, $sequence, $refund, [1 => $first]);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($counts, $this->counts());
        $this->refused('conflict', fn () => $actions->allocate($f['id'], $this->staff, $request, $sequence, $refund, [2 => $first]));
        $this->refused('stale', fn () => $actions->allocate($f['id'], $this->staff, (string) Str::uuid(), $sequence, $refund, [1 => $first]));
        $this->refused('already_applied', fn () => $this->act('allocate', $f, $refund, [1 => $first]));
        $this->assertSame(['production_tax'], RefundLineAllocation::distinct()->pluck('order_source')->all());
        $this->assertSame('staff', RefundLineAllocation::sole()->source);
        // Only line 1 is covered; the order is still not fully refunded.
        $this->assertSame(1, count(array_keys($this->kinds(), 'line_refunded_review_required', true)));
    }

    public function test_a_single_line_partial_refund_allocates_automatically_and_completing_it_records_the_line_review(): void
    {
        $f = $this->order();
        $this->assertCount(1, $f['totals']);
        $this->financial->refunds = [['re_ONE', 1000, 'succeeded']];
        $this->assertSame('partial', $this->observe($f)['refundState']);
        $this->assertSame(['automatic_single_line'], RefundLineAllocation::pluck('source')->all());
        $this->assertSame([1000], RefundLineAllocation::pluck('amount_minor')->all());
        $this->assertSame(['allocation_recorded'], $this->kinds());
        $this->financial->refunds = [['re_ONE', 1000, 'succeeded'], ['re_TWO', $f['gross'] - 1000, 'succeeded']];
        $this->assertSame('full', $this->observe($f)['refundState']);
        $this->assertSame($f['gross'], (int) RefundLineAllocation::sum('amount_minor'));
        $this->assertSame(1, count(array_keys($this->kinds(), 'line_refunded_review_required', true)));
        // A pending refund counts toward neither state nor allocation.
        $this->assertSame(0, RefundDisputeHold::count());
    }

    public function test_an_open_dispute_blocks_delivery_for_the_order_and_a_won_dispute_restores_it(): void
    {
        $f = $this->order(prepare: true);
        $early = $this->authorize($f);
        $this->financial->disputes = [['du_ONE', $f['gross'], 'needs_response']];
        $this->observe($f);
        $hold = RefundDisputeHold::sole();
        $this->assertSame(['production_tax', 'dispute_reconciling', null], [$hold->order_source, $hold->reason, $hold->license_grant_id]);
        $this->held(fn () => $this->authorize($f));
        $this->held(fn () => $this->redeem($f, $early));
        $this->assertSame([true], $this->heldLines($f));
        $this->assertSame(0, DB::table('paid_tax_redemptions')->count());
        $this->refused('reconciling', fn () => $this->act('releaseHold', $f, $hold->public_id));
        // Staff cannot place a second hold: the confirmed policy blocks automatically.
        $this->refused('policy_forbids', fn () => $this->act('placeDisputeHold', $f));
        $this->financial->disputes = [['du_ONE', $f['gross'], 'won']];
        $this->observe($f);
        $this->assertNotNull($hold->fresh()->released_at);
        $this->assertSame([false], $this->heldLines($f));
        // The authorization issued before the hold was never revoked and redeems once the hold is released.
        $this->assertGreaterThan(0, $this->redeem($f, $early));
        $this->assertGreaterThan(0, $this->redeem($f, $this->authorize($f)));
        $this->assertContains('dispute_hold_applied', $this->kinds());
        $this->assertContains('dispute_hold_released', $this->kinds());
    }

    public function test_a_lost_dispute_keeps_access_ended_for_staff_review_and_never_revokes_a_grant_or_original(): void
    {
        $f = $this->order(two: true, prepare: true);
        $originals = $this->originals();
        $this->financial->disputes = [['du_ONE', $f['gross'], 'under_review']];
        $this->observe($f);
        $this->financial->disputes = [['du_ONE', $f['gross'], 'lost']];
        $this->observe($f);
        $hold = RefundDisputeHold::whereNull('released_at')->sole();
        $this->assertContains('dispute_lost_review_required', $this->kinds());
        $this->held(fn () => $this->authorize($f, 0));
        $this->held(fn () => $this->authorize($f, 1));
        // The lost dispute ends line 1's access by staff suspension; the order-wide reconciling hold can then be released for line 2.
        $this->act('suspendLine', $f, $f['batch']['lines'][0]['id']);
        $this->act('releaseHold', $f, $hold->public_id);
        $this->held(fn () => $this->authorize($f, 0));
        $this->assertNotEmpty($this->authorize($f, 1)['id']);
        $this->assertSame([true, false], $this->heldLines($f));
        // Records only: no original, grant or contract was changed or removed.
        $this->assertSame($originals, $this->originals());
        $this->assertSame(2, DB::table('paid_tax_grant_origins')->count());
        $this->refused('already_applied', fn () => $this->act('suspendLine', $f, $f['batch']['lines'][0]['id']));
    }

    public function test_a_refund_after_delivery_keeps_the_delivered_attempt_and_every_prior_record(): void
    {
        $f = $this->order(prepare: true);
        $this->assertGreaterThan(0, $this->redeem($f, $this->authorize($f)));
        $originals = $this->originals();
        $redemptions = DB::table('paid_tax_redemptions')->get()->all();
        $authorizations = DB::table('paid_tax_authorizations')->get()->all();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $this->observe($f);
        $this->assertSame(['allocation_recorded', 'line_refunded_review_required'], $this->kinds());
        // Full refund alone does not revoke: new downloads still work until staff suspend the line.
        $this->assertSame(0, RefundDisputeHold::count());
        $this->assertNotEmpty($this->authorize($f)['id']);
        $this->assertSame($originals, $this->originals());
        $this->assertEquals($redemptions, DB::table('paid_tax_redemptions')->get()->all());
        $this->assertEquals($authorizations, array_slice(DB::table('paid_tax_authorizations')->get()->all(), 0, 1));
        $suspended = $this->act('suspendLine', $f, $f['batch']['lines'][0]['id']);
        $this->assertSame(['refund_suspension_applied'], array_column($suspended['effects'], 'kind'));
        $this->held(fn () => $this->authorize($f));
        // Nothing was deleted or rewritten, and the delivered attempt stays counted.
        $this->assertSame(1, $this->dlStatus($f)['lines'][0]['attemptCount']);
        $this->assertSame($originals, $this->originals());
        $this->assertSame(1, DB::table('paid_tax_redemptions')->count());
    }

    public function test_replayed_and_out_of_order_observations_change_nothing_and_regressions_are_attention(): void
    {
        $f = $this->order();
        $this->financial->refunds = [['re_ONE', 1000, 'pending']];
        $this->financial->disputes = [['du_ONE', $f['gross'], 'needs_response']];
        $this->observe($f);
        $this->financial->refunds = [['re_ONE', 1000, 'succeeded']];
        $this->financial->disputes = [['du_ONE', $f['gross'], 'won']];
        $request = (string) Str::uuid();
        $sequence = $this->sequence($f);
        $ledger = app(RefundDisputeLedger::class);
        $accepted = $ledger->observe($f['id'], $this->staff, $request, $sequence);
        $this->assertSame(['observed', 'partial', 'won'], [$accepted['outcome'], $accepted['refundState'], $accepted['disputeState']]);
        $counts = $this->counts();
        $calls = count($this->financial->calls);
        // The same request replays the retained result with no provider read and no row.
        $replay = $ledger->observe($f['id'], $this->staff, $request, $sequence);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($accepted['observation'], $replay['observation']);
        $this->assertSame([$counts, $calls], [$this->counts(), count($this->financial->calls)]);
        $this->refused('conflict', fn () => $ledger->observe($f['id'], LicenseFixtures::admin(), $request, $sequence));
        // The same facts under a new request add an observation but no object, status, allocation, hold or effect.
        $this->observe($f);
        $expected = $counts;
        $expected['refund_dispute_observations']++;
        $expected['refund_dispute_work'] = $counts['refund_dispute_work'];
        $this->assertSame($expected, $this->counts());
        // An older state arriving later is attention and never undoes the accepted one.
        $this->financial->refunds = [['re_ONE', 1000, 'pending']];
        $this->financial->disputes = [['du_ONE', $f['gross'], 'needs_response']];
        $stale = $this->observe($f);
        $this->assertSame('attention', $stale['outcome']);
        $this->assertContains('observation_attention', $this->kinds());
        $this->assertSame(0, RefundDisputeHold::whereNull('released_at')->count());
        $this->assertSame(1, (int) RefundLineAllocation::count());
        // Every staff command refuses until a later check is accepted.
        $this->refused('not_observed', fn () => $this->act('suspendLine', $f, $f['batch']['lines'][0]['id']));
        // An object vanishing from a complete list and an amount change are attention too.
        $this->financial->refunds = [];
        $this->financial->disputes = [['du_ONE', $f['gross'], 'won']];
        $this->assertSame('attention', $this->observe($f)['outcome']);
        $this->financial->refunds = [['re_ONE', 999, 'succeeded']];
        $this->assertSame('attention', $this->observe($f)['outcome']);
        $this->financial->refunds = [['re_ONE', 1000, 'succeeded']];
        $this->assertSame('observed', $this->observe($f)['outcome']);
        $this->assertSame(1, RefundDisputeObject::where('kind', 'refund')->count());
    }

    public function test_provider_facts_for_another_account_payment_or_currency_are_attention_and_change_nothing(): void
    {
        $f = $this->order();
        $originals = $this->originals();
        $wrong = [
            'another account' => fn (array $source): array => ['account_id' => 'acct_ANOTHER'] + $source,
            'another payment intent' => function (array $source): array {
                $source['payment']['id'] = 'pi_ANOTHERORDER';

                return $source;
            },
            'refund for another payment' => function (array $source): array {
                $source['refunds']['data'][0]['payment_intent'] = 'pi_ANOTHERORDER';

                return $source;
            },
            'dispute on another charge' => function (array $source): array {
                $source['disputes']['data'][0]['charge'] = 'ch_ANOTHERORDER';

                return $source;
            },
            'refund in another currency' => function (array $source): array {
                $source['refunds']['data'][0]['currency'] = 'eur';

                return $source;
            },
            'refund above the order total' => function (array $source) use ($f): array {
                $source['refunds']['data'][0]['amount'] = $f['gross'] + 1;

                return $source;
            },
            'different gross' => function (array $source) use ($f): array {
                $source['payment']['amount'] = $f['gross'] + 100;

                return $source;
            },
            'incomplete list' => function (array $source): array {
                $source['refunds']['has_more'] = true;

                return $source;
            },
        ];
        $this->financial->refunds = [['re_ONE', 500, 'succeeded']];
        $this->financial->disputes = [['du_ONE', 500, 'needs_response']];
        foreach ($wrong as $name => $change) {
            $this->financial->onInspect = $change;
            $result = $this->observe($f);
            $this->assertContains($result['outcome'], $name === 'incomplete list' ? ['incomplete'] : ['attention'], $name);
            $this->assertNull($result['refundState'], $name);
        }
        $this->financial->onInspect = null;
        $this->assertSame(0, RefundDisputeObject::count());
        $this->assertSame(0, RefundLineAllocation::count());
        $this->assertSame(0, RefundDisputeHold::count());
        $this->assertSame($originals, $this->originals());
    }

    public function test_non_staff_and_unknown_or_unfinalized_order_ids_are_refused(): void
    {
        $f = $this->order();
        // With the test chain's own policy configured, an id that is no finalized batch is simply not found.
        config(['refund-dispute.policy' => RefundDisputeFixtures::POLICY]);
        $this->financial->refunds = [['re_ONE', 500, 'succeeded']];
        $ledger = app(RefundDisputeLedger::class);
        foreach ([$f['buyer']['user'], User::factory()->create(), null] as $user) {
            foreach ([fn () => $ledger->observe($f['id'], $user, (string) Str::uuid(), 0), fn () => $ledger->review($f['id'], $user),
                fn () => app(RefundDisputeActions::class)->suspendLine($f['id'], $user, (string) Str::uuid(), 0, $f['batch']['lines'][0]['id'])] as $attempt) {
                try {
                    $attempt();
                    $this->fail('A non-staff caller reached an order.');
                } catch (AuthorizationException) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertSame([], $this->financial->calls);
        // The checkout order's own public id, an unknown id and malformed input are not batch ids.
        foreach ([$f['order']['orderId'], (string) Str::uuid(), 'not-a-uuid'] as $id) {
            $this->refused('not_found', fn () => $ledger->observe($id, $this->staff, (string) Str::uuid(), 0));
        }
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(array_fill_keys(self::LEDGER, 0), $this->counts());
    }

    public function test_evidence_that_does_not_verify_is_unavailable_before_any_provider_read(): void
    {
        $f = $this->order();
        $this->financial->refunds = [['re_ONE', 500, 'succeeded']];
        // The retained rows are immutable; a disposable fixture removes that guard to plant corruption.
        DB::unprepared('DROP TRIGGER paid_tax_grant_origins_immutable');
        DB::table('paid_tax_grant_origins')->update(['payload_hash' => str_repeat('0', 64)]);
        $this->refused('unavailable', fn () => app(RefundDisputeLedger::class)->observe($f['id'], $this->staff, (string) Str::uuid(), 0));
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(array_fill_keys(self::LEDGER, 0), $this->counts());
    }

    public function test_an_exclusive_reopening_decision_is_never_available_because_the_chain_sells_no_exclusive(): void
    {
        $f = $this->order();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $this->financial->disputes = [['du_ONE', $f['gross'], 'lost']];
        $this->observe($f);
        $this->assertSame([], array_values(array_filter($this->kinds(), fn (string $kind): bool => str_starts_with($kind, 'exclusive_'))));
        $this->refused('not_qualified', fn () => $this->act('qualifyExclusiveReopening', $f, $f['batch']['lines'][0]['id']));
        $this->refused('not_found', fn () => $this->act('qualifyExclusiveReopening', $f, (string) Str::uuid()));
        $this->assertNotContains('exclusive_reopening_qualified', $this->kinds());
    }

    public function test_holds_and_objects_of_the_two_chains_never_cross_even_with_equal_order_numbers(): void
    {
        $f = $this->order();
        $batchId = (int) $this->batchRow($f)->id;
        $originId = (int) DB::table('paid_tax_grant_origins')->value('id');
        $this->financial->disputes = [['du_ONE', $f['gross'], 'needs_response']];
        $this->financial->refunds = [['re_ONE', 500, 'succeeded']];
        $this->observe($f);
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
        // The production hold answers only the production source.
        $this->assertSame(['blocked', null], $this->blocked($batchId, $originId, RefundDisputeSource::PRODUCTION_TAX));
        $this->assertSame([null, null], $this->blocked($batchId, $originId, RefundDisputeSource::TEST));
        $this->assertSame(['order' => false, 'grants' => []], RefundDisputeAccess::heldGrantIds($batchId, RefundDisputeSource::TEST));
        $this->assertSame(['order' => true, 'grants' => []], RefundDisputeAccess::heldGrantIds($batchId, RefundDisputeSource::PRODUCTION_TAX));
        // Merchandise reads the test chain's ledger by order number only through ActiveOrderHolds and is unaffected.
        $this->assertFalse((new ActiveOrderHolds)->exist($batchId));
        $this->assertSame(1, DB::table('refund_dispute_objects')->where('order_id', $batchId)->where('order_source', 'production_tax')->where('kind', 'refund')->count());
        $this->assertSame(0, DB::table('refund_dispute_objects')->where('order_id', $batchId)->where('order_source', 'test')->count());
    }

    public function test_a_test_chain_row_with_the_same_order_number_never_leaks_into_a_production_order(): void
    {
        $f = $this->order();
        $batchId = (int) $this->batchRow($f)->id;
        $originId = (int) DB::table('paid_tax_grant_origins')->value('id');
        // A disposable fixture removes the test chain's existence guards so rows for a test order numbered like this batch can be planted.
        foreach (['refund_dispute_work_insert', 'refund_dispute_effects_insert', 'refund_dispute_holds_insert'] as $guard) {
            DB::unprepared('DROP TRIGGER '.$guard);
        }
        $now = now();
        DB::table('refund_dispute_work')->insert(['order_id' => $batchId, 'order_source' => 'test', 'sequence' => 7, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('refund_dispute_effects')->insert(['public_id' => (string) Str::uuid(), 'order_id' => $batchId, 'order_source' => 'test', 'sequence' => 1,
            'effect_key' => 'planted', 'kind' => 'dispute_hold_applied', 'policy_hash' => str_repeat('a', 64), 'context' => '{}', 'created_at' => $now]);
        DB::table('refund_dispute_holds')->insert(['public_id' => (string) Str::uuid(), 'order_id' => $batchId, 'order_source' => 'test', 'license_grant_id' => null,
            'reason' => 'dispute_reconciling', 'applied_effect_id' => DB::table('refund_dispute_effects')->value('id'), 'created_at' => $now]);
        // The planted test-chain hold and sequence are invisible to the production order that shares their number.
        $this->assertSame(0, $this->sequence($f));
        $this->assertSame([null, null], $this->blocked($batchId, $originId, RefundDisputeSource::PRODUCTION_TAX));
        $this->assertSame(['blocked', null], $this->blocked($batchId, $originId, RefundDisputeSource::TEST));
        $this->assertSame([false], $this->heldLines($f));
        $this->financial->refunds = [['re_ONE', 500, 'succeeded']];
        $this->assertSame('partial', $this->observe($f)['refundState']);
        $this->assertSame(1, $this->sequence($f));
        $this->assertSame([7, 1], DB::table('refund_dispute_work')->orderBy('order_source', 'desc')->pluck('sequence')->map(fn ($v): int => (int) $v)->all());
        // The production ledger counted only its own rows, and the planted ones were left exactly as written.
        $this->assertSame(1, RefundDisputeObject::where('order_source', 'production_tax')->count());
        $this->assertSame(0, RefundDisputeObject::where('order_source', 'test')->count());
        $this->assertSame(1, DB::table('refund_dispute_holds')->where('order_source', 'test')->whereNull('released_at')->count());
        $this->assertSame(0, RefundDisputeHold::where('order_source', 'production_tax')->count());
    }

    private function blocked(int $order, int $grant, string $source): array
    {
        try {
            RefundDisputeAccess::assertClear($order, $grant, $source);

            return [null, null];
        } catch (DeliveryException $error) {
            return [$error->reason, null];
        }
    }

    public function test_database_guards_reject_cross_chain_unknown_and_rewritten_rows(): void
    {
        $f = $this->order();
        $this->financial->disputes = [['du_ONE', 500, 'needs_response']];
        $this->financial->refunds = [['re_ONE', 500, 'succeeded']];
        $this->observe($f);
        $batchId = (int) $this->batchRow($f)->id;
        // The paid-tax schema refuses any external object that names its tables, so these guards never do: the production source's
        // parents are proven by the ledger's own locked transaction, and the paid-tax installation still verifies exactly.
        PaidTaxSchema::assertInstalled();
        $reject = function (callable $statement, string $label): void {
            try {
                $statement();
                $this->fail("Guard accepted: {$label}");
            } catch (QueryException $error) {
                $this->assertStringContainsString('Invalid or immutable refund and dispute evidence', $error->getMessage(), $label);
            }
        };
        $work = fn (array $change): array => array_replace(['order_id' => $batchId + 100, 'order_source' => 'production_tax', 'sequence' => 0,
            'created_at' => now(), 'updated_at' => now()], $change);
        $reject(fn () => DB::table('refund_dispute_work')->insert($work(['order_id' => $batchId + 1, 'order_source' => 'test'])), 'work naming a test order that does not exist');
        $reject(fn () => DB::table('refund_dispute_work')->insert($work(['order_source' => 'live'])), 'unknown source');
        $reject(fn () => DB::table('refund_dispute_work')->insert($work(['order_id' => $batchId])), 'second coordination row for the batch');
        $reject(fn () => DB::table('refund_dispute_work')->where('order_id', $batchId)->update(['order_source' => 'test']), 'work source rewrite');
        $observation = (array) DB::table('refund_dispute_observations')->orderBy('id')->first();
        unset($observation['id']);
        $row = fn (array $change): array => array_replace($observation, ['public_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'sequence' => 99], $change);
        $reject(fn () => DB::table('refund_dispute_observations')->insert($row(['order_source' => 'test'])), 'observation filed under the wrong chain');
        $reject(fn () => DB::table('refund_dispute_observations')->insert($row(['order_id' => 0])), 'observation for no order');
        $reject(fn () => DB::table('refund_dispute_observations')->insert($row(['sequence' => 1])), 'duplicate sequence in one chain');
        $object = DB::table('refund_dispute_objects')->where('kind', 'refund')->first();
        $allocation = ['public_id' => (string) Str::uuid(), 'order_id' => $batchId, 'order_source' => 'production_tax', 'refund_dispute_object_id' => $object->id,
            'order_line_id' => 999999, 'amount_minor' => 1, 'currency' => 'USD', 'source' => 'staff', 'actor_id' => $this->staff->id, 'sequence' => 9, 'created_at' => now()];
        $reject(fn () => DB::table('refund_line_allocations')->insert(['order_line_id' => 0] + $allocation), 'allocation to no line');
        $line = (int) DB::table('paid_tax_grant_origins')->value('id');
        $reject(fn () => DB::table('refund_line_allocations')->insert(['order_line_id' => $line, 'order_source' => 'test'] + $allocation), 'allocation filed under the test chain');
        $hold = RefundDisputeHold::whereNull('released_at')->sole();
        $reject(fn () => DB::table('refund_dispute_holds')->where('id', $hold->id)->update(['order_source' => 'test']), 'hold source rewrite');
        $reject(fn () => DB::table('refund_dispute_holds')->where('id', $hold->id)->update(['order_id' => $batchId + 1]), 'hold order rewrite');
        $reject(fn () => DB::table('refund_dispute_holds')->delete(), 'hold delete');
        $reject(fn () => DB::table('refund_dispute_effects')->delete(), 'effects delete');
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
    }

    public function test_holds_are_reported_per_line_and_a_second_order_is_unaffected(): void
    {
        $f = $this->order(two: true);
        $second = $this->secondPaidTaxOrder($f, 'cs_test_refundsecond', 'pi_refundsecond');
        $second['batch'] = (new PaidTaxGrants)->finalize($second['buyer']['principal'], $second['buyer']['user'], $second['order']['orderId']);
        $second['id'] = $second['batch']['id'];
        $this->financial->disputes = [['du_ONE', $f['gross'], 'needs_response']];
        $this->observe($f);
        $this->assertSame([true, true], $this->heldLines($f));
        $this->assertSame([false], $this->heldLines($second));
        $secondBatch = (int) DB::table('paid_tax_order_origins')->where('public_id', $second['batch']['id'])->value('id');
        $this->assertSame([null, null], $this->blocked($secondBatch, (int) DB::table('paid_tax_grant_origins')->where('batch_id', $secondBatch)->value('id'), 'production_tax'));
        // The second order has no observation at all: its ledger is empty and its own sequence starts at zero.
        $this->assertSame(0, app(RefundDisputeLedger::class)->review($second['batch']['id'], $this->staff)['sequence']);
        $this->assertSame(1, RefundDisputeObservation::count());
    }
}
