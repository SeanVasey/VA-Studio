<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPolicy\CloseProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPreparation\AmountInputConsistencyV1;
use App\Domain\Commerce\ProductionPreparation\CompareProductionAmountInputs;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\ProductionAmountRequirements;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\TestCase;

class ProductionAmountInputConsistencyAccessTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
        $this->fakePrivateMediaStorage();
    }

    /** Explicitly supplied synthetic zero tax, never an exemption/provider observation. */
    private function supplied(array $r): array
    {
        $lines = [];
        foreach ($r['lines'] as $line) {
            $lines[] = array_intersect_key($line, array_flip(['position', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id', 'offer_snapshot_hash']))
                + ['quantity' => 1, 'advertised_price_minor' => $line['price_minor'], 'supplied_net_minor' => $line['price_minor'],
                    'supplied_tax_minor' => 0, 'supplied_gross_minor' => $line['price_minor']];
        }

        return ['schema_version' => 1, 'purpose' => AmountInputConsistencyV1::INPUT_PURPOSE, 'packet_public_id' => $r['packet_public_id'],
            'retained_body_hash' => $r['retained_body_hash'], 'requirements_hash' => CanonicalJson::hash($r), 'context' => $r['context'],
            'currency' => $r['currency'], 'minor_unit_exponent' => $r['minor_unit_exponent'], 'tax_requirements' => $r['tax_requirements'],
            'advertised_subtotal_minor' => $r['advertised_subtotal_minor'], 'supplied_net_subtotal_minor' => $r['advertised_subtotal_minor'],
            'supplied_tax_minor' => 0, 'supplied_gross_total_minor' => $r['advertised_subtotal_minor'], 'lines' => $lines];
    }

    /** All selected-driver rows, table definitions, triggers and migration entries. */
    private function snapshot(): array
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $this->assertContains($driver, ['sqlite', 'mysql']);
        if ($driver === 'sqlite') {
            $schema = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(\PDO::FETCH_ASSOC);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
            $quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
        } else {
            $quote = static fn (string $name): string => '`'.str_replace('`', '``', $name).'`';
            $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME')->fetchAll(\PDO::FETCH_COLUMN);
            $schema = [];
            foreach ($tables as $table) {
                $schema['tables'][$table] = $pdo->query('SHOW CREATE TABLE '.$quote($table))->fetch(\PDO::FETCH_ASSOC);
            }
            $schema['triggers'] = $pdo->query('SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(\PDO::FETCH_ASSOC);
        }
        $rows = [];
        foreach ($tables as $table) {
            $values = $pdo->query('SELECT * FROM '.$quote($table))->fetchAll(\PDO::FETCH_ASSOC);
            $values = array_map(CanonicalJson::encode(...), $values);
            sort($values, SORT_STRING);
            $rows[$table] = $values;
        }

        return ['schema' => $schema, 'rows' => $rows];
    }

    public function test_authenticated_projection_is_read_only_and_does_not_consume_reported_buyer_pii(): void
    {
        $f = Fixture::prepared(true);
        $reports = app(ProductionBuyerAssentObservations::class);
        $reports->retain($reports->review($f['packet']->public_id, $f['actor']),
            ['buyer' => ['legal_name' => 'Private Synthetic Report', 'email' => 'private-report@example.test'], 'reported_accepted' => true,
                'observation_reference' => 'synthetic-report'], 'reported-only', $f['actor']);
        $requirements = app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']);
        $supplied = $this->supplied($requirements);
        $before = $this->snapshot();
        $queries = [];
        $recording = true;
        DB::listen(function ($query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $sqlite = $connection->getDriverName() === 'sqlite';
        // SQLite enforces query-only even for the inherited direct PDO reader.
        // MySQL uses actual row-locking reads; the query witness and complete
        // native snapshots prove invariance without a test-only driver switch.
        $originalQueryOnly = $sqlite ? (int) $pdo->query('PRAGMA query_only')->fetchColumn() : null;
        if ($sqlite) {
            $pdo->exec('PRAGMA query_only = ON');
        }
        try {
            if ($sqlite) {
                $this->assertSame(1, (int) $pdo->query('PRAGMA query_only')->fetchColumn());
            } else {
                $this->assertSame('mysql', $connection->getDriverName());
            }
            $result = app(CompareProductionAmountInputs::class)->forPacket($f['packet']->public_id, $supplied, $f['actor']);
            $this->assertSame('internally_consistent', $result['consistency_state']);
            $this->assertSame(CanonicalJson::hash($supplied), $result['supplied_input_hash']);
        } finally {
            $recording = false;
            $this->assertSame($connection, DB::connection());
            $this->assertSame($pdo, $connection->getPdo());
            if ($sqlite) {
                $pdo->exec('PRAGMA query_only = '.($originalQueryOnly === 1 ? 'ON' : 'OFF'));
                $this->assertSame($originalQueryOnly, (int) $pdo->query('PRAGMA query_only')->fetchColumn());
            }
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', preg_replace('/\\bfor\\s+update\\b/i', '', $query));
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(4999, $requirements['advertised_subtotal_minor']);
        foreach (['amount_minor', 'tax_minor', 'total_minor', 'amount_observation_id'] as $field) {
            $this->assertNull($result[$field]);
        }
        $this->assertSame('unobserved', $result['amount_evidence_state']);
        $this->assertStringNotContainsString('Private Synthetic Report', CanonicalJson::encode($result));
        $this->assertStringNotContainsString('private-report@example.test', CanonicalJson::encode($result));
        foreach (['buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'payable', 'execution_allowed', 'external_facts_verified',
            'provider_authenticated', 'payment_verified', 'rights_granted', 'consent_verified'] as $field) {
            $this->assertFalse($result[$field]);
        }
        Http::assertNothingSent();
    }

    public function test_frozen_historical_requirements_survive_closure_and_current_price_movement(): void
    {
        $f = Fixture::prepared(true);
        $service = app(CompareProductionAmountInputs::class);
        $supplied = $this->supplied(app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']));
        $result = $service->forPacket($f['packet']->public_id, $supplied, $f['actor']);
        app(CloseProductionTrackCapabilities::class)->applyReviewed(app(CloseProductionTrackCapabilities::class)->review($f['candidate'], $f['actor']),
            ['reason_code' => 'owner_withdrawal', ...Fixture::reference('closure_requested')], $f['actor']);
        DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false, 'price_minor' => 123]);
        DB::table('tracks')->where('id', $f['track']->id)->update(['status' => 'draft']);
        $before = $this->snapshot();
        $this->assertSame($result, $service->forPacket($f['packet']->public_id, $supplied, $f['reviewer']));
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue($result['requires_current_eligibility_proof']);
        $this->assertNull($result['tax_minor']);
        $this->assertNull($result['total_minor']);
        Http::assertNothingSent();
    }

    public static function refusal(): array
    {
        return [['staff'], ['mfa'], ['missing'], ['malformed'], ['nested']];
    }

    #[DataProvider('refusal')]
    public function test_existing_reader_refuses_current_authority_or_invalid_access_without_effects(string $scenario): void
    {
        $f = Fixture::prepared(true);
        $id = $f['packet']->public_id;
        $supplied = $this->supplied(app(ProductionAmountRequirements::class)->forPacket($id, $f['actor']));
        if ($scenario === 'staff') {
            DB::table('users')->where('id', $f['actor']->id)->update(['is_admin' => false]);
        } elseif ($scenario === 'mfa') {
            Filament::getPanel('admin')->multiFactorAuthentication(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders(), isRequired: true);
            $f['actor']->saveAppAuthenticationSecret(null);
        } elseif ($scenario === 'missing') {
            $id = '00000000-0000-4000-8000-000000000099';
        } elseif ($scenario === 'malformed') {
            $id = 'not-a-uuid';
        }
        $before = $this->snapshot();
        if ($scenario === 'nested') {
            DB::beginTransaction();
        }
        try {
            app(CompareProductionAmountInputs::class)->forPacket($id, $supplied, $f['actor']);
            $this->fail('Invalid authority/access was accepted.');
        } catch (ValidationException|AuthorizationException|LogicException) {
            $this->assertSame($before, $this->snapshot());
        } finally {
            if ($scenario === 'nested') {
                DB::rollBack();
            }
        }
        Http::assertNothingSent();
    }

    public static function lateDrift(): array
    {
        return [['actor'], ['packet'], ['line'], ['audit'], ['source audit']];
    }

    #[DataProvider('lateDrift')]
    public function test_final_primary_proof_rejects_late_drift_and_preserves_all_bookkeeping(string $scenario): void
    {
        $f = Fixture::prepared(true);
        $supplied = $this->supplied(app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']));
        $this->assertSame(0, DB::transactionLevel());
        if (in_array($scenario, ['packet', 'line'], true)) {
            // Damaged-restore setup only. MySQL DDL must precede the service transaction.
            DB::unprepared('DROP TRIGGER '.($scenario === 'packet' ? 'ptp_packet_update' : 'ptp_line_update'));
        }
        $before = $this->snapshot();
        $armed = false;
        $mutated = false;
        $original = Crypt::getFacadeRoot();
        $encrypter = Mockery::mock($original)->makePartial();
        $encrypter->shouldReceive('decryptString')->andReturnUsing(function (string $ciphertext) use ($original, $f, &$armed): string {
            $result = $original->decryptString($ciphertext);
            if ($ciphertext === $f['packet']->payload_ciphertext) {
                $armed = true;
            }

            return $result;
        });
        Crypt::swap($encrypter);
        DB::listen(function ($query) use ($f, $scenario, &$armed, &$mutated): void {
            if (! $armed || ! str_contains($query->sql, 'from "users"') && ! str_contains($query->sql, 'from `users`')) {
                return;
            }
            $armed = false;
            $mutated = true;
            match ($scenario) {
                'actor' => DB::table('users')->where('id', $f['actor']->id)->update(['is_admin' => false]),
                'packet' => DB::table(PacketEvidence::PACKETS)->update(['request_hash' => str_repeat('0', 64)]),
                'line' => DB::table(PacketEvidence::LINES)->update(['price_minor' => 1]),
                'audit' => DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->update(['action' => 'synthetic.late']),
                'source audit' => DB::table('audit_events')->where('subject_type', ProductionTrackCapabilities::class)->update(['action' => 'synthetic.late_source']),
            };
        });
        try {
            app(CompareProductionAmountInputs::class)->forPacket($f['packet']->public_id, $supplied, $f['actor']);
            $this->fail('Late drift was accepted.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertTrue($mutated);
            $this->assertSame($before, $this->snapshot());
        } finally {
            $armed = false;
        }
        Http::assertNothingSent();
    }

    public function test_private_or_provider_claims_refuse_without_hash_report_or_persistent_effects(): void
    {
        $f = Fixture::prepared(true);
        $s = $this->supplied(app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']));
        $before = $this->snapshot();
        foreach ([['buyer' => ['email' => 'private@example.test']], ['provider_receipt' => ['paid' => true]]] as $extra) {
            try {
                app(CompareProductionAmountInputs::class)->forPacket($f['packet']->public_id, $s + $extra, $f['actor']);
                $this->fail('Private/provider claim produced a report.');
            } catch (ValidationException $e) {
                $this->assertStringNotContainsString('private@example.test', json_encode($e->errors(), JSON_THROW_ON_ERROR));
                $this->assertSame($before, $this->snapshot());
            }
        }
        Http::assertNothingSent();
    }

    public function test_amount_comparison_does_not_depend_on_buyer_report_storage(): void
    {
        $f = Fixture::prepared(true);
        $s = $this->supplied(app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']));
        $this->assertSame(0, DB::transactionLevel());
        DB::statement('DROP TABLE '.ProductionBuyerAssentObservations::TABLE);
        $before = $this->snapshot();
        $report = app(CompareProductionAmountInputs::class)->forPacket($f['packet']->public_id, $s, $f['actor']);
        $this->assertSame('internally_consistent', $report['consistency_state']);
        $this->assertFalse($report['buyer_identity_verified']);
        $this->assertFalse($report['buyer_act_verified']);
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_staff_authority_precedes_diagnosis_of_private_supplied_input(): void
    {
        $f = Fixture::prepared(true);
        $s = $this->supplied(app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']));
        $s['buyer'] = ['email' => 'private@example.test'];
        DB::table('users')->where('id', $f['actor']->id)->update(['is_admin' => false]);
        $before = $this->snapshot();
        try {
            app(CompareProductionAmountInputs::class)->forPacket($f['packet']->public_id, $s, $f['actor']);
            $this->fail('Stale staff obtained a private-input diagnostic.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->snapshot());
        }
        Http::assertNothingSent();
    }
}
