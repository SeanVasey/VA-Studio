<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPolicy\CloseProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPreparation\AmountRequirementsV1;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\ProductionAmountRequirements;
use App\Domain\Commerce\ProductionPreparation\ProductionBuyerAssentObservations;
use App\Domain\Commerce\ProductionPreparation\ProductionTrackPreparationPackets;
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

class ProductionAmountRequirementsAccessTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
        $this->fakePrivateMediaStorage();
    }

    /** Every SQLite row, schema/trigger and migration entry, including empty commerce tables. */
    private function snapshot(): array
    {
        $pdo = DB::connection()->getPdo();
        $schema = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(\PDO::FETCH_ASSOC);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $rows = [];
        foreach ($tables as $table) {
            $values = $pdo->query('SELECT * FROM "'.str_replace('"', '""', $table).'"')->fetchAll(\PDO::FETCH_ASSOC);
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
        $packet = app(ProductionTrackPreparationPackets::class)->read($f['packet']->public_id, $f['actor']);
        $before = $this->snapshot();
        $queries = [];
        $recording = true;
        DB::listen(function ($query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });
        $pdo = DB::connection()->getPdo();
        $pdo->exec('PRAGMA query_only = ON');
        try {
            $this->assertSame(1, (int) $pdo->query('PRAGMA query_only')->fetchColumn());
            $result = app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']);
            AmountRequirementsV1::requireMatches($result, $packet);
        } finally {
            $recording = false;
            $pdo->exec('PRAGMA query_only = OFF');
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(4999, $result['advertised_subtotal_minor']);
        $this->assertStringNotContainsString('Private Synthetic Report', CanonicalJson::encode($result));
        $this->assertStringNotContainsString('private-report@example.test', CanonicalJson::encode($result));
        foreach (['buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'payable', 'execution_allowed', 'external_facts_verified'] as $field) {
            $this->assertFalse($result[$field]);
        }
        Http::assertNothingSent();
    }

    public function test_frozen_historical_requirements_survive_closure_and_current_price_movement(): void
    {
        $f = Fixture::prepared(true);
        $service = app(ProductionAmountRequirements::class);
        $result = $service->forPacket($f['packet']->public_id, $f['actor']);
        app(CloseProductionTrackCapabilities::class)->applyReviewed(app(CloseProductionTrackCapabilities::class)->review($f['candidate'], $f['actor']),
            ['reason_code' => 'owner_withdrawal', ...Fixture::reference('closure_requested')], $f['actor']);
        DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false, 'price_minor' => 123]);
        DB::table('tracks')->where('id', $f['track']->id)->update(['status' => 'draft']);
        $before = $this->snapshot();
        $this->assertSame($result, $service->forPacket($f['packet']->public_id, $f['reviewer']));
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
            app(ProductionAmountRequirements::class)->forPacket($id, $f['actor']);
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
        if (in_array($scenario, ['packet', 'line'], true)) {
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
            if (! $armed || ! str_contains($query->sql, 'from "users"')) {
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
            app(ProductionAmountRequirements::class)->forPacket($f['packet']->public_id, $f['actor']);
            $this->fail('Late drift was accepted.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertTrue($mutated);
            $this->assertSame($before, $this->snapshot());
        } finally {
            $armed = false;
        }
        Http::assertNothingSent();
    }
}
