<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawal;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawalReader;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureTransaction;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

/** Review finding C2: the withdrawal reader is sealed to a context minted by a live run callback. */
class ProductionFeatureSealedRunTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_reviewer_probe_context_minted_outside_run_cannot_read_the_withdrawal(): void
    {
        $owner = $this->withdrawn();
        $events = $this->events();
        foreach ([fn (ProductionFeatureContext $context) => (new ProductionConsentWithdrawalReader)->read($context, 1),
            fn (ProductionFeatureContext $context) => ProductionConsentWithdrawal::capture($context, 1)] as $consumer) {
            $result = 'not-called';
            DB::beginTransaction();
            try {
                // The reviewer's probe: a caller-owned level-1 transaction with the public mint path.
                $connection = DB::connection();
                $frame = new ProductionFeatureTransaction($connection);
                $reader = new CurrentRows($connection->getPdo(), $connection->getDriverName());
                $context = ProductionFeatureContext::locked($owner, new ProductionAccountFeatureAccess, $reader, $frame,
                    hrtime(true) + 10_000_000_000, new ProductionFeatureConfiguration);
                try {
                    $result = $consumer($context);
                } catch (ProductionFeatureException $error) {
                    $result = $error->status;
                }
            } finally {
                DB::rollBack();
            }
            $this->assertSame(503, $result, 'A context outside run must not release the private withdrawal capture.');
        }
        $this->assertSame($events, $this->events());
    }

    public function test_context_leaked_from_a_completed_run_cannot_read_the_withdrawal(): void
    {
        $owner = $this->withdrawn();
        $leaked = null;
        (new ProductionFeatureOperation)->run($owner, function (ProductionFeatureContext $context) use (&$leaked): array {
            $leaked = $context;

            return [];
        });
        $this->assertInstanceOf(ProductionFeatureContext::class, $leaked);
        DB::beginTransaction();
        try {
            (new ProductionConsentWithdrawalReader)->read($leaked, 1);
            $this->fail('A completed run context is not a live sealed operation.');
        } catch (Throwable $error) {
            $this->assertInstanceOf(ProductionFeatureException::class, $error);
        } finally {
            DB::rollBack();
        }
    }

    public function test_reader_inside_the_run_callback_still_captures(): void
    {
        $owner = $this->withdrawn();
        $captured = (new ProductionFeatureOperation)->run($owner, fn (ProductionFeatureContext $context): array => ['withdrawal' => (new ProductionConsentWithdrawalReader)->read($context, 1)])['withdrawal'];
        $this->assertInstanceOf(ProductionConsentWithdrawal::class, $captured);
        $this->assertSame('feature-owner@example.test', $captured->serverSnapshot()['recipient']);
    }

    private function withdrawn(): ProductionAccountFeatureIdentity
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());

        return $owner;
    }

    private function events(): array
    {
        return DB::table('production_consent_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
