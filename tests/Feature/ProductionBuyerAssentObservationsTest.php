<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPolicy\CloseProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPreparation\Models\ProductionBuyerAssentObservation;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations as Reports;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\TestCase;

class ProductionBuyerAssentObservationsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
        $this->fakePrivateMediaStorage();
    }

    private function input(): array
    {
        return ['buyer' => ['legal_name' => 'Synthetic Buyer', 'email' => 'buyer@example.test'], 'reported_accepted' => true, 'observation_reference' => 'synthetic-report-v1'];
    }

    public function test_exact_disclosure_private_retention_and_recovery_never_establish_buyer_act_or_purchase(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $review = $service->review($f['packet']->public_id, $f['actor']);
        $this->assertSame(CanonicalJson::encode($f['machine']['choices']['assent']), CanonicalJson::encode($review['assent']));
        $this->assertSame(CanonicalJson::encode($f['revision']->snapshot['license']), CanonicalJson::encode($review['selection']['lines'][0]['offer_snapshot']['license']));
        $this->assertNull($review['selection']['tax_minor']);
        $this->assertNull($review['selection']['total_minor']);
        $before = Fixture::rows();
        $body = $service->retain($review, $this->input(), 'report-key', $f['actor']);
        $this->assertSame($body, $service->retain($review, $this->input(), 'report-key', $f['actor']));
        $this->assertSame($body, $service->recover($f['packet']->public_id, 'report-key', $f['actor']));
        $this->assertNull($service->recover($f['packet']->public_id, 'unknown-key', $f['actor']));
        $this->assertDatabaseCount(Reports::TABLE, 1);
        foreach (['buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'payable', 'execution_allowed', 'external_facts_verified'] as $field) {
            $this->assertFalse($body[$field]);
        }
        $raw = DB::table(Reports::TABLE)->first();
        $this->assertSame(CanonicalJson::encode($body), Crypt::decryptString($raw->payload_ciphertext));
        $this->assertStringNotContainsString('buyer@example.test', json_encode($raw, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Synthetic Buyer', json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR));
        foreach (['quotes', 'orders', 'inventory_reservations', 'license_grants', 'checkout_intents'] as $table) {
            $this->assertSame($before[$table], Fixture::rows()[$table]);
        }
        Http::assertNothingSent();
    }

    public static function malformed(): array
    {
        return [['reported_accepted', false], ['reported_accepted', 'true'], ['reported_accepted', 1], ['buyer.legal_name', ''],
            ['buyer.legal_name', "Bad\nName"], ['buyer.legal_name', str_repeat('x', 161)], ['buyer.email', 'invalid'],
            ['buyer.email', str_repeat('a', 255).'@example.test'], ['observation_reference', 'bad reference'], ['extra', 1]];
    }

    #[DataProvider('malformed')]
    public function test_closed_report_input_rejects_nonaffirmative_or_malformed_identity(string $field, mixed $value): void
    {
        $f = Fixture::prepared(true);
        $input = $this->input();
        data_set($input, $field, $value);
        $this->expectException(ValidationException::class);
        try {
            app(Reports::class)->retain(app(Reports::class)->review($f['packet']->public_id, $f['actor']), $input, 'key', $f['actor']);
        } finally {
            $this->assertDatabaseCount(Reports::TABLE, 0);
        }
    }

    public function test_changed_input_or_changed_disclosure_does_not_reuse_retained_key(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $review = $service->review($f['packet']->public_id, $f['actor']);
        $body = $service->retain($review, $this->input(), 'key', $f['actor']);
        foreach (['buyer', 'terms', 'subtotal'] as $change) {
            $input = $this->input();
            $altered = $review;
            match ($change) {
                'buyer' => $input['buyer']['email'] = 'changed@example.test',
                'terms' => $altered['assent']['text'] = 'Changed terms',
                'subtotal' => $altered['selection']['advertised_subtotal_minor'] = 1,
            };
            try {
                $service->retain($altered, $input, 'key', $f['actor']);
                $this->fail('Changed request accepted.');
            } catch (ValidationException) {
                $this->assertSame($body, $service->recover($f['packet']->public_id, 'key', $f['actor']));
            }
        }
    }

    public function test_recovery_after_closure_stays_historical_but_requires_current_mfa(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $review = $service->review($f['packet']->public_id, $f['actor']);
        $body = $service->retain($review, $this->input(), 'key', $f['actor']);
        app(CloseProductionTrackCapabilities::class)->applyReviewed(app(CloseProductionTrackCapabilities::class)->review($f['candidate'], $f['actor']),
            ['reason_code' => 'owner_withdrawal', ...Fixture::reference('closure_requested')], $f['actor']);
        $this->assertSame($body, $service->recover($f['packet']->public_id, 'key', $f['actor']));
        Filament::getPanel('admin')->multiFactorAuthentication(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders(), isRequired: true);
        $f['actor']->saveAppAuthenticationSecret(null);
        $this->expectException(AuthorizationException::class);
        $service->recover($f['packet']->public_id, 'key', $f['actor']);
    }

    public function test_encryption_failure_rolls_back_and_key_remains_available(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $review = $service->review($f['packet']->public_id, $f['actor']);
        $real = Crypt::getFacadeRoot();
        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('encryptString')->once()->andThrow(new LogicException('synthetic failure'));
        Crypt::swap($mock);
        try {
            $service->retain($review, $this->input(), 'key', $f['actor']);
            $this->fail('Encryption failure accepted.');
        } catch (LogicException) {
            $this->assertDatabaseCount(Reports::TABLE, 0);
        } finally {
            Crypt::swap($real);
        }
        $this->assertNotNull($service->retain($review, $this->input(), 'key', $f['actor']));
    }

    public function test_late_crypto_callback_actor_drift_rolls_back_report_and_audit(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $review = $service->review($f['packet']->public_id, $f['actor']);
        $before = Fixture::rows();
        $actorBefore = (array) DB::table('users')->where('id', $f['actor']->id)->first();
        $real = Crypt::getFacadeRoot();
        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('encryptString')->once()->andReturnUsing(function (string $plain) use ($real, $f): string {
            $ciphertext = $real->encryptString($plain);
            DB::table('users')->where('id', $f['actor']->id)->update(['name' => 'Late altered observer']);

            return $ciphertext;
        });
        Crypt::swap($mock);
        try {
            $service->retain($review, $this->input(), 'key', $f['actor']);
            $this->fail('Late authority drift accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount(Reports::TABLE, 0);
            $this->assertSame($before, Fixture::rows());
            $this->assertSame($actorBefore, (array) DB::table('users')->where('id', $f['actor']->id)->first());
        } finally {
            Crypt::swap($real);
        }
    }

    public function test_eloquent_serialization_hides_private_evidence_and_refuses_mutations(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $service->retain($service->review($f['packet']->public_id, $f['actor']), $this->input(), 'key', $f['actor']);
        $model = ProductionBuyerAssentObservation::sole();
        foreach (['payload_ciphertext', 'payload_hash', 'request_key'] as $field) {
            $this->assertArrayNotHasKey($field, $model->toArray());
        }
        foreach (['save', 'delete'] as $method) {
            try {
                $model->$method();
                $this->fail('Model mutation accepted.');
            } catch (LogicException) {
                $this->assertDatabaseCount(Reports::TABLE, 1);
            }
        }
    }

    public function test_damaged_restore_cannot_substitute_opaque_recovery_key(): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $body = $service->retain($service->review($f['packet']->public_id, $f['actor']), $this->input(), 'key', $f['actor']);
        // Deliberate damaged-restore fixture, not an operational guard bypass.
        DB::unprepared('DROP TRIGGER pbao_update');
        DB::table(Reports::TABLE)->update(['request_key' => PacketEvidence::key('substituted-key')]);
        $this->expectException(ValidationException::class);
        $service->recover($f['packet']->public_id, 'substituted-key', $f['actor']);
    }

    public function test_foreign_existing_table_is_preserved_before_installation_ddl(): void
    {
        $this->assertDatabaseCount(Reports::TABLE, 0);
        Schema::drop(Reports::TABLE);
        Schema::create(Reports::TABLE, fn ($table) => $table->string('foreign_data'));
        DB::table(Reports::TABLE)->insert(['foreign_data' => 'retained']);
        $columns = Schema::getColumns(Reports::TABLE);
        $migration = require database_path('migrations/2026_10_07_239000_production_buyer_assent_observations.php');
        try {
            $migration->up();
            $this->fail('Foreign table adopted.');
        } catch (LogicException) {
            $this->assertSame($columns, Schema::getColumns(Reports::TABLE));
            $this->assertSame('retained', DB::table(Reports::TABLE)->value('foreign_data'));
        }
    }

    public static function mutations(): array
    {
        return [['update'], ['delete'], ['replace']];
    }

    #[DataProvider('mutations')]
    public function test_sql_guards_reject_overwrite_and_delete(string $operation): void
    {
        $f = Fixture::prepared(true);
        $service = app(Reports::class);
        $service->retain($service->review($f['packet']->public_id, $f['actor']), $this->input(), 'key', $f['actor']);
        $row = (array) DB::table(Reports::TABLE)->first();
        $this->expectException(QueryException::class);
        try {
            match ($operation) {
                'update' => DB::table(Reports::TABLE)->update(['payload_hash' => str_repeat('a', 64)]),
                'delete' => DB::table(Reports::TABLE)->delete(),
                'replace' => DB::table(Reports::TABLE)->insertOrIgnore($row),
            };
            if ($operation === 'replace') {
                DB::table(Reports::TABLE)->upsert([$row], ['id'], ['payload_ciphertext']);
            }
        } finally {
            $this->assertSame([$row], DB::table(Reports::TABLE)->get()->map(fn ($r) => (array) $r)->all());
        }
    }

    public function test_real_migrator_rollback_preserves_empty_and_populated_data_schema_and_bookkeeping(): void
    {
        foreach ([false, true] as $populated) {
            if ($populated) {
                $f = Fixture::prepared(true);
                $service = app(Reports::class);
                $service->retain($service->review($f['packet']->public_id, $f['actor']), $this->input(), 'key', $f['actor']);
            }
            $before = ['rows' => DB::table(Reports::TABLE)->get()->map(fn ($row): array => (array) $row)->all(), 'migrations' => DB::table('migrations')->get()->map(fn ($row): array => (array) $row)->all(),
                'columns' => Schema::getColumns(Reports::TABLE), 'indexes' => Schema::getIndexes(Reports::TABLE), 'foreign' => Schema::getForeignKeys(Reports::TABLE)];
            try {
                app('migrator')->rollback([database_path('migrations')], ['step' => 1]);
                $this->fail('Rollback accepted.');
            } catch (LogicException) {
                $this->assertSame($before['rows'], DB::table(Reports::TABLE)->get()->map(fn ($row): array => (array) $row)->all());
                $this->assertSame($before['migrations'], DB::table('migrations')->get()->map(fn ($row): array => (array) $row)->all());
                $this->assertSame($before['columns'], Schema::getColumns(Reports::TABLE));
                $this->assertSame($before['indexes'], Schema::getIndexes(Reports::TABLE));
                $this->assertSame($before['foreign'], Schema::getForeignKeys(Reports::TABLE));
            }
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
    }
}
