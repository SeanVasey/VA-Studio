<?php

namespace Tests\Feature\RefundDispute;

use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\Support\RefundDisputeRace;
use Tests\TestCase;

/** Independent-process MySQL races for A5 holds. SQLite cannot establish these lock orders and skips them. */
class RefundDisputeConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const OTHER_OWNER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private object $payments;

    private object $financial;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Refund/dispute races require independent MySQL processes.');
        }
    }

    private function refunded(): array
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        R::configure();
        Queue::fake();
        [$this->payments, $this->financial] = R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        $f = DeliveryFixtures::ready($this->payments);
        $f['staff'] = LicenseFixtures::admin();
        R::provider($this->financial, [['re_FULL', $f['payment']->amount_minor, 'succeeded']]);
        R::observe($f['order']->public_id, $f['staff']);

        return $f;
    }

    private function input(array $f, array $roles, array $pause): array
    {
        return ['roles' => $roles, 'pause' => $pause, 'policy' => R::POLICY, 'now' => now()->toIso8601ZuluString(),
            'staff_id' => $f['staff']->id, 'order_public_id' => $f['order']->public_id, 'grant_public_id' => $f['grant']->public_id,
            'owner_key' => InventoryFixtures::OWNER, 'sequence' => R::sequence($f['order']->public_id, $f['staff']),
            'competing_owner' => self::OTHER_OWNER, 'items' => $f['items'] ?? []];
    }

    public function test_a_committed_suspension_wins_the_order_row_and_refuses_a_waiting_download_authorization(): void
    {
        $f = $this->refunded();
        $before = R::originals();
        $race = RefundDisputeRace::run($this, $this->input($f, ['suspend', 'issue'], ['commerce.refund_dispute.refund_suspension_applied', null]),
            'orders', $f['order']->id);
        $this->assertSame([['suspended', null], ['denied', 'blocked']], array_map(fn ($r) => [$r['outcome'], $r['reason']], $race['results']));
        $this->assertSame('orders', $race['wait']['object_name']);
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
        $this->assertSame(0, TestDeliveryAuthorization::count());
        $this->assertSame($before, R::originals());
    }

    public function test_an_authorization_that_commits_first_is_followed_by_a_waiting_suspension_that_refuses_further_authorization(): void
    {
        $f = $this->refunded();
        $race = RefundDisputeRace::run($this, $this->input($f, ['issue', 'suspend'], ['commerce.delivery.authorized', null]),
            'orders', $f['order']->id);
        $this->assertSame([['issued', null], ['suspended', null]], array_map(fn ($r) => [$r['outcome'], $r['reason']], $race['results']));
        $authorization = TestDeliveryAuthorization::sole();
        $this->assertSame(1, RefundDisputeHold::whereNull('released_at')->count());
        // The raw token stayed in the worker; redemption refusal after a hold is covered by the lifecycle suite.
        try {
            app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grant']->public_id, 'contract', (string) Str::uuid());
            $this->fail('A held grant issued a new authorization.');
        } catch (DeliveryException $error) {
            $this->assertSame('blocked', $error->reason);
        }
        $this->assertSame(1, TestDeliveryAuthorization::count());
        $this->assertSame(0, DB::table('test_delivery_redemptions')->count());
        $this->assertSame($authorization->id, TestDeliveryAuthorization::sole()->id);
    }

    public function test_a_qualified_exclusive_reopening_decision_never_admits_a_competing_exclusive_purchase(): void
    {
        $f = $this->refunded();
        app(RefundDisputeActions::class)->suspendLine($f['order']->public_id, $f['staff'], (string) Str::uuid(),
            R::sequence($f['order']->public_id, $f['staff']), $f['grant']->public_id);
        $reservations = DB::table('inventory_reservations')->count();
        $race = RefundDisputeRace::run($this, $this->input($f, ['qualify', 'purchase'], ['commerce.refund_dispute.exclusive_reopening_qualified', null]), null, null);
        $this->assertSame('qualified', $race['results'][0]['outcome']);
        $this->assertSame('denied', $race['results'][1]['outcome']);
        $this->assertIsString($race['results'][1]['reason']);
        $this->assertSame(1, ExclusiveSale::count());
        $this->assertSame($reservations, DB::table('inventory_reservations')->count());
    }

    public function test_writer_latency_sits_well_inside_the_lease_and_authorization_windows(): void
    {
        $f = $this->refunded();
        $timings = ['observe' => [], 'suspend_release' => []];
        for ($i = 0; $i < 15; $i++) {
            $start = hrtime(true);
            R::observe($f['order']->public_id, $f['staff']);
            $timings['observe'][] = (hrtime(true) - $start) / 1e6;
            $start = hrtime(true);
            app(RefundDisputeActions::class)->suspendLine($f['order']->public_id, $f['staff'], (string) Str::uuid(),
                R::sequence($f['order']->public_id, $f['staff']), $f['grant']->public_id);
            $hold = RefundDisputeHold::whereNull('released_at')->sole();
            app(RefundDisputeActions::class)->releaseHold($f['order']->public_id, $f['staff'], (string) Str::uuid(),
                R::sequence($f['order']->public_id, $f['staff']), $hold->public_id);
            $timings['suspend_release'][] = (hrtime(true) - $start) / 1e6;
        }
        $summary = [];
        foreach ($timings as $name => $values) {
            sort($values);
            $summary[$name] = ['n' => count($values), 'p50_ms' => round($values[intdiv(count($values), 2)], 1), 'max_ms' => round(max($values), 1)];
            // Budgets: the observation lease is 120 s and a download authorization lives 60 s.
            $this->assertLessThan(5000, max($values), $name);
        }
        if (is_string($path = getenv('VASEY_A5_TIMINGS')) && $path !== '') {
            file_put_contents($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->assertSame(46, R::sequence($f['order']->public_id, $f['staff']));
        $this->assertSame(16, RefundDisputeObservation::where('order_id', $f['order']->id)->count());
    }
}
