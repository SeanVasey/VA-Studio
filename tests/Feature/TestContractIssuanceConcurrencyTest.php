<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\RequestTestContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixtures as F;
use Tests\Support\ContractRace;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestContractIssuanceConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Contract issuance races require independent MySQL processes.'); }
    }

    private function contractRaceInput(bool $request = true): array
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway); $this->app->instance(StripePaymentGateway::class, $gateway);
        $f = F::paid($gateway);
        $renderRequest = $request ? app(RequestTestContract::class)->handle($f['grant']->id) : null;
        $input = ['grant_id' => $f['grant']->id, 'request_id' => $renderRequest?->id, 'now' => now()->toIso8601ZuluString()];

        return [$input, FinalizationFixtures::retained()];
    }

    public function test_two_request_creators_converge_on_one_original_document_identity(): void
    {
        [$input, $before] = $this->contractRaceInput(false);
        $results = ContractRace::run($this, [$input, $input], 'duplicate_request');
        $this->assertSame(['requested', 'requested'], array_column($results, 'outcome'));
        $this->assertSame($results[0]['request_id'], $results[1]['request_id']);
        $this->assertSame(ContractRenderRequest::sole()->id, $results[0]['request_id']);
        $this->assertDatabaseCount('grant_contracts', 0); $this->assertSame($before, FinalizationFixtures::retained());
    }

    public function test_two_workers_share_one_active_claim_and_one_original_document(): void
    {
        [$input, $before] = $this->contractRaceInput();
        $results = ContractRace::run($this, [$input, $input], 'same_request');
        $this->assertSame(['ready', 'busy'], array_column($results, 'outcome'));
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertSame(1, ContractRenderWork::sole()->attempts);
        $this->assertSame('completed', ContractRenderWork::sole()->state);
        $this->assertSame($before, FinalizationFixtures::retained());
        $this->assertSame(GrantContract::sole()->pdf_hash, hash('sha256', app(ContractFiles::class)->verify(GrantContract::sole())));
    }

    public function test_lease_takeover_fences_a_stale_successful_renderer_from_publishing(): void
    {
        [$input, $before] = $this->contractRaceInput();
        $successor = array_replace($input, ['now' => now()->addSeconds(301)->toIso8601ZuluString()]);
        $results = ContractRace::run($this, [$input, $successor], 'stale_success');
        $this->assertSame(['stale', 'ready'], array_column($results, 'outcome'));
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertSame('completed', ContractRenderWork::sole()->state);
        $this->assertSame(2, ContractRenderWork::sole()->attempts); $this->assertNull(ContractRenderWork::sole()->reason);
        $this->assertSame($before, FinalizationFixtures::retained());
        $this->assertSame(GrantContract::sole()->pdf_hash, hash('sha256', app(ContractFiles::class)->verify(GrantContract::sole())));
    }

    public function test_stale_renderer_failure_cannot_regress_successor_completed_work(): void
    {
        [$input, $before] = $this->contractRaceInput();
        $successor = array_replace($input, ['now' => now()->addSeconds(301)->toIso8601ZuluString()]);
        $results = ContractRace::run($this, [array_replace($input, ['fail' => true]), $successor], 'stale_failure');
        $this->assertSame(['stale', 'ready'], array_column($results, 'outcome'));
        $this->assertDatabaseCount('grant_contracts', 1); $this->assertSame('completed', ContractRenderWork::sole()->state);
        $this->assertSame(2, ContractRenderWork::sole()->attempts); $this->assertNull(ContractRenderWork::sole()->reason);
        $this->assertNull(ContractRenderWork::sole()->next_attempt_at); $this->assertSame($before, FinalizationFixtures::retained());
    }
}
