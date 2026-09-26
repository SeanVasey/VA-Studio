<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Synthetic storage-only evidence. Domain/payment tests separately prove real application decisions. */
class TestOrderFinalizationMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
    }

    public function test_empty_finalization_migration_roundtrip_retains_existing_encrypted_evidence_and_pending_bindings(): void
    {
        $f = $this->finalizationSchemaFixture(true, true);
        $tables = ['orders', 'order_lines', 'order_attempts', 'checkout_intents', 'checkout_sessions', 'verified_payments',
            'inventory_reservations', 'inventory_claims', 'promotion_uses', 'audit_events'];
        $before = $this->finalizationSchemaRows($tables);
        $migration = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        $contracts = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $delivery = require database_path('migrations/2026_09_26_000023_test_owner_delivery.php');
        $delivery->down(); $fulfillmentActivations->down(); $contracts->down(); $migration->down();
        foreach ($this->finalizationSchemaTables() as $table) { $this->assertFalse(Schema::hasTable($table)); }
        foreach (['inventory_reservations', 'promotion_uses'] as $table) { $this->assertFalse(Schema::hasColumn($table, 'consumed_at')); }
        $migration->up(); $contracts->up(); $fulfillmentActivations->up(); $delivery->up();
        $this->assertSame($before, $this->finalizationSchemaRows($tables));
        foreach ($this->finalizationSchemaTables() as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame('pending', DB::table('inventory_reservations')->where('id', $f['attempt']->inventory_reservation_id)->value('state'));
    }

    public function test_populated_rollback_refuses_before_changing_any_evidence_or_guards(): void
    {
        $f = $this->finalizationSchemaGraph();
        $tables = [...$this->finalizationSchemaTables(), 'inventory_reservations', 'promotion_uses'];
        $before = $this->finalizationSchemaRows($tables);
        $migration = require database_path('migrations/2026_09_26_000020_test_order_finalization.php');
        try { $migration->down(); $this->fail('Populated finalization rollback was accepted.'); }
        catch (LogicException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertSame($before, $this->finalizationSchemaRows($tables));
        $this->finalizationSchemaRejected(fn () => DB::table('order_finalizations')->where('id', $f['finalization']->id)->delete());
    }

    public static function finalizationMutationOperations(): array
    {
        return [['orm_update'], ['orm_delete'], ['sql_update'], ['sql_delete']];
    }

    #[DataProvider('finalizationMutationOperations')]
    public function test_entire_finalization_graph_is_append_only_in_models_and_direct_sql(string $operation): void
    {
        $f = $this->finalizationSchemaGraph();
        foreach (['finalization', 'grant', 'entitlement', 'outbox', 'sale'] as $key) {
            $model = $f[$key]; $before = $model->refresh()->getAttributes();
            $change = $key === 'finalization' ? ['finalized_at' => now()->addSecond()] : ['created_at' => now()->addSecond()];
            try {
                match ($operation) {
                    'orm_update' => $model->forceFill($change)->save(),
                    'orm_delete' => $model->delete(),
                    'sql_update' => DB::table($model->getTable())->where('id', $model->id)->update($change),
                    'sql_delete' => DB::table($model->getTable())->where('id', $model->id)->delete(),
                };
                $this->fail('Retained finalization evidence was changed.');
            } catch (LogicException|QueryException $error) {
                $this->assertInstanceOf(str_starts_with($operation, 'orm_') ? LogicException::class : QueryException::class, $error);
                $this->assertSame($before, $model->fresh()->getAttributes());
            }
        }
    }

    public function test_model_casts_relationships_and_private_fields_preserve_the_storage_contract(): void
    {
        $f = $this->finalizationSchemaGraph();
        $this->assertTrue($f['finalization']->order->is($f['order']));
        $this->assertTrue($f['finalization']->payment->is($f['payment']));
        $this->assertTrue($f['finalization']->attempt->is($f['attempt']));
        $this->assertTrue($f['grant']->finalization->is($f['finalization']));
        $this->assertTrue($f['entitlement']->grant->is($f['grant']));
        $this->assertTrue($f['outbox']->grant->is($f['grant']));
        $this->assertTrue($f['sale']->grant->is($f['grant']));
        $this->assertTrue($f['sale']->line->is($f['grant']->line));
        $this->assertSame('pending', $f['entitlement']->state);
        $this->assertSame('pending', $f['outbox']->state);
        $this->assertSame($f['grant']->public_id, $f['outbox']->payload['grant_id']);
        $this->assertTrue($f['grant']->created_at->equalTo($f['finalization']->finalized_at));
        $this->assertSame('fulfillment_outbox', $f['outbox']->getTable());
        foreach (['finalization' => 'evidence', 'grant' => 'render_input'] as $key => $prefix) {
            $model = $f[$key];
            $this->assertSame(hash('sha256', $model->{$prefix.'_ciphertext'}), $model->{$prefix.'_hash'});
            $this->assertStringContainsString('finalization-privacy@example.invalid', Crypt::decryptString($model->{$prefix.'_ciphertext'}));
            $this->assertArrayNotHasKey($prefix.'_ciphertext', $model->attributesToArray());
            $this->assertArrayNotHasKey($prefix.'_hash', $model->attributesToArray());
        }
        $this->assertArrayNotHasKey('payload', $f['outbox']->attributesToArray());
        $this->assertArrayNotHasKey('asset_hash', $f['entitlement']->attributesToArray());
    }

    public function test_finalization_rejects_incoherent_payment_attempt_time_mode_policy_and_reason(): void
    {
        $a = $this->finalizationSchemaFixture(); $b = $this->finalizationSchemaFixture();
        $valid = $this->finalizationSchemaAttributes($a);
        foreach ([['order_id' => $b['order']->id], ['verified_payment_id' => $b['payment']->id],
            ['order_attempt_id' => $b['attempt']->id], ['mode' => 'live'], ['mode' => 'TEST'],
            ['outcome' => 'PAID'], ['outcome' => 'paid_exception', 'reason' => null], ['reason' => 'inventory_blocked'],
            ['outcome' => 'paid_exception', 'reason' => 'arbitrary'], ['outcome' => 'paid_exception', 'reason' => 'INVENTORY_BLOCKED'],
            ['policy_version' => 'unrecognized'], ['confirmed_at' => now()->subSecond()],
            ['finalized_at' => now()->subSecond()], ['eligibility_cutoff' => now()],
            ['public_id' => 'short'], ['evidence_ciphertext' => ''], ['evidence_hash' => 'short'],
            ['canonicalization_version' => '']] as $invalid) {
            $this->finalizationSchemaRejected(fn () => DB::table('order_finalizations')->insert([...$valid, ...$invalid]));
        }
        $this->assertDatabaseCount('order_finalizations', 0);
        $paid = OrderFinalization::create($valid);
        $this->finalizationSchemaRejected(fn () => OrderFinalization::create([...$valid, 'public_id' => (string) Str::uuid()]));
        $this->assertSame('paid', $paid->outcome);
    }

    public function test_confirmed_at_exact_cutoff_can_only_be_an_exception_and_cannot_be_before_checkout_intent(): void
    {
        $f = $this->finalizationSchemaFixture(confirmedOffset: 60);
        $this->assertTrue($f['payment']->confirmed_at->equalTo($f['attempt']->expires_at));
        $attrs = $this->finalizationSchemaAttributes($f);
        $this->finalizationSchemaRejected(fn () => OrderFinalization::create($attrs));
        $exception = OrderFinalization::create([...$attrs, 'outcome' => 'paid_exception', 'reason' => 'late_confirmation']);
        $this->assertSame('paid_exception', $exception->outcome);
        $early = $this->finalizationSchemaFixture(confirmedOffset: -1);
        $this->finalizationSchemaRejected(fn () => OrderFinalization::create($this->finalizationSchemaAttributes($early)));
    }

    public function test_paid_exception_cannot_create_grants_entitlements_or_paid_contract_work(): void
    {
        $f = $this->finalizationSchemaFixture();
        $finalization = OrderFinalization::create([...$this->finalizationSchemaAttributes($f),
            'outcome' => 'paid_exception', 'reason' => 'inventory_blocked']);
        $this->finalizationSchemaRejected(fn () => LicenseGrant::create($this->finalizationSchemaGrantAttributes($f, $finalization)));
        $attributes = $this->finalizationSchemaOutboxAttributes($finalization);
        $this->finalizationSchemaRejected(fn () => FulfillmentOutbox::create([...$attributes, 'kind' => 'render_test_contract_v1']));
        $outbox = FulfillmentOutbox::create($attributes);
        $this->assertNull($outbox->license_grant_id);
        $this->assertSame('exception', $outbox->effect_key);
        $this->assertSame('pending', DB::table('inventory_reservations')->where('id', $f['attempt']->inventory_reservation_id)->value('state'));
        $this->finalizationSchemaRejected(fn () => DB::table('inventory_reservations')->where('id', $f['attempt']->inventory_reservation_id)
            ->update(['state' => 'consumed', 'consumed_at' => $finalization->finalized_at]));
        foreach (['license_grants', 'pending_entitlements', 'exclusive_sales'] as $table) { $this->assertDatabaseCount($table, 0); }
    }

    public function test_grants_cannot_mix_orders_revisions_licenses_or_scopes(): void
    {
        $a = $this->finalizationSchemaFixture(); $b = $this->finalizationSchemaFixture();
        $finalization = OrderFinalization::create($this->finalizationSchemaAttributes($a));
        $attributes = $this->finalizationSchemaGrantAttributes($a, $finalization);
        foreach ([['order_line_id' => $b['order']->lines()->sole()->id], ['offer_revision_id' => $b['revision']->id],
            ['license_version_id' => $b['revision']->license_version_id], ['rights_scope_id' => $b['scope']->id],
            ['created_at' => now()->addSecond()], ['render_input_hash' => 'short']] as $invalid) {
            $this->finalizationSchemaRejected(fn () => LicenseGrant::create([...$attributes, ...$invalid]));
        }
        $grant = LicenseGrant::create($attributes);
        $this->finalizationSchemaRejected(fn () => LicenseGrant::create([...$attributes, 'public_id' => (string) Str::uuid()]));
        $this->assertDatabaseCount('license_grants', 1);
        $this->finalizationSchemaRejected(fn () => ExclusiveSale::create($this->finalizationSchemaSaleAttributes($grant, $finalization)));
    }

    public function test_pending_entitlements_reject_wrong_assets_hashes_roles_sizes_or_activation(): void
    {
        $f = $this->finalizationSchemaFixture();
        $other = $this->finalizationSchemaFixture();
        $finalization = OrderFinalization::create($this->finalizationSchemaAttributes($f));
        $grant = LicenseGrant::create($this->finalizationSchemaGrantAttributes($f, $finalization));
        $attributes = $this->finalizationSchemaEntitlementAttributes($grant, $finalization);
        $otherAsset = DB::table('media_assets')->where('track_id', $other['track']->id)->where('role', 'download_mp3')->sole();
        foreach ([['media_asset_id' => $otherAsset->id], ['asset_hash' => str_repeat('a', 64)],
            ['role' => 'artwork'], ['role' => 'DOWNLOAD_MP3'], ['size_bytes' => 0], ['size_bytes' => -1],
            ['size_bytes' => $attributes['size_bytes'] + 1], ['state' => 'active'], ['state' => 'PENDING'],
            ['created_at' => now()->addSecond()]] as $invalid) {
            $this->finalizationSchemaRejected(fn () => PendingEntitlement::create([...$attributes, ...$invalid]));
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->finalizationSchemaRejected(fn () => DB::table('pending_entitlements')->insert([...$attributes, 'size_bytes' => 1.5]));
        }
        PendingEntitlement::create($attributes);
        $this->finalizationSchemaRejected(fn () => PendingEntitlement::create($attributes));
        $this->assertDatabaseCount('pending_entitlements', 1);
    }

    public function test_outbox_rejects_private_or_extra_payload_fields_and_incoherent_effect_bindings(): void
    {
        $f = $this->finalizationSchemaFixture(); $other = $this->finalizationSchemaFixture();
        $finalization = OrderFinalization::create($this->finalizationSchemaAttributes($f));
        $otherFinalization = OrderFinalization::create($this->finalizationSchemaAttributes($other));
        $grant = LicenseGrant::create($this->finalizationSchemaGrantAttributes($f, $finalization));
        $attributes = $this->finalizationSchemaOutboxAttributes($finalization, $grant);
        foreach ([['state' => 'ready'], ['kind' => 'order_paid_exception_v1'], ['effect_key' => 'grant:999'],
            ['created_at' => now()->addSecond()], ['license_grant_id' => null],
            ['order_finalization_id' => $otherFinalization->id], ['public_id' => 'short'],
            ['payload' => ['buyer' => 'private@example.invalid']],
            ['payload' => [...$attributes['payload'], 'buyer' => 'private@example.invalid']],
            ['payload' => [...$attributes['payload'], 'schema_version' => '1']],
            ['payload' => [...$attributes['payload'], 'schema_version' => 2]],
            ['payload' => [...$attributes['payload'], 'evidence_hash' => str_repeat('a', 64)]],
            ['payload' => [...$attributes['payload'], 'grant_id' => (string) Str::uuid()]],
            ['payload' => [...$attributes['payload'], 'finalization_id' => $otherFinalization->public_id]]] as $invalid) {
            $this->finalizationSchemaRejected(fn () => FulfillmentOutbox::create([...$attributes, ...$invalid]));
        }
        FulfillmentOutbox::create($attributes);
        $this->finalizationSchemaRejected(fn () => FulfillmentOutbox::create([...$attributes, 'public_id' => (string) Str::uuid()]));
        $this->assertDatabaseCount('fulfillment_outbox', 1);
    }

    public function test_resource_consumption_requires_matching_paid_attempt_and_preserves_original_binding(): void
    {
        $f = $this->finalizationSchemaFixture(promoted: true); $other = $this->finalizationSchemaFixture();
        foreach (['inventory_reservations' => 'inventory_reservation_id', 'promotion_uses' => 'promotion_use_id'] as $table => $field) {
            $this->finalizationSchemaRejected(fn () => DB::table($table)->where('id', $f['attempt']->{$field})
                ->update(['state' => 'consumed', 'consumed_at' => now()]));
        }
        $finalization = OrderFinalization::create($this->finalizationSchemaAttributes($f));
        foreach (['inventory_reservations' => 'inventory_reservation_id', 'promotion_uses' => 'promotion_use_id'] as $table => $field) {
            $id = $f['attempt']->{$field}; $before = (array) DB::table($table)->where('id', $id)->sole();
            foreach ([['consumed_at' => null], ['consumed_at' => now()->addSecond()], ['attempt_id' => $other['attempt']->public_id],
                ['pending_at' => now()->addSecond()], ['expires_at' => now()->addHours(2)], ['state' => 'released'],
                ['state' => 'CONSUMED'], ['attempt_id' => strtoupper($f['attempt']->public_id)]] as $invalid) {
                $this->finalizationSchemaRejected(fn () => DB::table($table)->where('id', $id)
                    ->update(['state' => 'consumed', 'consumed_at' => $finalization->finalized_at, ...$invalid]));
            }
            if ($other['attempt']->{$field} !== null) {
                $this->finalizationSchemaRejected(fn () => DB::table($table)->where('id', $other['attempt']->{$field})
                    ->update(['state' => 'consumed', 'consumed_at' => $finalization->finalized_at]));
            }
            DB::table($table)->where('id', $id)->update(['state' => 'consumed', 'consumed_at' => $finalization->finalized_at]);
            $after = (array) DB::table($table)->where('id', $id)->sole();
            $this->assertSame('consumed', $after['state']);
            $this->assertNotNull($after['consumed_at']);
            unset($before['state'], $before['consumed_at'], $after['state'], $after['consumed_at']);
            $this->assertSame($before, $after);
            foreach ([['state' => 'pending', 'consumed_at' => null], ['consumed_at' => now()->addSecond()], ['consumed_at' => null]] as $invalid) {
                $this->finalizationSchemaRejected(fn () => DB::table($table)->where('id', $id)->update($invalid));
            }
        }
    }

    public function test_sql_foreign_keys_restrict_every_new_reference_and_effect_indexes_are_unique(): void
    {
        foreach (['order_finalizations' => 3, 'license_grants' => 5, 'pending_entitlements' => 2,
            'fulfillment_outbox' => 2, 'exclusive_sales' => 4] as $table => $count) {
            $keys = Schema::getForeignKeys($table); $this->assertCount($count, $keys);
            foreach ($keys as $key) { $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']); }
        }
        foreach (['order_finalizations' => [['order_id'], ['verified_payment_id'], ['order_attempt_id'], ['public_id']],
            'license_grants' => [['order_line_id'], ['public_id']],
            'pending_entitlements' => [['license_grant_id', 'media_asset_id', 'role']],
            'fulfillment_outbox' => [['order_finalization_id', 'effect_key'], ['public_id']],
            'exclusive_sales' => [['rights_scope_id'], ['license_grant_id'], ['order_line_id']]] as $table => $keys) {
            foreach ($keys as $columns) {
                $this->assertCount(1, array_filter(Schema::getIndexes($table), fn ($index) => $index['unique'] && $index['columns'] === $columns));
            }
        }
        $f = $this->finalizationSchemaFixture();
        foreach (['order_id', 'verified_payment_id', 'order_attempt_id'] as $field) {
            $this->finalizationSchemaRejected(fn () => OrderFinalization::create([...$this->finalizationSchemaAttributes($f), $field => 999999]));
        }
    }

    private function finalizationSchemaFixture(bool $exclusive = false, bool $promoted = false, int $confirmedOffset = 0): array
    {
        $f = F::prepared($exclusive, $promoted); $order = $f['order']; $attempt = $order->attempt()->sole();
        $private = $this->finalizationSchemaPrivate();
        $intent = CheckoutIntent::create(['public_id' => (string) Str::uuid(), 'order_id' => $order->id,
            'order_attempt_id' => $attempt->id, 'account_id' => F::ACCOUNT, 'mode' => 'test', 'idempotency_key' => 'Request-'.$order->public_id,
            'request_ciphertext' => $private['evidence_ciphertext'], 'request_hash' => $private['evidence_hash'],
            'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => now(), 'initiate_before' => now()->addMinute(),
            'retry_before' => now()->addMinutes(15), 'provider_expires_at' => now()->addHour()]);
        $session = CheckoutSession::create(['checkout_intent_id' => $intent->id, 'account_id' => F::ACCOUNT, 'mode' => 'test',
            'provider_session_id' => 'cs_test_'.Str::uuid(), ...$private, 'created_at' => now()]);
        $payment = VerifiedPayment::create(['order_id' => $order->id, 'order_attempt_id' => $attempt->id,
            'checkout_intent_id' => $intent->id, 'checkout_session_id' => $session->id, 'account_id' => F::ACCOUNT, 'mode' => 'test',
            'provider_payment_intent_id' => 'pi_'.Str::uuid(), 'amount_minor' => 3499, 'currency' => 'USD',
            ...$private, 'confirmed_at' => now()->addSeconds($confirmedOffset)]);

        return $f + compact('attempt', 'intent', 'session', 'payment');
    }

    private function finalizationSchemaGraph(): array
    {
        $f = $this->finalizationSchemaFixture(true, true);
        $finalization = OrderFinalization::create($this->finalizationSchemaAttributes($f));
        $grant = LicenseGrant::create($this->finalizationSchemaGrantAttributes($f, $finalization));
        $entitlement = PendingEntitlement::create($this->finalizationSchemaEntitlementAttributes($grant, $finalization));
        $outbox = FulfillmentOutbox::create($this->finalizationSchemaOutboxAttributes($finalization, $grant));
        $sale = ExclusiveSale::create($this->finalizationSchemaSaleAttributes($grant, $finalization));

        return $f + compact('finalization', 'grant', 'entitlement', 'outbox', 'sale');
    }

    private function finalizationSchemaAttributes(array $f): array
    {
        return ['public_id' => (string) Str::uuid(), 'order_id' => $f['order']->id, 'verified_payment_id' => $f['payment']->id,
            'order_attempt_id' => $f['attempt']->id, 'mode' => 'test', 'outcome' => 'paid', 'reason' => null,
            'policy_version' => 'test-order-finalization-v1', 'confirmed_at' => $f['payment']->confirmed_at,
            'eligibility_cutoff' => $f['attempt']->expires_at, 'finalized_at' => $f['payment']->confirmed_at, ...$this->finalizationSchemaPrivate()];
    }

    private function finalizationSchemaGrantAttributes(array $f, OrderFinalization $finalization): array
    {
        return ['public_id' => (string) Str::uuid(), 'order_line_id' => $f['order']->lines()->sole()->id,
            'order_finalization_id' => $finalization->id, 'offer_revision_id' => $f['revision']->id,
            'license_version_id' => $f['revision']->license_version_id, 'rights_scope_id' => $f['scope']->id,
            ...$this->finalizationSchemaPrivate('render_input'), 'created_at' => $finalization->finalized_at];
    }

    private function finalizationSchemaEntitlementAttributes(LicenseGrant $grant, OrderFinalization $finalization): array
    {
        $snapshot = json_decode(DB::table('offer_revisions')->where('id', $grant->offer_revision_id)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $asset = DB::table('media_assets')->where('id', $snapshot['assets'][0]['id'])->sole();

        return ['license_grant_id' => $grant->id, 'media_asset_id' => $asset->id, 'role' => $asset->role,
            'asset_hash' => $asset->sha256, 'size_bytes' => (int) $asset->size_bytes, 'state' => 'pending', 'created_at' => $finalization->finalized_at];
    }

    private function finalizationSchemaOutboxAttributes(OrderFinalization $finalization, ?LicenseGrant $grant = null): array
    {
        return ['public_id' => (string) Str::uuid(), 'order_finalization_id' => $finalization->id, 'license_grant_id' => $grant?->id,
            'effect_key' => $grant ? 'grant:'.$grant->line->position : 'exception',
            'kind' => $grant ? 'render_test_contract_v1' : 'order_paid_exception_v1', 'state' => 'pending',
            'payload' => ['schema_version' => 1, 'finalization_id' => $finalization->public_id, 'grant_id' => $grant?->public_id,
                'evidence_hash' => $grant ? $grant->render_input_hash : $finalization->evidence_hash], 'created_at' => $finalization->finalized_at];
    }

    private function finalizationSchemaSaleAttributes(LicenseGrant $grant, OrderFinalization $finalization): array
    {
        return ['rights_scope_id' => $grant->rights_scope_id, 'license_grant_id' => $grant->id, 'order_line_id' => $grant->order_line_id,
            'order_finalization_id' => $finalization->id, 'created_at' => $finalization->finalized_at];
    }

    private function finalizationSchemaPrivate(string $prefix = 'evidence'): array
    {
        $cipher = Crypt::encryptString(CanonicalJson::encode(['fixture' => 'finalization-privacy@example.invalid']));

        return [$prefix.'_ciphertext' => $cipher, $prefix.'_hash' => hash('sha256', $cipher), 'canonicalization_version' => CanonicalJson::VERSION];
    }

    private function finalizationSchemaTables(): array
    {
        return ['order_finalizations', 'license_grants', 'pending_entitlements', 'fulfillment_outbox', 'exclusive_sales'];
    }

    private function finalizationSchemaRows(array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) { $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(); }

        return $rows;
    }

    private function finalizationSchemaRejected(callable $operation): void
    {
        try { $operation(); $this->fail('Contradictory finalization storage was accepted.'); }
        catch (QueryException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
}
