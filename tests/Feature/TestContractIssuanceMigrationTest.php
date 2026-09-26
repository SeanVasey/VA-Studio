<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Synthetic manifests test schema invariants; domain tests separately execute the renderer/storage. */
class TestContractIssuanceMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $contractSchemaGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $this->contractSchemaGateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->contractSchemaGateway);
        $this->app->instance(StripePaymentGateway::class, $this->contractSchemaGateway);
    }

    public function test_empty_contract_migration_roundtrip_preserves_paid_graph_and_pending_fulfillment(): void
    {
        $this->contractSchemaGrants();
        $retained = F::retained();
        $migration = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        $fulfillmentActivations = require database_path('migrations/2026_09_26_000022_test_fulfillment_activation.php');
        $fulfillmentActivations->down(); $migration->down();
        foreach ($this->contractSchemaTables() as $table) { $this->assertFalse(Schema::hasTable($table)); }
        $this->assertSame($retained, F::retained());
        $migration->up(); $fulfillmentActivations->up();
        foreach ($this->contractSchemaTables() as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($retained, F::retained());
        $this->assertSame('pending', DB::table('pending_entitlements')->sole()->state);
        $this->assertSame('pending', DB::table('fulfillment_outbox')->sole()->state);
    }

    public function test_any_retained_request_prevents_rollback_without_removing_guards_or_evidence(): void
    {
        $grant = $this->contractSchemaGrants()[0];
        $request = ContractRenderRequest::create($this->contractSchemaRequestAttributes($grant));
        $before = $request->refresh()->getAttributes();
        $migration = require database_path('migrations/2026_09_26_000021_test_contract_issuance.php');
        try { $migration->down(); $this->fail('Rollback erased an initiated original-document request.'); }
        catch (LogicException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        foreach ($this->contractSchemaTables() as $table) { $this->assertTrue(Schema::hasTable($table)); }
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->contractSchemaRejected(fn () => DB::table('contract_render_requests')->where('id', $request->id)->delete());
    }

    public static function contractSchemaMutationOperations(): array
    {
        return [['orm_update'], ['orm_delete'], ['sql_update'], ['sql_delete']];
    }

    #[DataProvider('contractSchemaMutationOperations')]
    public function test_requests_and_original_contracts_are_append_only_in_models_and_direct_sql(string $operation): void
    {
        $graph = $this->contractSchemaGraph();
        foreach (['request', 'contract'] as $key) {
            $model = $graph[$key]; $before = $model->refresh()->getAttributes();
            $changes = $key === 'request' ? ['profile_hash' => str_repeat('b', 64)] : ['pdf_hash' => str_repeat('b', 64)];
            try {
                match ($operation) {
                    'orm_update' => $model->forceFill($changes)->save(),
                    'orm_delete' => $model->delete(),
                    'sql_update' => DB::table($model->getTable())->where('id', $model->id)->update($changes),
                    'sql_delete' => DB::table($model->getTable())->where('id', $model->id)->delete(),
                };
                $this->fail('Retained original-document evidence changed.');
            } catch (LogicException|QueryException $error) {
                $this->assertInstanceOf(str_starts_with($operation, 'orm_') ? LogicException::class : QueryException::class, $error);
                $this->assertSame($before, $model->fresh()->getAttributes());
            }
        }
    }

    public function test_request_requires_exact_paid_grant_outbox_and_ciphertext_hash(): void
    {
        [$first, $second] = $this->contractSchemaGrants(true);
        $attributes = $this->contractSchemaRequestAttributes($first);
        $otherOutbox = FulfillmentOutbox::where('license_grant_id', $second->id)->sole();
        foreach ([['license_grant_id' => $second->id], ['fulfillment_outbox_id' => $otherOutbox->id],
            ['license_grant_id' => 999999], ['fulfillment_outbox_id' => 999999], ['input_hash' => str_repeat('b', 64)],
            ['profile_hash' => 'short'], ['profile_hash' => str_repeat('A', 64)], ['profile' => []],
            ['canonicalization_version' => ''], ['public_id' => 'not-a-uuid'],
            ['document_public_id' => str_repeat('a', 36)], ['created_at' => now()->subSecond()]] as $invalid) {
            $this->contractSchemaRejected(fn () => ContractRenderRequest::create([...$attributes, ...$invalid]));
        }
        $request = ContractRenderRequest::create($attributes);
        $this->contractSchemaRejected(fn () => ContractRenderRequest::create([...$attributes,
            'public_id' => (string) Str::uuid(), 'document_public_id' => (string) Str::uuid()]));
        $otherAttributes = $this->contractSchemaRequestAttributes($second);
        foreach (['public_id', 'document_public_id'] as $field) {
            $this->contractSchemaRejected(fn () => ContractRenderRequest::create([...$otherAttributes, $field => $request->{$field}]));
        }
        $this->assertDatabaseCount('contract_render_requests', 1);
    }

    public function test_work_only_starts_pending_and_cannot_change_request_identity_or_be_deleted(): void
    {
        [$first, $second] = $this->contractSchemaGrants(true);
        $request = ContractRenderRequest::create($this->contractSchemaRequestAttributes($first));
        $other = ContractRenderRequest::create($this->contractSchemaRequestAttributes($second));
        $attributes = ['contract_render_request_id' => $request->id, 'state' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now()];
        foreach ([['state' => 'processing', 'attempts' => 1, 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(300)],
            ['state' => 'completed'], ['attempts' => 1], ['reason' => 'render_failed'], ['claim_token' => (string) Str::uuid()],
            ['next_attempt_at' => now()->addMinute()], ['contract_render_request_id' => 999999], ['updated_at' => now()->subSecond()]] as $invalid) {
            $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->insert([...$attributes, ...$invalid]));
        }
        $work = ContractRenderWork::create($attributes); $before = $work->refresh()->getAttributes();
        foreach (['contract_render_request_id' => $other->id, 'created_at' => now()->addSecond()] as $field => $value) {
            $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)->update([$field => $value]));
            try { $work->forceFill([$field => $value])->save(); $this->fail('Work identity changed through the model.'); }
            catch (LogicException $error) { $this->assertStringContainsString('immutable', $error->getMessage()); $work->refresh(); }
        }
        $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)->delete());
        try { $work->delete(); $this->fail('Work evidence was deleted through the model.'); }
        catch (LogicException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertSame($before, $work->fresh()->getAttributes());
        $this->contractSchemaRejected(fn () => ContractRenderWork::create($attributes));
    }

    public function test_claim_retry_and_completion_require_valid_leases_attempts_reasons_and_original_result(): void
    {
        $grant = $this->contractSchemaGrants()[0];
        $request = ContractRenderRequest::create($this->contractSchemaRequestAttributes($grant));
        $work = ContractRenderWork::create(['contract_render_request_id' => $request->id]);
        $claim = $this->contractSchemaClaimAttributes(1);
        foreach ([['attempts' => 0], ['attempts' => 2], ['claim_token' => 'short'],
            ['lease_expires_at' => now()->addSeconds(299)], ['lease_expires_at' => now()->addSeconds(301)],
            ['reason' => 'render_failed'], ['next_attempt_at' => now()->addMinute()]] as $invalid) {
            $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)->update([...$claim, ...$invalid]));
        }
        $work->update($claim); $token = $work->claim_token;
        $this->contractSchemaRejected(fn () => $work->update($this->contractSchemaClaimAttributes(2)));
        $terminal = ['state' => 'completed', 'claim_token' => null, 'lease_expires_at' => null, 'reason' => null, 'next_attempt_at' => null];
        $this->contractSchemaRejected(fn () => $work->update($terminal)); $work->refresh();
        foreach (['made_up', 'evidence_changed'] as $reason) {
            $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)
                ->update([...$terminal, 'state' => 'retry', 'reason' => $reason, 'next_attempt_at' => now()->addMinute()]));
        }
        $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)
            ->update([...$terminal, 'state' => 'retry', 'reason' => 'render_failed', 'next_attempt_at' => now()->addSeconds(59)]));
        $work->update([...$terminal, 'state' => 'retry', 'reason' => 'render_failed', 'next_attempt_at' => now()->addMinute()]);
        $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)->update($this->contractSchemaClaimAttributes(2)));
        $this->travelTo(now()->addMinute()); $work->refresh()->update($this->contractSchemaClaimAttributes(2));
        $this->assertNotSame($token, $work->claim_token);
        $this->travelTo(now()->addSecond());
        $contract = GrantContract::create($this->contractSchemaResultAttributes($request, $work));
        $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)
            ->update([...$terminal, 'state' => 'quarantined', 'reason' => 'invalid_pdf', 'updated_at' => now()]));
        $work->update($terminal); $this->assertSame('completed', $work->fresh()->state);
        $this->assertSame($work->request->document_public_id, $contract->public_id);
        foreach ([$this->contractSchemaClaimAttributes(3), ['updated_at' => now()->addSecond()],
            ['state' => 'pending', 'attempts' => 0]] as $invalid) {
            $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)->update($invalid));
        }
        try { $work->update(['updated_at' => now()->addSecond()]); $this->fail('Completed work was changed.'); }
        catch (LogicException $error) { $this->assertStringContainsString('immutable', $error->getMessage()); }
    }

    public function test_expired_claim_cannot_write_an_original_and_fifth_expiry_quarantines_without_reset(): void
    {
        $grant = $this->contractSchemaGrants()[0];
        $request = ContractRenderRequest::create($this->contractSchemaRequestAttributes($grant));
        $work = ContractRenderWork::create(['contract_render_request_id' => $request->id]);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $work->refresh()->update($this->contractSchemaClaimAttributes($attempt));
            $this->travelTo(now()->addSeconds(300));
            $this->contractSchemaRejected(fn () => GrantContract::create($this->contractSchemaResultAttributes($request, $work)));
        }
        $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)->update($this->contractSchemaClaimAttributes(6)));
        $work->refresh()->update(['state' => 'quarantined', 'reason' => 'retry_exhausted', 'claim_token' => null, 'lease_expires_at' => null]);
        $this->assertSame(5, $work->fresh()->attempts);
        $this->assertSame('quarantined', $work->fresh()->state);
        $this->contractSchemaRejected(fn () => DB::table('contract_render_work')->where('id', $work->id)
            ->update(['state' => 'pending', 'attempts' => 0, 'reason' => null]));
        $this->assertDatabaseCount('grant_contracts', 0);
    }

    public function test_result_requires_exact_request_identity_hashes_current_token_and_private_original_path(): void
    {
        [$first, $second] = $this->contractSchemaGrants(true);
        $request = ContractRenderRequest::create($this->contractSchemaRequestAttributes($first));
        $other = ContractRenderRequest::create($this->contractSchemaRequestAttributes($second));
        $work = ContractRenderWork::create(['contract_render_request_id' => $request->id]);
        $work->update($this->contractSchemaClaimAttributes(1));
        $attributes = $this->contractSchemaResultAttributes($request, $work);
        foreach ([['public_id' => (string) Str::uuid()], ['license_grant_id' => $second->id], ['contract_render_request_id' => $other->id],
            ['input_hash' => str_repeat('b', 64)], ['profile_hash' => str_repeat('b', 64)], ['pdf_hash' => str_repeat('A', 64)],
            ['disk' => 'public'], ['disk' => 'LOCAL'], ['claim_token' => (string) Str::uuid()], ['claim_token' => 'invalid'],
            ['storage_path' => 'contracts/test/../../public/original.pdf'], ['storage_path' => str_replace('original.pdf', 'replacement.pdf', $attributes['storage_path'])],
            ['size_bytes' => 0], ['size_bytes' => 16777217], ['page_count' => 0], ['page_count' => 101],
            ['issued_at' => now()->subSecond()], ['issued_at' => now()->addSeconds(300)]] as $invalid) {
            $this->contractSchemaRejected(fn () => GrantContract::create([...$attributes, ...$invalid]));
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['size_bytes', 'page_count'] as $field) {
                $this->contractSchemaRejected(fn () => DB::table('grant_contracts')->insert([...$attributes, $field => 1.5]));
            }
        }
        GrantContract::create($attributes);
        $this->contractSchemaRejected(fn () => GrantContract::create($attributes));
        $this->assertDatabaseCount('grant_contracts', 1);
    }

    public function test_models_hide_private_manifest_fields_and_bind_relations_without_activating_fulfillment(): void
    {
        $graph = $this->contractSchemaGraph();
        $this->assertTrue($graph['request']->grant->is($graph['grant']));
        $this->assertTrue($graph['request']->outbox->is($graph['outbox']));
        $this->assertTrue($graph['request']->work->is($graph['work']));
        $this->assertTrue($graph['request']->contract->is($graph['contract']));
        $this->assertTrue($graph['work']->request->is($graph['request']));
        $this->assertTrue($graph['contract']->request->is($graph['request']));
        $this->assertTrue($graph['contract']->grant->is($graph['grant']));
        $this->assertTrue($graph['contract']->issued_at->equalTo(now()));
        $this->assertSame(1024, $graph['contract']->size_bytes);
        $this->assertSame(1, $graph['contract']->page_count);
        $this->assertSame('contract_render_work', $graph['work']->getTable());
        foreach (['request' => ['input_hash', 'profile', 'profile_hash'], 'work' => ['claim_token'],
            'contract' => ['input_hash', 'profile_hash', 'disk', 'storage_path', 'claim_token', 'pdf_hash']] as $key => $fields) {
            foreach ($fields as $field) { $this->assertArrayNotHasKey($field, $graph[$key]->attributesToArray()); }
        }
        $this->assertSame('pending', DB::table('pending_entitlements')->sole()->state);
        $this->assertSame('pending', $graph['outbox']->fresh()->state);
    }

    public function test_contract_foreign_keys_restrict_deletion_and_all_logical_effects_have_unique_indexes(): void
    {
        foreach (['contract_render_requests' => 2, 'contract_render_work' => 1, 'grant_contracts' => 2] as $table => $count) {
            $keys = Schema::getForeignKeys($table); $this->assertCount($count, $keys);
            foreach ($keys as $key) { $this->assertContains(strtolower($key['on_delete']), ['restrict', 'no action']); }
        }
        foreach (['contract_render_requests' => [['public_id'], ['license_grant_id'], ['fulfillment_outbox_id'], ['document_public_id']],
            'contract_render_work' => [['contract_render_request_id']],
            'grant_contracts' => [['public_id'], ['license_grant_id'], ['contract_render_request_id'], ['storage_path']]] as $table => $sets) {
            foreach ($sets as $columns) {
                $this->assertCount(1, array_filter(Schema::getIndexes($table), fn ($index) => $index['unique'] && $index['columns'] === $columns));
            }
        }
    }

    private function contractSchemaGrants(bool $mixed = false): array
    {
        $f = $mixed ? F::confirmedMixedCart($this->contractSchemaGateway) : F::confirmed($this->contractSchemaGateway);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($f['payment']->id));

        return LicenseGrant::orderBy('id')->get()->all();
    }

    private function contractSchemaRequestAttributes(LicenseGrant $grant): array
    {
        $profile = ['schema_version' => 1, 'purpose' => 'synthetic_schema_only_profile', 'renderer' => 'not-executed'];

        return ['public_id' => (string) Str::uuid(), 'license_grant_id' => $grant->id,
            'fulfillment_outbox_id' => FulfillmentOutbox::where('license_grant_id', $grant->id)->sole()->id,
            'input_hash' => $grant->render_input_hash, 'profile' => $profile, 'profile_hash' => CanonicalJson::hash($profile),
            'canonicalization_version' => CanonicalJson::VERSION, 'document_public_id' => (string) Str::uuid(), 'created_at' => now()];
    }

    private function contractSchemaClaimAttributes(int $attempts): array
    {
        return ['state' => 'processing', 'attempts' => $attempts, 'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addSeconds(300), 'next_attempt_at' => null, 'reason' => null, 'updated_at' => now()];
    }

    private function contractSchemaResultAttributes(ContractRenderRequest $request, ContractRenderWork $work): array
    {
        return ['public_id' => $request->document_public_id, 'license_grant_id' => $request->license_grant_id,
            'contract_render_request_id' => $request->id, 'input_hash' => $request->input_hash, 'profile_hash' => $request->profile_hash,
            'disk' => 'local', 'storage_path' => 'contracts/test/'.$request->public_id.'/'.$work->claim_token.'/original.pdf',
            'claim_token' => $work->claim_token, 'pdf_hash' => str_repeat('a', 64), 'size_bytes' => 1024, 'page_count' => 1, 'issued_at' => now()];
    }

    private function contractSchemaGraph(): array
    {
        $grant = $this->contractSchemaGrants()[0]; $outbox = FulfillmentOutbox::where('license_grant_id', $grant->id)->sole();
        $request = ContractRenderRequest::create($this->contractSchemaRequestAttributes($grant));
        $work = ContractRenderWork::create(['contract_render_request_id' => $request->id]);
        $work->update($this->contractSchemaClaimAttributes(1));
        $contract = GrantContract::create($this->contractSchemaResultAttributes($request, $work));
        $work->update(['state' => 'completed', 'claim_token' => null, 'lease_expires_at' => null]);

        return compact('grant', 'outbox', 'request', 'work', 'contract');
    }

    private function contractSchemaTables(): array
    {
        return ['contract_render_requests', 'contract_render_work', 'grant_contracts'];
    }

    private function contractSchemaRejected(callable $operation): void
    {
        try { $operation(); $this->fail('Contradictory contract schema write was accepted.'); }
        catch (QueryException $error) { $this->assertNotSame('', $error->getMessage()); }
    }
}
