<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use App\Domain\Grants\Paid\PaidGrants;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\PhpCliWrapperFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** M-16: outside the CLI, the pinned paid renderer's child runs through the validated CLI binary, unchanged otherwise. */
final class PaidGrantCliRendererTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use PhpCliWrapperFixture;
    use ProductionCheckoutJourneyFixture;

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    private function retained(): array
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['batch'] = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    public function test_the_cli_builds_the_pinned_renderer_without_a_process_factory(): void
    {
        $this->assertNull($this->factoryOf(app(PaidGrantRendererProcess::class)));
        $this->simulateFpm(PHP_BINARY);
        $this->assertInstanceOf(Closure::class, $this->factoryOf(app(PaidGrantRendererProcess::class)));
    }

    public function test_outside_the_cli_the_original_renders_through_the_configured_cli_with_the_pinned_arguments(): void
    {
        $f = $this->retained();
        $wrapper = $this->cliWrapper();
        $this->simulateFpm($wrapper);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame('complete', $complete['lines'][0]['documentStatus']);
        $manifest = PaidGrantRecords::decode((array) DB::table('paid_originals')->sole());
        $bytes = file_get_contents(Storage::disk('local')->path($manifest['artifact']['storage_path']));
        $this->assertStringStartsWith('%PDF-1.', $bytes);

        [$probe, $render] = $this->wrapperRuns($wrapper);
        $this->assertSame([$wrapper, '-n', '-r'], array_slice($probe, 0, 3));
        $this->assertSame([$wrapper, '-n'], array_slice($render, 0, 2));
        $this->assertSame(base_path('scripts/render-paid-grant.php'), end($render));
        $this->assertCount(2, $this->wrapperRuns($wrapper));
    }

    public function test_outside_the_cli_without_a_configured_cli_no_original_is_published(): void
    {
        $f = $this->retained();
        $this->simulateFpm(null);
        try {
            (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('An unconfigured CLI binary cannot render.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('render_failed', $error->reason);
        }
        $this->assertDatabaseCount('paid_originals', 0);
    }

    private function factoryOf(PaidGrantRendererProcess $renderer): ?Closure
    {
        return (new ReflectionProperty($renderer, 'processFactory'))->getValue($renderer);
    }
}
