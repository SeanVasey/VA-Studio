<?php

namespace Tests\Feature\ProductionCheckoutComposition;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * `vasey:production-checkout-reconcile` on the actual enrolled buyer, catalog and assent graph with synthetic provider evidence
 * (lane PCC): refusal before any query without the gate and every explicit switch, retrieval only, idempotence, retained
 * uncertainty and the lost-create locator rule.
 */
final class ProductionCheckoutReconcileCommandTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private const SWITCHES = ['production_checkout.mounted' => true, 'production_checkout.operator_reconciliation_enabled' => true,
        'production_checkout.reconciliation_enabled' => true, 'production_checkout.provider_io_enabled' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, ...self::SWITCHES]);
        Queue::fake();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function reconcile(string $order, array $options = []): array
    {
        $exit = Artisan::call('vasey:production-checkout-reconcile', ['order' => $order, ...$options]);
        $lines = array_values(array_filter(explode("\n", Artisan::output())));
        $this->assertCount(1, $lines, 'Exactly one JSON line.');

        return [$exit, json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR)];
    }

    /** The payable order with the counting decorator bound as the provider, optionally after the first hosted request. */
    private function order(bool $initiate = true): array
    {
        $f = $this->payable();
        $gateway = new CountingGateway($f['gateway']);
        $this->app->instance(ProviderGateway::class, $gateway);
        if ($initiate) {
            $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        }

        return [$f, $gateway];
    }

    private static function evidence(): string
    {
        $tables = [];
        foreach (['intent', 'session', 'observation', 'payment'] as $kind) {
            $tables[$kind] = DB::table(CheckoutSchema::TABLES[$kind])->orderBy('id')->get()->all();
        }

        return json_encode($tables, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function closed(): array
    {
        return ['unmounted' => [['production_checkout.mounted' => false]], 'mount string' => [['production_checkout.mounted' => 'true']],
            'operator off' => [['production_checkout.operator_reconciliation_enabled' => false]],
            'operator string' => [['production_checkout.operator_reconciliation_enabled' => 'true']],
            'operator null' => [['production_checkout.operator_reconciliation_enabled' => null]],
            'reconciliation off' => [['production_checkout.reconciliation_enabled' => false]],
            'provider io off' => [['production_checkout.provider_io_enabled' => false]],
            'provider io one' => [['production_checkout.provider_io_enabled' => 1]]];
    }

    #[DataProvider('closed')]
    public function test_it_refuses_before_any_query_or_provider_call_unless_the_gate_and_every_switch_are_literally_true(array $override): void
    {
        [$f, $gateway] = $this->order();
        $f['gateway']->paid = true;
        $before = self::evidence();
        $retrieves = $gateway->retrieves;
        config($override);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        [$exit, $output] = $this->reconcile($f['order']['orderId']);
        $this->assertSame(1, $exit);
        $this->assertSame(['code' => 'refused', 'reason' => isset($override['production_checkout.mounted']) ? 'not_mounted' : 'disabled'], $output);
        $this->assertSame([], $queries, 'Refused before any query.');
        $this->assertSame($retrieves, $gateway->retrieves);
        $this->assertSame($before, self::evidence());
    }

    public function test_it_refuses_in_staging_even_with_every_switch_on(): void
    {
        [$f] = $this->order();
        $this->app->instance('env', 'staging');
        try {
            $this->assertSame([1, ['code' => 'refused', 'reason' => 'not_mounted']], $this->reconcile($f['order']['orderId']));
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function domainSwitches(): array
    {
        return ['operator off' => ['production_checkout.operator_reconciliation_enabled', false],
            'operator string' => ['production_checkout.operator_reconciliation_enabled', 'true'],
            'reconciliation off' => ['production_checkout.reconciliation_enabled', false]];
    }

    /** The domain method repeats both switch checks, so a caller that skips the command's checks is refused too. */
    #[DataProvider('domainSwitches')]
    public function test_the_domain_operator_path_refuses_on_its_own_without_both_switches(string $key, mixed $value): void
    {
        [$f, $gateway] = $this->order();
        $f['gateway']->paid = true;
        $before = self::evidence();
        $retrieves = $gateway->retrieves;
        config([$key => $value]);
        foreach (['operatorReconcile', 'operatorStatus'] as $method) {
            try {
                app(HostedCheckout::class)->{$method}($f['order']['orderId']);
                $this->fail($method.' ran without its switches.');
            } catch (CheckoutException $error) {
                $this->assertSame('disabled', $error->reason, $method);
            }
        }
        $this->assertSame($retrieves, $gateway->retrieves);
        $this->assertSame($before, self::evidence());
    }

    public function test_it_retrieves_retains_a_verified_payment_and_is_idempotent(): void
    {
        [$f, $gateway] = $this->order();
        $creates = count($f['gateway']->creates);
        [$exit, $open] = $this->reconcile($f['order']['orderId']);
        $this->assertSame(0, $exit);
        $this->assertSame(['code' => 'reconciled', 'orderId' => $f['order']['orderId'], 'fundsMode' => 'test', 'paymentStatus' => 'unverified',
            'outcome' => null, 'checkoutStatus' => 'open', 'observations' => 1], $open);
        $f['gateway']->paid = true;
        [$exit, $paid] = $this->reconcile($f['order']['orderId']);
        $this->assertSame(0, $exit);
        $this->assertSame(['paymentStatus' => 'verified', 'outcome' => 'on_time', 'checkoutStatus' => 'complete', 'observations' => 2],
            array_intersect_key($paid, array_flip(['paymentStatus', 'outcome', 'checkoutStatus', 'observations'])));
        $evidence = self::evidence();
        $retrieves = $gateway->retrieves;
        // Unchanged provider state: a fresh retrieval each time, and not one new row.
        for ($run = 0; $run < 2; $run++) {
            $this->assertSame([0, $paid], $this->reconcile($f['order']['orderId']));
        }
        $this->assertSame($retrieves + 2, $gateway->retrieves);
        $this->assertSame($evidence, self::evidence());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertSame($creates, count($f['gateway']->creates), 'The operator never creates a session.');
        foreach (['license_grants', 'orders', 'checkout_intents'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // The buyer's own status reads the same retained payment.
        $this->assertSame('verified', $f['hosted']->status($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
    }

    public function test_a_provider_failure_retains_one_uncertain_observation_and_no_payment(): void
    {
        [$f, $gateway] = $this->order();
        $gateway->failRetrieve = true;
        for ($run = 0; $run < 2; $run++) {
            $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($f['order']['orderId']));
        }
        $kinds = DB::table(CheckoutSchema::TABLES['observation'])->orderBy('sequence')->pluck('kind')->all();
        $this->assertSame('uncertain', end($kinds));
        $this->assertSame(1, count(array_filter($kinds, fn (string $kind): bool => $kind === 'uncertain')));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        $gateway->failRetrieve = false;
        $f['gateway']->paid = true;
        $this->assertSame('verified', $this->reconcile($f['order']['orderId'])[1]['paymentStatus']);
    }

    public function test_a_lost_create_response_needs_a_locator_that_belongs_to_the_order(): void
    {
        [$f, $gateway] = $this->order(false);
        $f['gateway']->loseFirstResponse = true;
        try {
            $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('Lost response silently confirmed.');
        } catch (\Throwable) {
        }
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 0);
        $retrieves = $gateway->retrieves;
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'locator_required']], $this->reconcile($f['order']['orderId']));
        $this->assertSame($retrieves, $gateway->retrieves, 'No locator, no provider call.');
        // A session the provider reports for another order is refused by the retained intent binding; nothing is bound.
        $gateway->foreignSession = true;
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']],
            $this->reconcile($f['order']['orderId'], ['--session' => 'cs_test_SYNTHETIC']));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 0);
        $gateway->foreignSession = false;
        $f['gateway']->paid = true;
        [$exit, $result] = $this->reconcile($f['order']['orderId'], ['--session' => 'cs_test_SYNTHETIC']);
        $this->assertSame(0, $exit);
        $this->assertSame('verified', $result['paymentStatus']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 1);
        $this->assertCount(1, $f['gateway']->creates, 'Recovery never repeats the create.');
        // Once bound, a different locator is refused before any provider call.
        $retrieves = $gateway->retrieves;
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'locator_mismatch']],
            $this->reconcile($f['order']['orderId'], ['--session' => 'cs_test_OTHER']));
        $this->assertSame($retrieves, $gateway->retrieves);
    }

    public function test_arguments_are_exact_and_an_unknown_order_is_refused_without_detail(): void
    {
        foreach ([['order' => 'not-a-uuid'], ['order' => strtoupper('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')],
            ['order' => '11111111-1111-4111-8111-111111111111', '--session' => 'pi_SYNTHETIC'],
            ['order' => '11111111-1111-4111-8111-111111111111', '--session' => 'cs_test_'.str_repeat('a', 200)]] as $arguments) {
            $order = $arguments['order'];
            unset($arguments['order']);
            $this->assertSame([1, ['code' => 'refused', 'reason' => 'invalid']], $this->reconcile($order, $arguments));
        }
        $this->app->instance(ProviderGateway::class, new ProductionCheckoutGatewayFixture);
        [$exit, $output] = $this->reconcile('11111111-1111-4111-8111-111111111111');
        $this->assertSame(1, $exit);
        $this->assertSame('refused', $output['code']);
        $this->assertSame(['code', 'reason'], array_keys($output));
    }
}

/** Counts retrievals around the synthetic fixture; can fail them or report a session for another order. */
final class CountingGateway implements ProviderGateway
{
    public int $retrieves = 0;

    public bool $failRetrieve = false;

    public bool $foreignSession = false;

    public function __construct(private readonly ProductionCheckoutGatewayFixture $inner) {}

    public function provenance(ExecutionContextV1 $context): string
    {
        return $this->inner->provenance($context);
    }

    public function account(ExecutionContextV1 $context): array
    {
        return $this->inner->account($context);
    }

    public function create(ExecutionContextV1 $context, array $params, string $key): array
    {
        return $this->inner->create($context, $params, $key);
    }

    public function retrieve(ExecutionContextV1 $context, string $sessionId): array
    {
        $this->retrieves++;
        if ($this->failRetrieve) {
            throw new \RuntimeException('SYNTHETIC PROVIDER FAILURE PRIVATE BODY');
        }
        $session = $this->inner->retrieve($context, $sessionId);
        if ($this->foreignSession) {
            $session['client_reference_id'] = '22222222-2222-4222-8222-222222222222';
        }

        return $session;
    }

    public function paymentIntent(ExecutionContextV1 $context, string $id): array
    {
        return $this->inner->paymentIntent($context, $id);
    }
}
