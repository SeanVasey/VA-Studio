<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Codex P1 r4210180698: after proveCreatable()'s admitted frame commits, an ordinary framework
 * transaction (the trailing current-credential check) committed before gateway->create(). A
 * committing listener on it withdrew the offer and create still ran. Safe outcome: no framework
 * transaction commits between the admitted re-proof and create, and the offer is active at create.
 * Synthetic gateway fixture only.
 */
final class TerminalReproofCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    public function test_no_unadmitted_commit_between_the_admitted_reproof_and_provider_create(): void
    {
        $f = $this->payable();
        $trackId = $f['catalog']['items'][0]['trackId'];
        $state = ['armed' => false, 'observed' => false, 'admitted' => false, 'after' => 0, 'acted' => 0, 'at_create' => null];
        app('events')->listen(TransactionCommitting::class, function () use (&$state, $trackId): void {
            $state['observed'] = DB::connection()->getEventDispatcher() instanceof CheckoutCommandCommitDispatcher;
            if ($state['admitted'] && ! $state['observed'] && $state['at_create'] === null) {
                $state['acted']++;
                DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]);
            }
        });
        app('events')->listen(TransactionCommitted::class, function () use (&$state): void {
            if ($state['armed'] && $state['at_create'] === null) {
                if ($state['observed']) {
                    $state['admitted'] = true;
                    $state['after'] = 0;
                } elseif ($state['admitted']) {
                    $state['after']++;
                }
            }
        });
        $f['hosted'] = new HostedCheckout($f['access'], new TerminalProbeGateway($f['gateway'], function () use (&$state): void {
            $state['armed'] = true;
        }, function () use (&$state, $trackId): void {
            $state['at_create'] = DB::table('offers')->where('track_id', $trackId)->where('is_active', true)->count();
        }));
        $error = null;
        try {
            $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        } catch (\Throwable $thrown) {
            $error = $thrown::class;
        }
        $snapshot = [...$state, 'driver' => DB::getDriverName(), 'error' => $error, 'provider_creates' => count($f['gateway']->creates)];
        $directory = sys_get_temp_dir().'/va-checkout-terminal-reproof';
        @mkdir($directory, 0700, true);
        file_put_contents($directory.'/terminal-offer.json', json_encode($snapshot, JSON_PRETTY_PRINT)."\n");
        $this->assertTrue($state['admitted']);
        $this->assertSame(0, $state['after'], 'A framework transaction committed between the admitted re-proof and create.');
        $this->assertSame(0, $state['acted']);
        $this->assertSame(1, $state['at_create'], 'The offer was withdrawn before create.');
    }
}

/** Delegates to the synthetic fixture; arms at provenance() and probes the database at create(). */
final class TerminalProbeGateway implements ProviderGateway
{
    private bool $armed = false;

    public function __construct(private readonly ProductionCheckoutGatewayFixture $inner, private readonly \Closure $arm, private readonly \Closure $probe) {}

    public function provenance(ExecutionContextV1 $context): string
    {
        if (! $this->armed) {
            $this->armed = true;
            ($this->arm)();
        }

        return $this->inner->provenance($context);
    }

    public function account(ExecutionContextV1 $context): array
    {
        return $this->inner->account($context);
    }

    public function create(ExecutionContextV1 $context, array $params, string $key): array
    {
        ($this->probe)();

        return $this->inner->create($context, $params, $key);
    }

    public function retrieve(ExecutionContextV1 $context, string $sessionId): array
    {
        return $this->inner->retrieve($context, $sessionId);
    }

    public function paymentIntent(ExecutionContextV1 $context, string $paymentId): array
    {
        return $this->inner->paymentIntent($context, $paymentId);
    }
}
