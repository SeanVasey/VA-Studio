<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\RefundDispute\Models\RefusedOrderResolution;
use App\Domain\Commerce\RefundDispute\ProductionTaxFinalizeGuard;
use App\Domain\Commerce\RefundDispute\RefundDisputeException;
use App\Domain\Commerce\RefundDispute\RefundDisputePolicy;
use App\Domain\Commerce\RefundDispute\ResolveRefusedProductionOrder;
use App\Domain\Commerce\RefundDispute\StaffCommerceDirectory;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Filament\Resources\ProductionTaxRefusedOrderResource;
use App\Filament\Resources\ProductionTaxRefusedOrderResource\Pages\ListProductionTaxRefusedOrders;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionTaxFinancialFixtures;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\TestCase;

/**
 * FP-068 (Sean, 2026-11-10): staff resolution of a paid production tax order whose finalization is refused (fully refunded or
 * dispute lost). Synthetic orders only; the "provider" is the programmable read-only fixture, so nothing reaches Stripe.
 */
class RefusedOrderResolutionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    private const TABLE = 'production_tax_refused_order_resolutions';

    private User $staff;

    private object $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        config(['paid-tax-grants.downloads_enabled' => true, 'refund-dispute.enabled' => true, 'payments.stripe.mode' => 'test',
            'payments.stripe.account_id' => 'acct_SYNTHETIC']);
        $this->staff = LicenseFixtures::admin();
    }

    /** Same synthetic machine as the shared fixture, retaining the confirmed DD-16 refund choices. */
    protected static function taxMachine(string $behavior = 'exclusive', int $ceiling = 2500): array
    {
        $machine = ProductionTrackCapabilitiesFixtures::machine();
        $machine['version'] = 'synthetic-tax-checkout-machine-v1';
        $machine['choices']['tax_calculation'] = ['strategy' => 'provider_calculated', 'behavior' => $behavior, 'maximum_rate_bps' => $ceiling, 'rounding' => 'provider_exact'];
        $machine['choices']['reservation_and_exclusives']['late_time_basis'] = 'application_verified_observation_time';
        $machine['choices']['reservation_and_exclusives']['reservation_seconds'] = 7200;
        $machine['choices']['reservation_and_exclusives']['provider_lifetime_seconds'] = 3600;
        $machine['choices']['refunds_and_disputes'] = RefundDisputePolicy::PRODUCTION_CONFIRMED;

        return $machine;
    }

    /** A paid, unfinalized synthetic order with the programmable provider bound to its reviewed payment. */
    private function pending(bool $two = false): array
    {
        $f = $this->paidTaxOrder(twoLines: $two);
        $reviewed = DB::table('production_tax_checkout_reviewed_sessions')->first();
        $f['payment'] = (string) $reviewed->provider_payment_id;
        $f['gross'] = (int) $reviewed->amount_total_minor;
        $f['id'] = $f['order']['orderId'];
        $this->financial = ProductionTaxFinancialFixtures::gateway('acct_SYNTHETIC', $f['payment'], $f['gross']);
        $this->app->instance(StripeFinancialInspectionGateway::class, $this->financial);

        return $f;
    }

    private function resolve(array $f, ?User $actor = null, bool $asStaff = true): array
    {
        return app(ResolveRefusedProductionOrder::class)->resolve($f['id'], $asStaff ? ($actor ?? $this->staff) : $actor);
    }

    private function refused(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('The resolution unexpectedly succeeded.');
        } catch (RefundDisputeException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    private function finalizeOrder(array $f): array
    {
        return (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
    }

    /** Every table's rows, so a test can prove the only writes were the resolution row and its audit event. */
    private function snapshot(): array
    {
        $rows = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $rows[$name] = md5(json_encode(DB::table($name)->get(), JSON_THROW_ON_ERROR));
        }

        return $rows;
    }

    private function changedTables(array $before): array
    {
        $changed = array_keys(array_diff_assoc($this->snapshot(), $before));
        sort($changed);

        return $changed;
    }

    /** The tables a successful resolution may write, sorted. */
    private function written(): array
    {
        $tables = ['audit_events', self::TABLE];
        sort($tables);

        return $tables;
    }

    private function nothingWritten(callable $action, string $reason, array $f): void
    {
        $before = $this->snapshot();
        $this->refused($reason, $action);
        $this->assertSame([], $this->changedTables($before));
        $this->assertSame(0, RefusedOrderResolution::count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_fully_refunded_order_is_resolved_with_no_grant_and_only_the_resolution_and_audit_rows(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_PRIVATE', $f['gross'], 'succeeded']];
        $before = $this->snapshot();
        $result = $this->resolve($f);
        $this->assertSame(['testOnly' => true, 'status' => 'recorded', 'replayed' => false], array_intersect_key($result, array_flip(['testOnly', 'status', 'replayed'])));
        $this->assertSame('full_refund', $result['reason']);
        $this->assertSame($f['gross'], $result['grossMinor']);
        // Nothing else in the database changed: no order, payment, refund, grant, license, contract, authorization, offer or track row.
        $this->assertSame($this->written(), $this->changedTables($before));
        $row = RefusedOrderResolution::sole();
        $this->assertSame([$f['id'], 'full_refund', $f['gross'], $f['payment'], 'USD', $this->staff->id, 'acct_SYNTHETIC'],
            [$row->order_public_id, $row->reason, $row->gross_minor, $row->provider_payment_id, $row->currency, $row->actor_id, $row->provider_account]);
        $this->assertSame(DB::table('production_tax_checkout_orders')->value('payload_hash'), $row->order_hash);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $row->getRawOriginal('evidence_hash'));
        $this->assertSame(hash('sha256', $row->getRawOriginal('evidence_ciphertext')), $row->getRawOriginal('evidence_hash'));
        $this->assertStringNotContainsString('re_PRIVATE', $row->getRawOriginal('evidence_ciphertext'));
        $audit = AuditEvent::where('action', 'commerce.refused_order.resolved')->sole();
        $this->assertSame([$this->staff->id, RefusedOrderResolution::class, $row->id], [$audit->actor_id, $audit->subject_type, $audit->subject_id]);
        $this->assertSame(0, $audit->context['grants_created']);
        // One provider read, bound to the retained PaymentIntent, outside every transaction.
        $this->assertCount(1, $this->financial->calls);
        $this->assertSame([[$f['payment'], 0]], array_map(fn (array $c): array => [$c['id'], $c['transaction_level']], $this->financial->calls));
        $this->assertSame(0, DB::table('paid_tax_order_origins')->count() + DB::table('paid_tax_grant_origins')->count()
            + DB::table('paid_tax_originals')->count() + DB::table('paid_tax_fulfillments')->count());
        $this->assertSame(0, DB::table('license_grants')->count());
    }

    public function test_a_lost_dispute_is_resolved_and_the_resolution_never_revokes_or_creates_anything(): void
    {
        $f = $this->pending();
        $this->financial->disputes = [['du_LOST', $f['gross'], 'lost']];
        $before = $this->snapshot();
        $result = $this->resolve($f);
        $this->assertSame('dispute_lost', $result['reason']);
        $this->assertSame($this->written(), $this->changedTables($before));
        $this->assertSame('dispute_lost', RefusedOrderResolution::sole()->reason);
    }

    public function test_a_lost_dispute_together_with_a_covering_refund_is_recorded_as_the_refund(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $this->financial->disputes = [['du_LOST', $f['gross'], 'lost']];
        $this->assertSame('full_refund', $this->resolve($f)['reason']);
    }

    public function test_a_second_resolve_is_a_no_op_that_returns_the_existing_resolution_without_a_provider_call(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $other = LicenseFixtures::admin();
        $first = $this->resolve($f);
        $calls = count($this->financial->calls);
        $before = $this->snapshot();
        // Even if the provider now shows something else, and even from another staff member, the first decision stands.
        $this->financial->refunds = [];
        $replay = $this->resolve($f, $other);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['resolution'], $replay['resolution']);
        $this->assertSame($first['reason'], $replay['reason']);
        $this->assertSame($before, $this->snapshot());
        $this->assertCount($calls, $this->financial->calls);
        $this->assertSame(1, RefusedOrderResolution::count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.refused_order.resolved')->count());
    }

    public function test_a_resolved_order_can_never_be_finalized_even_after_the_provider_state_reverses(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $this->resolve($f);
        $calls = count($this->financial->calls);
        $attempts = [
            'provider still refunded' => fn () => null,
            'provider now clean' => fn () => $this->financial->refunds = [],
            'lifecycle switched off' => fn () => config(['refund-dispute.enabled' => false]),
        ];
        foreach ($attempts as $name => $change) {
            $change();
            $before = $this->snapshot();
            try {
                $this->finalizeOrder($f);
                $this->fail($name.': a resolved order was finalized');
            } catch (PaidTaxException $error) {
                $this->assertSame([409, ProductionTaxFinalizeGuard::RESOLVED], [$error->status, $error->reason], $name);
            }
            $this->assertSame($before, $this->snapshot(), $name);
        }
        $this->assertCount($calls, $this->financial->calls, 'a resolved order is refused without another provider read');
        $this->assertSame(0, DB::table('paid_tax_order_origins')->count());
        $this->assertSame(0, DB::table('paid_tax_grant_origins')->count());
        // Another buyer learns nothing: the unlocked owner pre-check still precedes the deferred refusal.
        $guard = app(ProductionTaxFinalizeGuard::class);
        $this->assertTrue($guard->resolved($f['id']));
        $this->assertFalse($guard->resolved((string) Str::uuid()));
    }

    public function test_clear_partial_failed_and_unresolved_provider_states_are_refused_and_write_nothing(): void
    {
        $f = $this->pending();
        $cases = [
            'clear' => [[], [], 'not_refused'],
            'partial refund' => [[['re_PART', $f['gross'] - 1, 'succeeded']], [], 'not_refused'],
            'failed refund' => [[['re_FAILED', $f['gross'], 'failed']], [], 'not_refused'],
            'canceled refund' => [[['re_CANCELED', $f['gross'], 'canceled']], [], 'not_refused'],
            'dispute won' => [[], [['du_WON', $f['gross'], 'won']], 'not_refused'],
            'dispute warning closed' => [[], [['du_CLOSED', $f['gross'], 'warning_closed']], 'not_refused'],
            'dispute open' => [[], [['du_OPEN', $f['gross'], 'needs_response']], 'reconciling'],
            'dispute under review' => [[], [['du_REVIEW', $f['gross'], 'under_review']], 'reconciling'],
            'warning open' => [[], [['du_WARN', $f['gross'], 'warning_needs_response']], 'reconciling'],
            'fully refunded and the dispute is still open' => [[['re_FULL', $f['gross'], 'succeeded']], [['du_OPEN', $f['gross'], 'needs_response']], 'reconciling'],
            'refund only pending' => [[['re_PENDING', $f['gross'], 'pending']], [], 'in_flight'],
            'refunds in flight cover the gross' => [[['re_A', 100, 'succeeded'], ['re_B', $f['gross'] - 100, 'pending']], [], 'in_flight'],
            'refund action required' => [[['re_ACTION', $f['gross'], 'requires_action']], [], 'in_flight'],
        ];
        foreach ($cases as $name => [$refunds, $disputes, $reason]) {
            $this->financial->refunds = $refunds;
            $this->financial->disputes = $disputes;
            $before = count($this->financial->calls);
            $this->nothingWritten(fn () => $this->resolve($f), $reason, $f);
            $this->assertCount($before + 1, $this->financial->calls, $name);
        }
        // The charge aggregate says fully refunded while the list shows only part: not yet a succeeded full refund.
        $this->financial->refunds = [['re_LAG', 100, 'succeeded']];
        $this->financial->disputes = [];
        $this->financial->chargeOverrides = ['amount_refunded' => $f['gross'], 'refunded' => true];
        $this->nothingWritten(fn () => $this->resolve($f), 'in_flight', $f);
        $this->financial->chargeOverrides = [];
        $this->assertSame(0, collect($this->financial->calls)->max('transaction_level'));
        // None of those refusals is sticky: once the provider shows a full refund the same order resolves.
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $this->assertSame('full_refund', $this->resolve($f)['reason']);
    }

    public function test_a_failed_foreign_or_incomplete_provider_read_is_attention_and_writes_nothing(): void
    {
        $f = $this->pending();
        $failures = [
            'provider error' => fn (array $source) => throw new \RuntimeException('Stripe is unreachable'),
            'another account' => fn (array $source): array => ['account_id' => 'acct_ANOTHER'] + $source,
            'another payment' => fn (array $source): array => ['payment' => ['id' => 'pi_FOREIGN'] + $source['payment']] + $source,
            'another amount' => fn (array $source): array => ['payment' => ['amount' => $source['payment']['amount'] + 1] + $source['payment']] + $source,
            'live mode' => fn (array $source): array => ['payment' => ['livemode' => true] + $source['payment']] + $source,
            'incomplete refund list' => fn (array $source): array => ['refunds' => ['has_more' => true] + $source['refunds']] + $source,
            'incomplete dispute list' => fn (array $source): array => ['disputes' => ['has_more' => true] + $source['disputes']] + $source,
        ];
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        foreach ($failures as $name => $failure) {
            $this->financial->onInspect = $failure;
            $this->nothingWritten(fn () => $this->resolve($f), 'not_observed', $f);
        }
        // Refunds that individually fit but together exceed the payment are attention too, never a resolution.
        $this->financial->onInspect = null;
        $this->financial->refunds = [['re_ONE', $f['gross'], 'succeeded'], ['re_TWO', $f['gross'], 'succeeded']];
        $this->nothingWritten(fn () => $this->resolve($f), 'not_observed', $f);
        $this->financial->refunds = [['re_EUR', $f['gross'], 'succeeded', 'eur']];
        $this->nothingWritten(fn () => $this->resolve($f), 'not_observed', $f);
        $this->assertSame(0, collect($this->financial->calls)->max('transaction_level'));
    }

    public function test_a_finalized_order_is_refused_without_a_provider_call(): void
    {
        $f = $this->pending();
        $batch = $this->finalizeOrder($f);
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $calls = count($this->financial->calls);
        $before = $this->snapshot();
        $this->refused('finalized', fn () => $this->resolve($f));
        $this->assertSame($before, $this->snapshot());
        $this->assertCount($calls, $this->financial->calls, 'a finalized order uses the ledger path and makes no provider read here');
        $this->assertSame(1, DB::table('paid_tax_order_origins')->count());
        $this->assertSame($batch['id'], DB::table('paid_tax_order_origins')->value('public_id'));
        $this->assertSame(0, RefusedOrderResolution::count());
    }

    public function test_an_order_that_becomes_finalized_during_the_provider_read_is_refused_under_lock(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $armed = true;
        $self = $this;
        $this->financial->onInspect = function (array $source) use (&$armed, $f, $self): array {
            if ($armed) {
                $armed = false;
                // The buyer finalizes while the provider still looked clean, i.e. between staff's pre-check and the locked frame.
                $self->financial->refunds = [];
                $self->financial->onInspect = null;
                $self->finalizeOrder($f);
                $self->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
            }

            return $source;
        };
        $this->assertSame(0, DB::table('paid_tax_order_origins')->count());
        $this->refused('finalized', fn () => $this->resolve($f));
        $this->assertSame(1, DB::table('paid_tax_order_origins')->count());
        $this->assertSame(0, RefusedOrderResolution::count());
    }

    public function test_an_unpaid_or_unknown_order_is_refused(): void
    {
        $f = $this->taxOrder();
        $this->financial = ProductionTaxFinancialFixtures::gateway('acct_SYNTHETIC', 'pi_UNPAID', 1000);
        $this->app->instance(StripeFinancialInspectionGateway::class, $this->financial);
        $unpaid = ['id' => $f['order']['orderId']];
        $this->nothingWritten(fn () => $this->resolve($unpaid), 'not_paid', $unpaid);
        foreach ([(string) Str::uuid(), 'not-a-uuid', '', str_repeat('a', 300)] as $id) {
            $this->nothingWritten(fn () => $this->resolve(['id' => $id]), 'not_found', $unpaid);
        }
        $this->assertSame([], $this->financial->calls);
    }

    public function test_only_staff_with_mfa_and_current_authority_can_resolve_and_a_refusal_makes_no_provider_call(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $customer = User::factory()->create();
        $demoted = LicenseFixtures::admin();
        $stale = User::find($demoted->id);
        $demoted->is_admin = false;
        $demoted->save();
        $actors = ['guest' => null, 'customer' => $customer, 'the buyer' => $f['buyer']['user'], 'an unsaved user' => new User(['name' => 'Ghost']),
            'a user that no longer exists' => tap(User::factory()->make(), fn (User $user) => $user->id = 987654), 'demoted staff holding a stale model' => $stale];
        foreach ($actors as $name => $actor) {
            $before = $this->snapshot();
            try {
                $this->resolve($f, $actor, asStaff: false);
                $this->fail($name.' resolved an order');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($before, $this->snapshot(), $name);
        }
        // Staff without MFA enrolled while the panel requires it.
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $before = $this->snapshot();
            try {
                $this->resolve($f);
                $this->fail('Staff without MFA resolved an order');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($before, $this->snapshot());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
        }
        $this->assertSame([], $this->financial->calls, 'no unauthorized caller reached the provider');
        $this->assertSame(0, RefusedOrderResolution::count());
        $this->assertSame('full_refund', $this->resolve($f)['reason']);
    }

    public function test_authority_is_proven_again_under_lock_after_the_provider_read(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $panel = Filament::getPanel('admin');
        $providers = $panel->getMultiFactorAuthenticationProviders();
        // MFA becomes required (and this user has none) while the provider read is in flight.
        $this->financial->onInspect = function (array $source) use ($panel, $providers): array {
            $panel->multiFactorAuthentication($providers, isRequired: true);

            return $source;
        };
        try {
            $before = $this->snapshot();
            try {
                $this->resolve($f);
                $this->fail('Authority withdrawn during the read still resolved the order');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($before, $this->snapshot());
        } finally {
            $panel->multiFactorAuthentication($providers, isRequired: false);
        }
        // Admin rights withdrawn during the read.
        $this->financial->onInspect = function (array $source): array {
            User::whereKey($this->staff->id)->update(['is_admin' => false]);

            return $source;
        };
        try {
            $this->resolve($f);
            $this->fail('A demoted user resolved the order');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, RefusedOrderResolution::count());
        $this->assertSame(0, AuditEvent::where('action', 'commerce.refused_order.resolved')->count());
    }

    public function test_it_refuses_unless_the_production_lifecycle_is_admitted_and_never_inside_a_transaction(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $cases = [
            'flag off' => fn () => config(['refund-dispute.enabled' => false]),
            'staging' => fn () => $this->app['env'] = 'staging',
            'production' => fn () => $this->app['env'] = 'production',
            'live mode' => fn () => config(['payments.stripe.mode' => 'live']),
            'no account' => fn () => config(['payments.stripe.account_id' => null]),
        ];
        foreach ($cases as $name => $change) {
            config(['refund-dispute.enabled' => true, 'payments.stripe.mode' => 'test', 'payments.stripe.account_id' => 'acct_SYNTHETIC']);
            $this->app['env'] = 'testing';
            $change();
            // Staging and production would demand MFA this fixture user lacks; either refusal writes nothing and reads nothing.
            try {
                $this->resolve($f);
                $this->fail($name.' resolved an order');
            } catch (RefundDisputeException|AuthorizationException $error) {
                $this->addToAssertionCount(1);
            }
        }
        $this->app['env'] = 'testing';
        config(['refund-dispute.enabled' => true, 'payments.stripe.mode' => 'test', 'payments.stripe.account_id' => 'acct_SYNTHETIC']);
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(0, RefusedOrderResolution::count());
        $this->refused('unavailable', fn () => DB::transaction(fn () => $this->resolve($f)));
        $this->assertSame([], $this->financial->calls);
        $this->assertSame(0, RefusedOrderResolution::count());
    }

    public function test_the_track_and_offer_are_untouched_and_purchasable_again_because_this_chain_holds_nothing(): void
    {
        // DD-14 / C4: the tax chain reserves no inventory, sells no exclusive and writes only its own four append-only tables.
        $f = $this->pending();
        foreach (['inventory_reservations', 'exclusive_sales', 'exclusive_reservations', 'rights_scopes'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), $table.' holds nothing for a paid production tax order');
            }
        }
        $body = Evidence::open((array) DB::table('production_tax_checkout_orders')->first(), 'production_tax_checkout_order');
        $this->assertSame('unscoped_nonexclusive', $body['inventory']['kind']);
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $before = $this->snapshot();
        $this->resolve($f);
        // Every catalog table (tracks, offers, revisions, licenses, inventory) is byte-for-byte what it was before the resolution.
        $this->assertSame($this->written(), $this->changedTables($before));
        // The same buyer can order the same items again immediately.
        $again = $f['checkout']->preview($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items']);
        $order = $f['checkout']->order($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'],
            $again['previewHash'], true, ['legalName' => 'Declared synthetic buyer'], 'synthetic-tax-after-resolution');
        $this->assertNotSame($f['order']['orderId'], $order['orderId']);
        $this->assertSame($f['preview']['previewHash'], $again['previewHash'], 'the offer is previewed exactly as before the first order');
    }

    public function test_the_resolution_table_is_append_only_and_validated_by_the_database(): void
    {
        $f = $this->pending();
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        $this->resolve($f);
        $row = DB::table(self::TABLE)->first();
        $rejected = function (callable $statement, string $label): void {
            try {
                $statement();
                $this->fail($label.' was accepted');
            } catch (QueryException $error) {
                $this->assertStringContainsString('refused-order resolution', $error->getMessage(), $label);
            }
        };
        $rejected(fn () => DB::table(self::TABLE)->where('id', $row->id)->update(['reason' => 'dispute_lost']), 'update');
        $rejected(fn () => DB::table(self::TABLE)->where('id', $row->id)->delete(), 'delete');
        $rejected(fn () => $this->insertResolution($f), 'a second resolution of the same order');
        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame('full_refund', DB::table(self::TABLE)->value('reason'));
        $this->expectException(\LogicException::class);
        RefusedOrderResolution::sole()->update(['reason' => 'dispute_lost']);
    }

    public function test_the_database_refuses_a_resolution_of_the_wrong_shape(): void
    {
        $f = $this->pending();
        $bad = [
            'an unknown reason' => ['reason' => 'because'],
            'a zero amount' => ['gross_minor' => 0],
            'an amount above the cap' => ['gross_minor' => 100000000],
            'another currency' => ['currency' => 'EUR'],
            'a short order hash' => ['order_hash' => str_repeat('a', 63)],
            'a short order id' => ['order_public_id' => 'short'],
            'a short evidence hash' => ['evidence_hash' => 'abc'],
            'empty evidence' => ['evidence_ciphertext' => ''],
            'resolved before observed' => ['resolved_at' => '2000-01-01 00:00:00'],
        ];
        foreach ($bad as $name => $override) {
            try {
                $this->insertResolution($f, $override);
                $this->fail($name.' was accepted');
            } catch (QueryException $error) {
                $this->assertStringContainsString('refused-order resolution', $error->getMessage(), $name);
            }
        }
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->insertResolution($f);
        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    private function insertResolution(array $f, array $override = []): void
    {
        DB::table(self::TABLE)->insert($override + ['public_id' => (string) Str::uuid(), 'order_public_id' => $f['id'],
            'order_hash' => DB::table('production_tax_checkout_orders')->where('public_id', $f['id'])->value('payload_hash'),
            'provider_account' => 'acct_SYNTHETIC', 'provider_payment_id' => $f['payment'], 'reason' => 'full_refund', 'gross_minor' => $f['gross'],
            'currency' => 'USD', 'actor_id' => $this->staff->id, 'evidence_ciphertext' => 'x', 'evidence_hash' => str_repeat('b', 64),
            'canonicalization_version' => 'v1', 'observed_at' => '2026-11-10 00:00:00', 'resolved_at' => '2026-11-10 00:00:01']);
    }

    public function test_staff_see_unfinalized_paid_orders_and_resolve_a_refused_one_through_the_domain(): void
    {
        $f = $this->pending();
        $this->actingAs($this->staff);
        $page = Livewire::test(ListProductionTaxRefusedOrders::class)->assertSuccessful()->assertSee($f['id']);
        $this->assertSame(['resolveRefusedOrder'], array_keys($page->instance()->getTable()->getFlatActions()));
        $record = app(StaffCommerceDirectory::class)->unfinalizedProductionOrders()->where('production_tax_checkout_orders.public_id', $f['id'])->sole();
        // Clear provider state: refused with a notice, nothing recorded.
        $page->mountTableAction('resolveRefusedOrder', $record)->callMountedTableAction()
            ->assertNotified('Resolve refused order was not recorded');
        $this->assertSame(0, RefusedOrderResolution::count());
        $this->financial->refunds = [['re_FULL', $f['gross'], 'succeeded']];
        Livewire::test(ListProductionTaxRefusedOrders::class)->mountTableAction('resolveRefusedOrder', $record)->callMountedTableAction()
            ->assertNotified('Refused order resolved');
        $this->assertSame('full_refund', RefusedOrderResolution::sole()->reason);
        // Resolved rows show the resolution and offer no second action.
        $resolved = app(StaffCommerceDirectory::class)->unfinalizedProductionOrders()->sole();
        $this->assertSame('full_refund', $resolved->getAttribute('resolution_reason'));
        Livewire::test(ListProductionTaxRefusedOrders::class)->assertSee('full refund')->assertTableActionHidden('resolveRefusedOrder', $resolved);
    }

    public function test_a_finalized_order_is_not_listed_and_customers_and_staff_without_mfa_cannot_open_the_list(): void
    {
        $f = $this->pending();
        $this->assertSame(1, app(StaffCommerceDirectory::class)->unfinalizedProductionOrders()->count());
        $this->finalizeOrder($f);
        $this->assertSame(0, app(StaffCommerceDirectory::class)->unfinalizedProductionOrders()->count());
        $this->get('/admin/unfinalized-production-orders')->assertRedirect();
        $this->actingAs(User::factory()->create());
        $this->get('/admin/unfinalized-production-orders')->assertForbidden();
        $this->actingAs($this->staff);
        $component = Livewire::test(ListProductionTaxRefusedOrders::class)->assertSuccessful();
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $component->call('$refresh')->assertForbidden();
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: false);
        }
        foreach (['create', 'update', 'delete', 'deleteAny', 'view'] as $ability) {
            $this->assertFalse(ProductionTaxRefusedOrderResource::can($ability), $ability);
        }
        // Outside the admitted environment the list is empty.
        $this->app['env'] = 'staging';
        $this->assertSame(0, app(StaffCommerceDirectory::class)->unfinalizedProductionOrders()->count());
    }
}
