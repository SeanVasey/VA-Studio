<?php

namespace Tests\Feature;

use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\ReadGrantContract;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Regression: a work transition is stamped with the decision second, and the wall clock can cross into the next
 * second before the row is saved. The stored work timestamp must stay exactly the decision second, because the
 * original document and the database guards compare it for equality (issued_at) or derive leases/backoff from it.
 */
final class TestContractSecondBoundaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;
    private ContractRenderer $renderer;
    private CarbonImmutable $second;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        // Fixtures, request, claim and decision all fall in one second, 200 ms after its start.
        $this->second = CarbonImmutable::now()->utc()->startOfSecond();
        $this->travelTo($this->second->addMilliseconds(200)); F::configure(); Queue::fake();
        $this->gateway = PaymentFixtures::gateway(); $this->renderer = F::renderer();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, $this->renderer);
    }

    /** Move the clock 900 ms (into the next second) once, while the transition to $state is being saved. */
    private function crossSecondWhileSaving(string $state): void
    {
        $crossed = false;
        ContractRenderWork::saving(function (ContractRenderWork $work) use ($state, &$crossed): void {
            if (! $crossed && $work->state === $state && $work->isDirty('state')) {
                $crossed = true; $this->travelTo(now()->addMilliseconds(900));
            }
        });
    }

    private function request(): array
    {
        $f = F::paid($this->gateway);

        return [$f, app(RequestTestContract::class)->handle($f['grant']->id)];
    }

    public function test_same_second_completion_without_a_crossing_issues(): void
    {
        [, $request] = $this->request();
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertTrue(GrantContract::sole()->issued_at->equalTo($this->second));
        $this->assertTrue(ContractRenderWork::sole()->updated_at->equalTo($this->second));
    }

    public function test_completion_crossing_a_second_before_its_save_issues_with_issued_at_equal_to_work_updated_at(): void
    {
        [$f, $request] = $this->request();
        $this->crossSecondWhileSaving('completed');
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertTrue(now()->greaterThanOrEqualTo($this->second->addSecond()), 'The clock must have crossed the second.');
        $work = ContractRenderWork::sole(); $contract = GrantContract::sole();
        $this->assertSame('completed', $work->state); $this->assertNull($work->reason); $this->assertSame(1, $work->attempts);
        $this->assertTrue($contract->issued_at->equalTo($this->second));
        $this->assertTrue($work->updated_at->equalTo($contract->issued_at));
        $this->assertSame($contract->id, app(ReadGrantContract::class)->forRequest(ContractRenderRequest::findOrFail($request->id))->id);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.contract.test_issued')->count());
        $view = app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('issued', $view['contractStatus']);
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id)); $this->assertCount(1, $this->renderer->calls);
    }

    public function test_claim_crossing_a_second_before_its_save_keeps_the_lease_bound_to_the_claim_second(): void
    {
        [, $request] = $this->request();
        $leases = [];
        ContractRenderWork::saved(function (ContractRenderWork $work) use (&$leases): void {
            if ($work->state === 'processing') { $leases[] = [$work->updated_at, $work->lease_expires_at]; }
        });
        $this->crossSecondWhileSaving('processing');
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertCount(1, $leases);
        $this->assertTrue($leases[0][0]->equalTo($this->second));
        $this->assertTrue($leases[0][1]->equalTo($this->second->addSeconds(300)));
        $stored = DB::table('contract_render_work')->sole();
        $this->assertSame('completed', $stored->state); $this->assertSame(1, (int) $stored->attempts);
        $this->assertTrue(GrantContract::sole()->issued_at->equalTo($this->second->addSecond()));
        $this->assertTrue(ContractRenderWork::sole()->updated_at->equalTo(GrantContract::sole()->issued_at));
    }

    public function test_transient_failure_crossing_a_second_before_its_save_schedules_the_retry_from_the_failure_second(): void
    {
        [, $request] = $this->request();
        $this->renderer->onRender = fn () => throw new ContractIssuanceException('render_failed');
        $this->crossSecondWhileSaving('retry');
        $this->assertSame('retry', app(RenderTestContract::class)->handle($request->id));
        $work = ContractRenderWork::sole();
        $this->assertSame('retry', $work->state); $this->assertSame('render_failed', $work->reason);
        $this->assertNull($work->claim_token); $this->assertNull($work->lease_expires_at);
        $this->assertTrue($work->updated_at->equalTo($this->second));
        $this->assertTrue($work->next_attempt_at->equalTo($this->second->addSeconds(60)));
        $this->assertDatabaseCount('grant_contracts', 0);
        $this->travelTo($work->next_attempt_at); $this->renderer->onRender = null;
        $this->assertSame('ready', app(RenderTestContract::class)->handle($request->id));
        $this->assertSame(2, ContractRenderWork::sole()->attempts); $this->assertDatabaseCount('grant_contracts', 1);
    }

    public function test_permanent_failure_crossing_a_second_before_its_save_records_the_failure_second(): void
    {
        [, $request] = $this->request();
        $this->renderer->onRender = fn () => throw new ContractIssuanceException('unsupported_input');
        $this->crossSecondWhileSaving('quarantined');
        $this->assertSame('quarantined', app(RenderTestContract::class)->handle($request->id));
        $work = ContractRenderWork::sole();
        $this->assertSame('quarantined', $work->state); $this->assertSame('unsupported_input', $work->reason);
        $this->assertTrue($work->updated_at->equalTo($this->second));
        $this->assertDatabaseCount('grant_contracts', 0);
    }
}
