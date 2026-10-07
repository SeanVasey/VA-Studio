<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPolicy\CloseProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPreparation\Models\ProductionTrackPreparationPacket;
use App\Domain\Commerce\ProductionPreparation\Models\ProductionTrackPreparationPacketLine;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Domain\Commerce\ProductionPreparation\PrepareProductionTrackPreparationPacket;
use App\Domain\Commerce\ProductionPreparation\ReadProductionTrackPreparationPacket;
use App\Domain\Commerce\ProductionPreparation\SaveProductionTrackPreparationPacket;
use App\Support\Audit\AuditEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\TestCase;

class ProductionTrackPreparationPacketGuardsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
        $this->fakePrivateMediaStorage();
    }

    public static function lateDrift(): array
    {
        return [['offer active'], ['current revision'], ['new rights selector'], ['track metadata'], ['license'], ['packet'], ['line'], ['audit'], ['actor'], ['source audit']];
    }

    #[DataProvider('lateDrift')]
    public function test_core_post_prepare_user_query_drift_refuses_packet_and_audit_atomically(string $scenario): void
    {
        $f = Fixture::prepared();
        // Explicit damaged-restore canaries only; the operational guards stay
        // strict in shipped migrations. Catalog active/current fields are editable.
        foreach (match ($scenario) {
            'license' => ['license_review_state_guard_v4', 'license_review_content_guard'], 'packet' => ['ptp_packet_update'], 'line' => ['ptp_line_update'], default => []
        } as $trigger) {
            DB::unprepared('DROP TRIGGER '.$trigger);
        }
        $before = Fixture::rows();
        $armed = false;
        $mutated = false;
        AuditEvent::created(function (AuditEvent $event) use (&$armed): void {
            if ($event->action === 'commerce.production_preparation.packet_retained') {
                $armed = true;
            }
        });
        DB::listen(function ($query) use (&$armed, &$mutated, $scenario, $f): void {
            if (! $armed || ! str_contains(strtolower($query->sql), 'from "users"') && ! str_contains(strtolower($query->sql), 'from `users`')) {
                return;
            }
            $armed = false;
            $mutated = true;
            match ($scenario) {
                'offer active' => DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false]),
                'current revision' => DB::table('offers')->where('id', $f['offer']->id)->update(['current_revision_id' => null]),
                'new rights selector' => DB::table('rights_declarations')->insert(['track_id' => $f['track']->id, 'provenance_reference' => 'SYNTHETIC LATE RIGHT',
                    'sample_disclosure' => 'Test only', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]),
                'track metadata' => DB::table('tracks')->where('id', $f['track']->id)->update(['title' => 'SYNTHETIC LATE TITLE']),
                'license' => DB::table('license_versions')->where('id', $f['items'][0]['licenseVersionId'])->update(['status' => 'approved']),
                'packet' => DB::table(PacketEvidence::PACKETS)->update(['request_hash' => str_repeat('0', 64)]),
                'line' => DB::table(PacketEvidence::LINES)->update(['price_minor' => 1]),
                'audit' => DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->update(['action' => 'synthetic.changed']),
                'actor' => DB::table('users')->where('id', $f['actor']->id)->update(['is_admin' => false]),
                'source audit' => DB::table('audit_events')->where('subject_type', ProductionTrackCapabilities::class)->update(['action' => 'synthetic.source_changed']),
            };
        });
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $this->fail('Late consumer/authority/source drift was admitted.');
        } catch (ValidationException|AuthorizationException) {
            $this->assertTrue($mutated, 'Canary must run during the core post-prepare authority read.');
            $this->assertSame($before, Fixture::rows());
            $this->assertDatabaseCount(PacketEvidence::PACKETS, 0);
            $this->assertDatabaseCount(PacketEvidence::LINES, 0);
        } finally {
            $armed = false;
        }
        Http::assertNothingSent();
    }

    public function test_license_expiry_during_core_final_authority_refresh_refuses_all_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
        $f = Fixture::prepared(licenseUntil: '2026-10-06T12:00:01Z');
        $before = Fixture::rows();
        $armed = false;
        $crossed = false;
        AuditEvent::created(function (AuditEvent $event) use (&$armed): void {
            if ($event->action === 'commerce.production_preparation.packet_retained') {
                $armed = true;
            }
        });
        DB::listen(function ($query) use (&$armed, &$crossed): void {
            if ($armed && (str_contains($query->sql, 'from "users"') || str_contains($query->sql, 'from `users`'))) {
                $armed = false;
                $crossed = true;
                CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-06T12:00:02Z'));
                Carbon::setTestNow(CarbonImmutable::parse('2026-10-06T12:00:02Z'));
            }
        });
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $this->fail('Expired license admitted.');
        } catch (ValidationException) {
            $this->assertTrue($crossed);
            $this->assertSame($before, Fixture::rows());
        } finally {
            $armed = false;
            $this->travelBack();
        }
    }

    public function test_stale_closed_candidate_and_current_mfa_refuse_fresh_writes(): void
    {
        $f = Fixture::prepared();
        app(CloseProductionTrackCapabilities::class)->applyReviewed(app(CloseProductionTrackCapabilities::class)->review($f['candidate'], $f['actor']),
            ['reason_code' => 'owner_withdrawal', ...Fixture::reference('closure_requested')], $f['actor']);
        $before = Fixture::rows();
        try {
            app(SaveProductionTrackPreparationPacket::class)->applyReviewed($f['capture'], $f['actor']);
            $this->fail('Closed source wrote.');
        } catch (ValidationException) {
            $this->assertSame($before, Fixture::rows());
        }
        Filament::getPanel('admin')->multiFactorAuthentication(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders(), isRequired: true);
        $f['actor']->saveAppAuthenticationSecret(null);
        try {
            app(ReadProductionTrackPreparationPacket::class)->recover($f['key'], $f['actor']);
            $this->fail('Missing MFA recovered.');
        } catch (AuthorizationException) {
            $this->assertSame($before, Fixture::rows());
        }
    }

    public static function unsupportedCatalog(): array
    {
        return [['inactive selection'], ['bad latest rights'], ['missing preview'], ['unsupported active offer'], ['stale renderer'], ['missing run output'], ['damaged asset hash']];
    }

    #[DataProvider('unsupportedCatalog')]
    public function test_current_interpreted_primary_eligibility_refuses_unsupported_or_damaged_catalog(string $scenario): void
    {
        $f = Fixture::prepared();
        if ($scenario === 'stale renderer') {
            DB::unprepared('DROP TRIGGER license_review_content_guard');
            DB::unprepared('DROP TRIGGER license_review_state_guard_v4');
        }
        if (in_array($scenario, ['missing preview', 'missing run output', 'damaged asset hash'], true)) {
            DB::unprepared('DROP TRIGGER media_assets_immutable_update');
        }
        match ($scenario) {
            'inactive selection' => DB::table('offers')->where('id', $f['offer']->id)->update(['is_active' => false]),
            'bad latest rights' => DB::table('rights_declarations')->insert(['track_id' => $f['track']->id, 'provenance_reference' => 'SYNTHETIC NEW RIGHTS', 'sample_disclosure' => 'Test', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]),
            'missing preview' => DB::table('media_assets')->where('id', $f['media']['preview_tagged']->id)->update(['status' => 'failed']),
            'unsupported active offer' => DB::table('offers')->insert(['track_id' => $f['track']->id, 'license_version_id' => $f['items'][0]['licenseVersionId'], 'price_minor' => 1, 'currency' => 'USD', 'deliverable_asset_ids' => '[]', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]),
            'stale renderer' => DB::table('license_versions')->where('id', $f['items'][0]['licenseVersionId'])->update(['renderer_version' => 'synthetic-stale-renderer']),
            'missing run output' => DB::table('media_assets')->where('id', $f['media']['download_mp3']->id)->update(['processing_run_id' => null]),
            'damaged asset hash' => DB::table('media_assets')->where('id', $f['media']['master_wav']->id)->update(['sha256' => str_repeat('0', 64)]),
        };
        $before = Fixture::rows();
        try {
            app(PrepareProductionTrackPreparationPacket::class)->review($f['candidate'], $f['context'], $f['items'], 'fresh-unsupported', $f['actor']);
            $this->fail('Unsupported catalog prepared.');
        } catch (ValidationException) {
            $this->assertSame($before, Fixture::rows());
        }
    }

    public static function immutableOperations(): array
    {
        return [[PacketEvidence::PACKETS, 'update'], [PacketEvidence::PACKETS, 'delete'], [PacketEvidence::PACKETS, 'replace'],
            [PacketEvidence::LINES, 'update'], [PacketEvidence::LINES, 'delete'], [PacketEvidence::LINES, 'replace']];
    }

    #[DataProvider('immutableOperations')]
    public function test_raw_update_delete_and_sqlite_replace_with_recursive_triggers_off_preserve_evidence(string $table, string $operation): void
    {
        Fixture::prepared(true);
        $before = Fixture::rows();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        try {
            if ($operation === 'update') {
                DB::table($table)->update(['id' => 999]);
            } elseif ($operation === 'delete') {
                DB::table($table)->delete();
            } else {
                $row = (array) DB::table($table)->sole();
                $columns = array_keys($row);
                $grammar = DB::connection()->getQueryGrammar();
                DB::insert('REPLACE INTO '.$table.' ('.implode(',', array_map($grammar->wrap(...), $columns)).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')', array_values($row));
            }
            $this->fail('Immutable evidence changed.');
        } catch (QueryException) {
            $this->assertSame($before, Fixture::rows());
        }
    }

    public function test_model_writes_and_damaged_retained_line_or_audit_are_refused(): void
    {
        $f = Fixture::prepared(true);
        foreach ([ProductionTrackPreparationPacket::class, ProductionTrackPreparationPacketLine::class] as $class) {
            try {
                (new $class)->save();
                $this->fail('Model write admitted.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        DB::table('audit_events')->where('action', 'commerce.production_preparation.packet_retained')->delete();
        $this->expectException(ValidationException::class);
        app(ReadProductionTrackPreparationPacket::class)->recover($f['key'], $f['actor']);
    }
}
