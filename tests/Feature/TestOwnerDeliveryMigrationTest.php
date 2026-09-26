<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\ActivateTestFulfillment;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Synthetic authorization payloads exercise SQL boundaries, not the later token issuance/HTTP authority. */
class TestOwnerDeliveryMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $deliverySchemaGateway;

    protected function setUp(): void
    {
        parent::setUp(); $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); ActivationFixtures::configure();
        $this->deliverySchemaGateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->deliverySchemaGateway);
        $this->app->instance(StripePaymentGateway::class, $this->deliverySchemaGateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
    }

    public function test_empty_delivery_roundtrip_retains_complete_activation_originals_and_pending_rights(): void
    {
        $fixture = $this->fixture(); $retained = $this->retained();
        $migration = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $migration->down();
        foreach ($this->tables() as $table) { $this->assertFalse(Schema::hasTable($table)); }
        $this->assertSame($retained, $this->retained());
        $migration->up();
        foreach ($this->tables() as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($retained, $this->retained());
        $this->assertSame('pending', PendingEntitlement::sole()->state);
        $this->assertSame('pending', DB::table('fulfillment_outbox')->sole()->state);
    }

    public function test_control_starts_blocked_with_exact_parent_and_only_versioned_toggles_are_allowed(): void
    {
        $fixture = $this->fixture(); $attributes = $this->controlAttributes($fixture);
        foreach ([['blocked' => false], ['control_version' => 1], ['control_version' => 4294967295], ['order_id' => 999999],
            ['test_fulfillment_activation_id' => 999999], ['public_id' => 'not-a-uuid'],
            ['created_at' => now()->subSecond(), 'updated_at' => now()->subSecond()], ['updated_at' => now()->addSecond()]] as $invalid) {
            $this->rejected(fn () => TestDeliveryControl::create([...$attributes, ...$invalid]));
        }
        $control = TestDeliveryControl::create($attributes); $before = $control->refresh()->getAttributes();
        foreach ([['blocked' => false], ['control_version' => 1], ['blocked' => false, 'control_version' => 2],
            ['blocked' => 2, 'control_version' => 1], ['blocked' => false, 'control_version' => 4294967295], ['public_id' => (string) Str::uuid()],
            ['order_id' => 999999], ['created_at' => now()->subSecond()], ['updated_at' => now()->addSecond()]] as $invalid) {
            $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control->id)->update($invalid));
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control->id)
                ->update(['blocked' => 0, 'control_version' => 1.5]));
        }
        $this->assertSame($before, $control->fresh()->getAttributes());
        foreach ([['public_id' => (string) Str::uuid()], ['control_version' => 1]] as $invalid) {
            try { $control->forceFill($invalid)->save(); $this->fail('Control changed without a valid versioned toggle.'); }
            catch (LogicException) { $control->refresh(); }
        }
        $this->toggle($control, false); $this->assertSame(1, $control->fresh()->control_version);
        $this->travelTo(now()->addSecond()); $this->toggle($control, true);
        $this->assertTrue($control->fresh()->blocked); $this->assertSame(2, $control->control_version);
        $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control->id)
            ->update(['blocked' => false, 'control_version' => 3, 'updated_at' => now()->subSecond()]));
    }

    public function test_authorization_binds_enabled_control_owner_grant_exact_target_and_strict_sixty_second_lifetime(): void
    {
        $fixture = $this->fixture(true); $control = TestDeliveryControl::create($this->controlAttributes($fixture));
        $attributes = $this->authorizationAttributes($fixture, $control);
        $this->rejected(fn () => TestDeliveryAuthorization::create($attributes));
        $this->toggle($control, false); $attributes['control_version'] = $control->control_version;
        $otherGrant = $fixture['grants'][1]; $otherContract = GrantContract::where('license_grant_id', $otherGrant->id)->sole();
        $entitlement = PendingEntitlement::where('license_grant_id', $fixture['grants'][0]->id)->firstOrFail();
        foreach ([['owner_key' => str_repeat('f', 64)], ['test_delivery_control_id' => 999999],
            ['test_fulfillment_activation_id' => 999999], ['order_id' => 999999], ['control_version' => 0], ['control_version' => 2],
            ['control_version' => 4294967294], ['control_version' => 4294967295],
            ['license_grant_id' => $otherGrant->id], ['grant_contract_id' => $otherContract->id],
            ['grant_contract_id' => null], ['pending_entitlement_id' => $entitlement->id],
            ['kind' => 'master_wav'], ['kind' => 'contract '], ['kind' => 'CONTRACT'],
            ['policy_version' => 'test-owner-delivery-v1 '], ['policy_version' => 'live'],
            ['canonicalization_version' => 'vasey-json-v1 '], ['evidence_ciphertext' => ''],
            ['evidence_ciphertext' => str_repeat('é', 2097153)],
            ['evidence_hash' => str_repeat('A', 64)], ['token_hash' => 'short'], ['request_hash' => str_repeat('g', 64)],
            ['idempotency_key_hash' => str_repeat('A', 64)], ['public_id' => str_repeat('f', 36)],
            ['issued_at' => now()->subSecond(), 'expires_at' => now()->addSeconds(59)],
            ['expires_at' => now()->addSeconds(59)], ['expires_at' => now()->addSeconds(61)]] as $invalid) {
            $this->rejected(fn () => TestDeliveryAuthorization::create([...$attributes, ...$invalid]));
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['2026-09-26T12:00:00Z', '2026-09-26 12:00:00.1', '2026-02-30 12:00:00'] as $invalid) {
                $this->rejected(fn () => DB::table('test_delivery_authorizations')->insert([...$attributes, 'issued_at' => $invalid]));
            }
        }
        $authorization = TestDeliveryAuthorization::create($attributes);
        $this->rejected(fn () => TestDeliveryAuthorization::create([...$attributes, 'public_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', 'another-token')]));
        $this->rejected(fn () => TestDeliveryAuthorization::create([...$attributes, 'public_id' => (string) Str::uuid(), 'idempotency_key_hash' => hash('sha256', 'another-request')]));
        $assetAttributes = $this->authorizationAttributes($fixture, $control, $entitlement->role);
        $this->rejected(fn () => TestDeliveryAuthorization::create([...$assetAttributes, 'kind' => 'download_mp3']));
        TestDeliveryAuthorization::create($assetAttributes);
        $this->assertDatabaseCount('test_delivery_authorizations', 2);
        $this->assertTrue($authorization->expires_at->equalTo($authorization->issued_at->addSeconds(60)));
    }

    public function test_redemption_requires_exact_bytes_matching_target_and_is_single_use_before_expiry(): void
    {
        $fixture = $this->fixture(); $control = TestDeliveryControl::create($this->controlAttributes($fixture)); $this->toggle($control, false);
        $authorization = TestDeliveryAuthorization::create($this->authorizationAttributes($fixture, $control));
        $attributes = $this->redemptionAttributes($authorization);
        foreach ([['test_delivery_authorization_id' => 999999], ['control_version' => 2], ['control_version' => 4294967294],
            ['control_version' => 4294967295], ['content_hash' => str_repeat('a', 64)],
            ['size_bytes' => $attributes['size_bytes'] + 1], ['size_bytes' => 0], ['size_bytes' => 1073741825],
            ['redeemed_at' => now()->subSecond()], ['redeemed_at' => now()->addSeconds(60)],
            ['evidence_hash' => 'invalid'], ['canonicalization_version' => 'vasey-json-v2'], ['public_id' => 'invalid']] as $invalid) {
            $this->rejected(fn () => TestDeliveryRedemption::create([...$attributes, ...$invalid]));
        }
        $redemption = TestDeliveryRedemption::create([...$attributes, 'redeemed_at' => now()->addSeconds(59)]);
        $this->rejected(fn () => TestDeliveryRedemption::create([...$attributes, 'public_id' => (string) Str::uuid()]));
        $asset = TestDeliveryAuthorization::create($this->authorizationAttributes($fixture, $control, 'master_wav'));
        $assetAttributes = $this->redemptionAttributes($asset);
        $this->rejected(fn () => TestDeliveryRedemption::create([...$assetAttributes, 'content_hash' => $attributes['content_hash']]));
        TestDeliveryRedemption::create($assetAttributes);
        $this->assertTrue($redemption->authorization->is($authorization));
        $this->assertDatabaseCount('test_delivery_redemptions', 2);
    }

    public function test_blocking_and_reenabling_never_revives_authorizations_from_an_older_control_version(): void
    {
        $fixture = $this->fixture(); $control = TestDeliveryControl::create($this->controlAttributes($fixture)); $this->toggle($control, false);
        $authorization = TestDeliveryAuthorization::create($this->authorizationAttributes($fixture, $control));
        $attributes = $this->redemptionAttributes($authorization);
        $this->toggle($control, true);
        $this->rejected(fn () => TestDeliveryRedemption::create($attributes));
        $this->rejected(fn () => TestDeliveryAuthorization::create($this->authorizationAttributes($fixture, $control)));
        $this->toggle($control, false); $this->assertSame(3, $control->control_version);
        $this->rejected(fn () => TestDeliveryRedemption::create($attributes));
        $this->rejected(fn () => TestDeliveryRedemption::create([...$attributes, 'control_version' => 3]));
        $new = TestDeliveryAuthorization::create($this->authorizationAttributes($fixture, $control));
        TestDeliveryRedemption::create($this->redemptionAttributes($new));
        $this->assertDatabaseCount('test_delivery_authorizations', 2); $this->assertDatabaseCount('test_delivery_redemptions', 1);
    }

    public function test_populated_rollback_and_orm_or_sql_changes_cannot_erase_controls_authorizations_or_redemptions(): void
    {
        $fixture = $this->fixture(); $control = TestDeliveryControl::create($this->controlAttributes($fixture));
        $migration = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        try { $migration->down(); $this->fail('Even a retained blocked control must prevent rollback.'); }
        catch (LogicException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        foreach ($this->tables() as $table) { $this->assertTrue(Schema::hasTable($table)); }
        $this->toggle($control, false);
        $authorization = TestDeliveryAuthorization::create($this->authorizationAttributes($fixture, $control));
        $redemption = TestDeliveryRedemption::create($this->redemptionAttributes($authorization));
        $retained = $this->retained();
        foreach ([$authorization, $redemption] as $model) {
            $before = $model->refresh()->getAttributes();
            foreach (['orm_update', 'orm_delete', 'sql_update', 'sql_delete'] as $operation) {
                $model->refresh();
                try {
                    match ($operation) {
                        'orm_update' => $model->forceFill(['evidence_hash' => str_repeat('b', 64)])->save(),
                        'orm_delete' => $model->delete(),
                        'sql_update' => DB::table($model->getTable())->where('id', $model->id)->update(['evidence_hash' => str_repeat('b', 64)]),
                        'sql_delete' => DB::table($model->getTable())->where('id', $model->id)->delete(),
                    };
                    $this->fail('Retained delivery evidence changed.');
                } catch (LogicException|QueryException $error) {
                    $this->assertInstanceOf(str_starts_with($operation, 'orm_') ? LogicException::class : QueryException::class, $error);
                }
                $this->assertSame($before, $model->fresh()->getAttributes());
            }
        }
        $this->rejected(fn () => DB::table('test_delivery_controls')->where('id', $control->id)->delete());
        try { $control->delete(); $this->fail('Control evidence was deleted.'); }
        catch (LogicException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertSame($retained, $this->retained());
    }

    public function test_storage_contract_hides_private_evidence_and_keeps_relationships_and_restrictive_uniqueness(): void
    {
        $fixture = $this->fixture(); $control = TestDeliveryControl::create($this->controlAttributes($fixture)); $this->toggle($control, false);
        $authorization = TestDeliveryAuthorization::create($this->authorizationAttributes($fixture, $control));
        $redemption = TestDeliveryRedemption::create($this->redemptionAttributes($authorization));
        $this->assertTrue($control->order->is($fixture['order']->fresh())); $this->assertTrue($control->activation->is($fixture['activation']));
        $this->assertTrue($authorization->order->is($fixture['order']->fresh())); $this->assertTrue($authorization->activation->is($fixture['activation']));
        $this->assertTrue($authorization->control->is($control)); $this->assertTrue($authorization->grant->is($fixture['grants'][0]));
        $this->assertTrue($authorization->contract->is(GrantContract::sole())); $this->assertNull($authorization->entitlement);
        $this->assertTrue($authorization->redemption->is($redemption)); $this->assertTrue($redemption->authorization->is($authorization));
        foreach (['owner_key', 'token_hash', 'idempotency_key_hash', 'request_hash', 'evidence_ciphertext', 'evidence_hash'] as $field) {
            $this->assertArrayNotHasKey($field, $authorization->attributesToArray());
        }
        foreach (['content_hash', 'evidence_ciphertext', 'evidence_hash'] as $field) { $this->assertArrayNotHasKey($field, $redemption->attributesToArray()); }
        foreach (['test_delivery_controls' => 2, 'test_delivery_authorizations' => 6, 'test_delivery_redemptions' => 1] as $table => $count) {
            $keys = Schema::getForeignKeys($table); $this->assertCount($count, $keys);
            foreach ($keys as $key) { $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']); }
        }
        foreach (['test_delivery_controls' => [['public_id'], ['order_id'], ['test_fulfillment_activation_id']],
            'test_delivery_authorizations' => [['public_id'], ['token_hash'], ['order_id', 'idempotency_key_hash']],
            'test_delivery_redemptions' => [['public_id'], ['test_delivery_authorization_id']]] as $table => $indexes) {
            foreach ($indexes as $columns) {
                $this->assertCount(1, array_filter(Schema::getIndexes($table), fn ($index) => $index['unique'] && $index['columns'] === $columns));
            }
        }
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $authorization->issued_at);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $redemption->redeemed_at);
    }

    private function fixture(bool $mixed = false): array
    {
        $fixture = ActivationFixtures::issued($this->deliverySchemaGateway, $mixed);
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($fixture['order']->id));
        return array_replace($fixture, ['activation' => TestFulfillmentActivation::sole()]);
    }

    private function controlAttributes(array $fixture): array
    {
        return ['public_id' => (string) Str::uuid(), 'order_id' => $fixture['order']->id,
            'test_fulfillment_activation_id' => $fixture['activation']->id, 'blocked' => true, 'control_version' => 0,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function toggle(TestDeliveryControl $control, bool $blocked): void
    {
        $control->refresh()->update(['blocked' => $blocked, 'control_version' => $control->control_version + 1, 'updated_at' => now()]);
        $control->refresh();
    }

    private function authorizationAttributes(array $fixture, TestDeliveryControl $control, string $kind = 'contract'): array
    {
        $grant = $fixture['grants'][0];
        return ['public_id' => (string) Str::uuid(), 'order_id' => $fixture['order']->id,
            'test_fulfillment_activation_id' => $fixture['activation']->id, 'test_delivery_control_id' => $control->id,
            'control_version' => $control->control_version, 'owner_key' => $fixture['order']->owner_key,
            'token_hash' => hash('sha256', (string) Str::uuid()), 'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
            'request_hash' => hash('sha256', 'synthetic-request'), 'kind' => $kind, 'license_grant_id' => $grant->id,
            'grant_contract_id' => $kind === 'contract' ? GrantContract::where('license_grant_id', $grant->id)->sole()->id : null,
            'pending_entitlement_id' => $kind === 'contract' ? null : PendingEntitlement::where('license_grant_id', $grant->id)->where('role', $kind)->sole()->id,
            'policy_version' => 'test-owner-delivery-v1', 'issued_at' => now(), 'expires_at' => now()->addSeconds(60)] + $this->evidence();
    }

    private function redemptionAttributes(TestDeliveryAuthorization $authorization): array
    {
        $target = $authorization->kind === 'contract' ? $authorization->contract : $authorization->entitlement;
        return ['public_id' => (string) Str::uuid(), 'test_delivery_authorization_id' => $authorization->id,
            'control_version' => $authorization->control_version, 'content_hash' => $authorization->kind === 'contract' ? $target->pdf_hash : $target->asset_hash,
            'size_bytes' => $target->size_bytes, 'redeemed_at' => now()] + $this->evidence();
    }

    private function evidence(): array
    {
        $ciphertext = Crypt::encryptString(CanonicalJson::encode(['purpose' => 'synthetic_delivery_schema_only']));
        return ['evidence_ciphertext' => $ciphertext, 'evidence_hash' => hash('sha256', $ciphertext), 'canonicalization_version' => CanonicalJson::VERSION];
    }

    private function retained(): array
    {
        return ContractFixtures::retained() + ['activation' => json_encode(DB::table('test_fulfillment_activations')->orderBy('id')->get(), JSON_THROW_ON_ERROR)];
    }

    private function tables(): array { return ['test_delivery_controls', 'test_delivery_authorizations', 'test_delivery_redemptions']; }

    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('Contradictory delivery schema write was accepted.'); }
        catch (QueryException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
}
