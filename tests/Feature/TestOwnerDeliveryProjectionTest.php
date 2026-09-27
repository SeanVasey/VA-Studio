<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\IssuedTestDelivery;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\ReadTestOwnerDelivery;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Domain\Media\Models\MediaAsset;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestOwnerDeliveryProjectionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;
    private PrepareTestDeliveryStream $streams;

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $this->gateway = PaymentFixtures::gateway(); $this->streams = F::observingStreams();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway); $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer()); $this->app->instance(PrepareTestDeliveryStream::class, $this->streams);
    }

    private function read(array $fixture): array
    {
        return app(ReadTestOwnerDelivery::class)->handle($fixture['order']->public_id, InventoryFixtures::OWNER);
    }

    private function issue(array $fixture, string $kind = 'contract'): IssuedTestDelivery
    {
        return app(IssueTestDelivery::class)->handle($fixture['order']->public_id, InventoryFixtures::OWNER,
            $fixture['grant']->public_id, $kind, (string) Str::uuid());
    }

    private function fails(string $reason, callable $action): void
    {
        try { $action(); $this->fail('Corrupt or foreign delivery projection was returned.'); }
        catch (DeliveryException $error) { $this->assertSame($reason, $error->reason); }
    }

    public function test_ownership_is_required_before_policy_or_retained_graph_is_consulted(): void
    {
        $f = F::ready($this->gateway); config(['payments.stripe.mode' => 'live']); $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        foreach ([$f['order']->public_id, (string) Str::uuid(), 'not-an-order'] as $order) {
            foreach ([str_repeat('b', 64), '', 'invalid'] as $owner) {
                $this->fails('not_found', fn () => app(ReadTestOwnerDelivery::class)->handle($order, $owner));
            }
        }
        $this->assertStringNotContainsString('test_delivery', implode("\n", $queries));
        $this->assertStringNotContainsString('test_fulfillment', implode("\n", $queries));
        $this->assertSame([], $this->streams->transactionLevels);
    }

    public function test_unactivated_owned_order_returns_empty_unavailable_without_creating_any_delivery_records(): void
    {
        $f = ActivationFixtures::issued($this->gateway); $before = F::retained();
        $this->assertSame(['deliverySchema' => 1, 'orderId' => $f['order']->public_id, 'testOnly' => true,
            'status' => 'unavailable', 'items' => [], 'history' => [], 'historyLimit' => 20, 'historyHasMore' => false], $this->read($f));
        foreach (['test_fulfillment_activations', 'test_delivery_controls', 'test_delivery_authorizations', 'test_delivery_redemptions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, F::retained()); $this->assertSame([], $this->streams->transactionLevels);
    }

    public function test_all_exact_retained_roles_have_only_frozen_public_metadata_in_fixed_order(): void
    {
        $f = F::allRoles($this->gateway); $before = F::retained(); $grant = $f['grant']->public_id;
        $roles = ['contract' => ['pdf', 'application/pdf'], 'master_wav' => ['wav', 'audio/wav'],
            'download_mp3' => ['mp3', 'audio/mpeg'], 'stems_zip' => ['zip', 'application/zip']]; $expected = [];
        foreach ($roles as $kind => [$extension, $mime]) {
            $record = $kind === 'contract' ? GrantContract::sole() : MediaAsset::findOrFail(PendingEntitlement::where('role', $kind)->sole()->media_asset_id);
            $expected[] = ['grantId' => $grant, 'kind' => $kind, 'filename' => $grant.'-'.$kind.'.'.$extension,
                'mimeType' => $mime, 'sizeBytes' => $record->size_bytes];
        }
        Track::sole()->update(['title' => 'Changed current title', 'status' => 'draft']);
        $read = $this->read($f); $this->assertSame($expected, $read['items']); $this->assertSame('available', $read['status']);
        $this->assertSame([], $read['history']); $this->assertFalse($read['historyHasMore']);
        $this->assertSame($before, F::retained()); $this->assertSame([], $this->streams->transactionLevels);
    }

    public function test_multiple_grants_only_list_their_retained_kinds_in_original_order(): void
    {
        $f = F::ready($this->gateway, true); $items = $this->read($f)['items']; $expected = [];
        foreach ($f['grants'] as $grant) {
            $expected[] = [$grant->public_id, 'contract']; $expected[] = [$grant->public_id, 'master_wav'];
        }
        $this->assertSame($expected, array_map(fn ($item) => [$item['grantId'], $item['kind']], $items));
    }

    public function test_metadata_reads_do_not_open_files_call_providers_or_mutate_any_retained_state(): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $before = F::retained();
        $authBefore = TestDeliveryAuthorization::sole()->getAttributes(); $calls = $this->gateway->calls;
        $contract = GrantContract::sole(); unlink(Storage::disk($contract->disk)->path($contract->storage_path));
        $asset = MediaAsset::findOrFail(PendingEntitlement::sole()->media_asset_id); unlink(Storage::disk($asset->disk)->path($asset->storage_path));
        Storage::shouldReceive('disk')->never(); $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $read = $this->read($f); $this->assertSame('available', $read['status']); $this->assertCount(2, $read['items']);
        $this->assertSame($issued->authorizationId, $read['history'][0]['authorizationId']);
        $this->assertSame('unused', $read['history'][0]['status']); $this->assertSame([0], $this->streams->transactionLevels);
        $this->assertSame($calls, $this->gateway->calls); $this->assertSame($before, F::retained());
        $this->assertSame($authBefore, TestDeliveryAuthorization::sole()->getAttributes()); $this->assertDatabaseCount('test_delivery_redemptions', 0);
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/\A\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $query); }
        $json = json_encode($read, JSON_THROW_ON_ERROR);
        foreach ([InventoryFixtures::OWNER, $issued->token(), $authBefore['token_hash'], $authBefore['evidence_hash'],
            $authBefore['request_hash'], $contract->storage_path, $asset->storage_path, $f['delivery_control']->public_id] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame(['authorizationId', 'grantId', 'kind', 'issuedAt', 'expiresAt', 'status', 'attemptedAt'], array_keys($read['history'][0]));
    }

    public static function withdrawals(): array { return [['flag'], ['string_flag'], ['policy'], ['blocked']]; }

    #[DataProvider('withdrawals')]
    public function test_policy_withdrawal_or_control_block_keeps_verified_items_and_unconsumed_history(string $scenario): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f); $initial = $this->read($f);
        match ($scenario) {
            'flag' => config(['delivery.test_access_enabled' => false]), 'string_flag' => config(['delivery.test_access_enabled' => 'true']),
            'policy' => config(['delivery.test_access_policy' => json_encode(F::policy() + ['extra' => true])]),
            'blocked' => app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-PROJECTION-BLOCK'),
        };
        $read = $this->read($f); $initial['status'] = 'unavailable'; $this->assertSame($initial, $read);
        $this->assertSame('unused', $read['history'][0]['status']); $this->assertSame($issued->authorizationId, $read['history'][0]['authorizationId']);
        $this->assertDatabaseCount('test_delivery_redemptions', 0); $this->assertSame([0], $this->streams->transactionLevels);
    }

    public function test_missing_and_initially_blocked_controls_keep_metadata_unavailable_without_provisioning(): void
    {
        $f = F::ready($this->gateway, enable: false); $missing = $this->read($f);
        $this->assertSame('unavailable', $missing['status']); $this->assertCount(2, $missing['items']); $this->assertDatabaseCount('test_delivery_controls', 0);
        app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 0, 'SYNTHETIC-PROJECTION-PROVISION');
        $this->assertSame($missing, $this->read($f)); $this->assertDatabaseCount('test_delivery_controls', 1);
    }

    public static function environments(): array { return [['live'], ['foreign_account'], ['invalid_account'], ['production']]; }

    #[DataProvider('environments')]
    public function test_unsupported_environment_or_account_never_exposes_owned_metadata(string $scenario): void
    {
        $f = F::ready($this->gateway); $environment = $this->app->environment();
        match ($scenario) {
            'live' => config(['payments.stripe.mode' => 'live']), 'foreign_account' => config(['payments.stripe.account_id' => 'acct_FOREIGN']),
            'invalid_account' => config(['payments.stripe.account_id' => 'invalid']), 'production' => $this->app->instance('env', 'production'),
        };
        try { $this->fails('unavailable', fn () => $this->read($f)); }
        finally { $this->app->instance('env', $environment); }
        $this->assertSame([], $this->streams->transactionLevels);
    }

    public function test_attempted_status_has_precedence_and_expiry_is_inclusive_without_claiming_receipt(): void
    {
        $f = F::ready($this->gateway); $attempted = $this->issue($f); $unused = $this->issue($f, 'master_wav');
        $at = now()->toImmutable()->utc()->toIso8601ZuluString();
        app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $attempted->authorizationId, $attempted->token())->close();
        $history = $this->read($f)['history'];
        $this->assertSame([$unused->authorizationId, $attempted->authorizationId], array_column($history, 'authorizationId'));
        $this->assertSame(['unused', 'attempted'], array_column($history, 'status'));
        $this->assertSame([null, $at], array_column($history, 'attemptedAt'));
        $this->travelTo(now()->addSeconds(59)); $this->assertSame('unused', $this->read($f)['history'][0]['status']);
        $this->travelTo(now()->addSecond()); $history = $this->read($f)['history'];
        $this->assertSame(['expired', 'attempted'], array_column($history, 'status')); $this->assertSame([null, $at], array_column($history, 'attemptedAt'));
        $this->assertDatabaseCount('test_delivery_redemptions', 1);
    }

    public function test_history_is_newest_twenty_with_deterministic_ties_and_a_twenty_one_row_query_bound(): void
    {
        $f = F::ready($this->gateway); $ids = [];
        for ($i = 0; $i < 21; $i++) {
            if ($i > 0 && $i % 3 === 0) { $this->travelTo(now()->addSeconds(60)); }
            $ids[] = $this->issue($f)->authorizationId;
            if ($i === 19) { $twenty = $this->read($f); $this->assertFalse($twenty['historyHasMore']); $this->assertCount(20, $twenty['history']); }
        }
        $queries = []; $loaded = 0;
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        Event::listen('eloquent.retrieved: '.TestDeliveryAuthorization::class, function () use (&$loaded): void { $loaded++; });
        $read = $this->read($f); $this->assertSame(array_slice(array_reverse($ids), 0, 20), array_column($read['history'], 'authorizationId'));
        $this->assertTrue($read['historyHasMore']); $this->assertSame(20, $read['historyLimit']); $this->assertSame(21, $loaded);
        $historyQueries = array_values(array_filter($queries, fn ($query) => preg_match('/from ["`]test_delivery_authorizations["`]/i', $query)));
        $this->assertCount(1, $historyQueries); $this->assertMatchesRegularExpression('/order by ["`]issued_at["`] desc, ["`]id["`] desc limit 21\z/i', $historyQueries[0]);
        $this->assertDatabaseCount('test_delivery_authorizations', 21); $this->assertDatabaseCount('test_delivery_redemptions', 0);
    }

    public static function corruptGraphs(): array { return [['original'], ['activation'], ['authorization'], ['redemption'], ['control']]; }

    #[DataProvider('corruptGraphs')]
    public function test_any_corrupt_presented_proof_or_retained_graph_fails_closed_even_after_flag_withdrawal(string $kind): void
    {
        $f = F::ready($this->gateway); $issued = $this->issue($f);
        if ($kind === 'redemption') { app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token())->close(); }
        $before = F::retained(); config(['delivery.test_access_enabled' => false]);
        $model = match ($kind) { 'original' => GrantContract::class, 'activation' => TestFulfillmentActivation::class,
            'authorization' => TestDeliveryAuthorization::class, 'redemption' => TestDeliveryRedemption::class, 'control' => TestDeliveryControl::class };
        Event::listen('eloquent.retrieved: '.$model, function ($record) use ($kind): void {
            if ($kind === 'original') { $record->input_hash = str_repeat('0', 64); return; }
            if ($kind === 'control') { $record->test_fulfillment_activation_id++; return; }
            $payload = json_decode(Crypt::decryptString($record->evidence_ciphertext), true, 128, JSON_THROW_ON_ERROR);
            $payload['unexpected'] = true; $record->evidence_ciphertext = Crypt::encryptString(CanonicalJson::encode($payload));
            $record->evidence_hash = hash('sha256', $record->evidence_ciphertext);
        });
        $this->fails('changed', fn () => $this->read($f)); $this->assertSame($before, F::retained());
    }

    public static function futureTimes(): array { return [['activation'], ['authorization'], ['redemption'], ['control']]; }

    #[DataProvider('futureTimes')]
    public function test_future_retained_timestamps_fail_closed(string $kind): void
    {
        $f = F::ready($this->gateway); $start = now()->toImmutable();
        if ($kind === 'authorization') { $this->travelTo($start->addSecond()); $this->issue($f); }
        if ($kind === 'redemption') {
            $issued = $this->issue($f); $this->travelTo($start->addSecond());
            app(RedeemTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, $issued->token())->close();
        }
        if ($kind === 'control') {
            $this->travelTo($start->addSecond()); app(ManageTestDeliveryControl::class)->handle($f['order']->public_id, true, 1, 'SYNTHETIC-FUTURE-CONTROL');
        }
        $this->travelTo($kind === 'activation' ? $start->subSecond() : $start);
        $this->fails('changed', fn () => $this->read($f));
    }
}
