<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\DeliveryRace;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestOwnerDeliveryConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Owner delivery races require independent MySQL processes.'); }
    }

    public function test_two_independent_issuers_that_both_verify_bytes_compete_for_one_remaining_rolling_budget_slot(): void
    {
        $f = $this->ready(); $input = $this->input($f, 'issue'); $before = F::retained();
        foreach ([0, 1] as $attempt) {
            app(IssueTestDelivery::class)->handle($input['order_public_id'], $input['owner_key'], $input['grant_public_id'], 'contract', (string) Str::uuid());
        }
        $this->assertSame(2, TestDeliveryAuthorization::count());
        $results = DeliveryRace::run($this, $input); $outcomes = array_column($results, 'outcome'); sort($outcomes);
        $this->assertSame(['denied', 'issued'], $outcomes);
        $this->assertSame('budget_exhausted', collect($results)->firstWhere('outcome', 'denied')['reason']);
        $this->assertSame(3, TestDeliveryAuthorization::count()); $this->assertSame(0, TestDeliveryRedemption::count());
        $this->assertSame(3, DB::table('audit_events')->where('action', 'commerce.delivery.authorized')->count());
        $winner = collect($results)->firstWhere('outcome', 'issued');
        $this->assertTrue(TestDeliveryAuthorization::where('public_id', $winner['authorization_id'])->exists());
        $this->assertSame($before, F::retained());
    }

    public function test_two_independent_redeemers_that_hold_verified_snapshots_commit_only_one_stream_attempt(): void
    {
        $f = $this->ready(); $input = $this->input($f, 'redeem'); $before = F::retained();
        $issued = app(IssueTestDelivery::class)->handle($input['order_public_id'], $input['owner_key'], $input['grant_public_id'], 'contract', (string) Str::uuid());
        $input += ['authorization_public_id' => $issued->authorizationId, 'token' => $issued->token()];
        $results = DeliveryRace::run($this, $input); $outcomes = array_column($results, 'outcome'); sort($outcomes);
        $this->assertSame(['denied', 'redeemed'], $outcomes);
        $this->assertSame('redeemed', collect($results)->firstWhere('outcome', 'denied')['reason']);
        $streams = array_values(array_filter($results, fn ($result) => $result['content_hash'] !== null));
        $this->assertCount(1, $streams); $original = GrantContract::where('license_grant_id', $f['grants'][0]->id)->sole();
        $this->assertSame($original->pdf_hash, $streams[0]['content_hash']); $this->assertSame($original->size_bytes, $streams[0]['size_bytes']);
        $this->assertSame(1, TestDeliveryAuthorization::count()); $redemption = TestDeliveryRedemption::sole();
        $this->assertSame($original->pdf_hash, $redemption->content_hash); $this->assertSame($original->size_bytes, $redemption->size_bytes);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.delivery.redeemed')->count());
        $this->assertSame($before, F::retained());
    }

    public function test_independent_block_commits_while_redemption_holds_its_verified_snapshot_and_prevents_consumption(): void
    {
        $f = $this->ready(); $input = $this->input($f, 'block_redemption'); $before = F::retained();
        $issued = app(IssueTestDelivery::class)->handle($input['order_public_id'], $input['owner_key'], $input['grant_public_id'], 'contract', (string) Str::uuid());
        $input += ['authorization_public_id' => $issued->authorizationId, 'token' => $issued->token()];
        $results = DeliveryRace::run($this, $input);
        $this->assertSame(['denied', 'blocked'], array_column($results, 'outcome'));
        $this->assertSame('blocked', $results[0]['reason']); $this->assertNull($results[1]['reason']);
        $this->assertSame([null, null], array_column($results, 'content_hash'));
        $control = TestDeliveryControl::sole(); $this->assertTrue($control->blocked); $this->assertSame(2, $control->control_version);
        $this->assertSame(1, TestDeliveryAuthorization::count()); $this->assertSame(0, TestDeliveryRedemption::count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'commerce.delivery.redeemed')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'commerce.delivery.control_changed')->count());
        $this->assertSame($before, F::retained());
    }

    private function ready(): array
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway); $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        return F::ready($gateway);
    }

    private function input(array $fixture, string $mode): array
    {
        return ['mode' => $mode, 'order_id' => $fixture['order']->id, 'order_public_id' => $fixture['order']->public_id,
            'owner_key' => InventoryFixtures::OWNER, 'grant_public_id' => $fixture['grants'][0]->public_id, 'now' => now()->toIso8601ZuluString()];
    }
}
