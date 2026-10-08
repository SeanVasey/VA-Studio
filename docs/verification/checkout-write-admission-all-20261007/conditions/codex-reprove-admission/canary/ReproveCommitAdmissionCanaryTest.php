<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * Codex P1 r4210033214: a TransactionCommitting listener on proveCreatable()'s own commit deactivates
 * the offer after its read-only proofs. The safe outcome is a refusal before the first provider create.
 * Synthetic gateway fixture only; no real provider call is possible.
 */
final class ReproveCommitAdmissionCanaryTest extends TestCase
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

    public function test_offer_withdrawn_in_the_reprove_commit_refuses_before_provider_create(): void
    {
        $f = $this->payable();
        $trackId = $f['catalog']['items'][0]['trackId'];
        $armed = false;
        $acted = 0;
        // The first commit after the gateway's provenance() is proveCreatable()'s own frame.
        app('events')->listen(TransactionCommitting::class, function () use (&$armed, &$acted, $trackId): void {
            if ($armed && $acted === 0) {
                $acted++;
                DB::table('offers')->where('track_id', $trackId)->update(['is_active' => false]);
            }
        });
        $f['hosted'] = new HostedCheckout($f['access'], new ReproveArmingGateway($f['gateway'], function () use (&$armed): void {
            $armed = true;
        }));
        $refused = null;
        try {
            $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        } catch (\Throwable $error) {
            $refused = $error instanceof CheckoutException ? 'CheckoutException:'.$error->reason : $error::class;
        }
        $snapshot = ['driver' => DB::getDriverName(), 'acted' => $acted, 'refused' => $refused, 'provider_creates' => count($f['gateway']->creates),
            'sessions' => DB::table(CheckoutSchema::TABLES['session'])->count(),
            'inactive_offers' => DB::table('offers')->where('track_id', $trackId)->where('is_active', false)->count()];
        $directory = sys_get_temp_dir().'/va-checkout-reprove-admission';
        @mkdir($directory, 0700, true);
        file_put_contents($directory.'/reprove-offer.json', json_encode($snapshot, JSON_PRETTY_PRINT)."\n");
        $this->assertSame(1, $acted);
        $this->assertSame([], $f['gateway']->creates);
        $this->assertSame('CheckoutException:write_source_changed', $refused);
        $this->assertSame(0, $snapshot['sessions']);
        $this->assertSame(0, $snapshot['inactive_offers']);
    }
}

/** Delegates to the synthetic fixture; arms the listener at the first gateway contact of this initiate(). */
final class ReproveArmingGateway implements ProviderGateway
{
    private bool $armed = false;

    public function __construct(private readonly ProductionCheckoutGatewayFixture $inner, private readonly \Closure $arm) {}

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
