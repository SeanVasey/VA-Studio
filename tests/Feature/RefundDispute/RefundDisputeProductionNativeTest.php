<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\RefundDisputePolicy;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxDownloads;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionTaxFinancialFixtures;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\Support\RefundDisputeRace;
use Tests\TestCase;

/**
 * BL-42 independent-process MySQL races on the production tax chain: a refund/dispute writer against the buyer's download frame, and
 * two staff observing one order. Each writer holds the batch row (`paid_tax_order_origins`), the row the download frame locks before
 * it reads a hold. SQLite cannot establish these lock orders and skips them.
 */
class RefundDisputeProductionNativeTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    private const WORKER = 'refund-dispute-production-race-worker.php';

    private const ORIGINALS = ['production_tax_checkout_orders', 'production_tax_checkout_requests', 'production_tax_checkout_bindings',
        'production_tax_checkout_reviewed_sessions', 'paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_document_work',
        'paid_tax_originals', 'paid_tax_fulfillments'];

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Production refund/dispute races require independent MySQL processes.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        config(['paid-tax-grants.downloads_enabled' => true, 'refund-dispute.enabled' => true, 'payments.stripe.mode' => 'test',
            'payments.stripe.account_id' => 'acct_SYNTHETIC']);
    }

    /** The shared synthetic machine, retaining the DD-16 refund choices this lifecycle accepts. */
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

    /** A finalized synthetic production tax order; documents are prepared only when a download is part of the race. */
    private function order(bool $prepare): array
    {
        $f = $this->paidTaxOrder();
        // The finalize guard reads the provider while the lifecycle is admitted; this clean synthetic provider reports no refund or dispute.
        $reviewed = DB::table('production_tax_checkout_reviewed_sessions')->first();
        $this->app->instance(StripeFinancialInspectionGateway::class,
            ProductionTaxFinancialFixtures::gateway('acct_SYNTHETIC', (string) $reviewed->provider_payment_id, (int) $reviewed->amount_total_minor));
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        if ($prepare) {
            $f['batch'] = (new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        }
        $row = DB::table('paid_tax_order_origins')->where('public_id', $f['batch']['id'])->first();
        $f['id'] = $f['batch']['id'];
        $f['row'] = (int) $row->id;
        $f['payment'] = $row->provider_payment_id;
        $f['gross'] = $f['batch']['lines'][0]['tax']['order_total_minor'];
        $f['staff'] = [LicenseFixtures::admin(), LicenseFixtures::admin()];

        return $f;
    }

    private function input(array $f, array $roles, array $pause, array $refunds, array $disputes, array $extra = []): array
    {
        return $extra + ['roles' => $roles, 'pause' => $pause, 'account' => 'acct_SYNTHETIC', 'payment' => $f['payment'], 'gross' => $f['gross'],
            'refunds' => $refunds, 'disputes' => $disputes, 'order_id' => $f['id'], 'line_id' => $f['batch']['lines'][0]['id'],
            'staff_ids' => [$f['staff'][0]->id, $f['staff'][1]->id], 'sequence' => 0,
            'config' => ['production-customer-identity' => config('production-customer-identity'), 'production-tax-checkout' => config('production-tax-checkout'),
                'paid-tax-grants' => config('paid-tax-grants'), 'refund-dispute.enabled' => true, 'payments.stripe.mode' => 'test',
                'payments.stripe.account_id' => 'acct_SYNTHETIC']];
    }

    private function originals(): array
    {
        $rows = [];
        foreach (self::ORIGINALS as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    private function outcomes(array $race): array
    {
        return array_map(fn (array $result): array => [$result['outcome'], $result['reason']], $race['results']);
    }

    public function test_a_committed_dispute_hold_wins_the_batch_row_and_refuses_a_waiting_download_authorization(): void
    {
        $f = $this->order(prepare: true);
        $before = $this->originals();
        $input = $this->input($f, ['observe', 'authorize'], ['commerce.refund_dispute.dispute_hold_applied', null], [], [['du_ONE', $f['gross'], 'needs_response']],
            ['request' => ['requestKey' => (string) Str::uuid(), 'originHash' => $f['batch']['lines'][0]['originHash'], 'kind' => 'master_wav',
                'nonce' => bin2hex(random_bytes(32))]]);
        $race = RefundDisputeRace::run($this, $input, 'paid_tax_order_origins', $f['row'], self::WORKER);
        $this->assertSame([['observed', null], ['denied', 'refund_dispute_hold']], $this->outcomes($race));
        $this->assertSame(409, $race['results'][1]['status']);
        $this->assertSame('paid_tax_order_origins', $race['wait']['object_name']);
        $this->assertSame(['production_tax', 'dispute_reconciling'], [RefundDisputeHold::whereNull('released_at')->sole()->order_source,
            RefundDisputeHold::whereNull('released_at')->sole()->reason]);
        $this->assertSame(0, DB::table('paid_tax_authorizations')->count());
        $this->assertSame($before, $this->originals());
    }

    public function test_a_committed_dispute_hold_refuses_the_waiting_redemption_of_an_authorization_issued_before_it(): void
    {
        $f = $this->order(prepare: true);
        $row = $f['batch']['lines'][0];
        $authorization = (new PaidTaxDownloads)->authorize($f['id'], $row['id'], ['requestKey' => (string) Str::uuid(), 'originHash' => $row['originHash'],
            'kind' => 'master_wav', 'nonce' => bin2hex(random_bytes(32))], $f['buyer']['principal'], $f['buyer']['user']);
        $before = $this->originals();
        $input = $this->input($f, ['observe', 'redeem'], ['commerce.refund_dispute.dispute_hold_applied', null], [], [['du_ONE', $f['gross'], 'needs_response']],
            ['authorization_id' => $authorization['id'], 'token' => $authorization['token']]);
        $race = RefundDisputeRace::run($this, $input, 'paid_tax_order_origins', $f['row'], self::WORKER);
        $this->assertSame([['observed', null], ['denied', 'refund_dispute_hold']], $this->outcomes($race));
        $this->assertSame('paid_tax_order_origins', $race['wait']['object_name']);
        // The authorization was never revoked or spent: it is still the one issued row and no attempt was recorded.
        $this->assertSame(1, DB::table('paid_tax_authorizations')->count());
        $this->assertSame(0, DB::table('paid_tax_redemptions')->count());
        $this->assertSame($before, $this->originals());
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
    }

    public function test_two_staff_observing_one_order_serialize_and_the_second_finds_the_sequence_moved(): void
    {
        $f = $this->order(prepare: false);
        $input = $this->input($f, ['observe', 'observe'], ['commerce.refund_dispute.observed', null], [['re_ONE', 500, 'succeeded']], []);
        $race = RefundDisputeRace::run($this, $input, 'paid_tax_order_origins', $f['row'], self::WORKER);
        $this->assertSame([['observed', null], ['denied', 'stale']], $this->outcomes($race));
        $this->assertSame('paid_tax_order_origins', $race['wait']['object_name']);
        $this->assertSame(1, RefundDisputeObservation::count());
        $this->assertSame(1, RefundDisputeObject::count());
        $this->assertSame([1], DB::table('refund_dispute_work')->pluck('sequence')->map(fn ($sequence): int => (int) $sequence)->all());
        $this->assertSame(['production_tax'], DB::table('refund_dispute_work')->pluck('order_source')->unique()->values()->all());
    }
}
