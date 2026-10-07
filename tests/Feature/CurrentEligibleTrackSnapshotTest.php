<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use App\Domain\Catalog\Discovery\EligibleTrackSnapshot;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Inventory\SelectionInventory;
use App\Domain\Rights\Models\RightsDeclaration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class CurrentEligibleTrackSnapshotTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
    }

    public function test_empty_and_49_candidate_pages_are_bounded_private_and_complete_by_continuation(): void
    {
        $service = app(CurrentEligibleTrackSnapshot::class);
        $this->assertSame([], $service->capture()->paths());
        $rows = [];
        for ($i = 0; $i < 49; $i++) {
            $rows[] = ['title' => 'PRIVATE-'.$i, 'slug' => 'private-'.$i, 'published_slug' => 'private-'.$i, 'status' => 'published'];
        }
        DB::table('tracks')->insert($rows);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "tracks"') || str_contains($query->sql, 'from `tracks`')) {
                $queries[] = $query->sql;
            }
        });
        $first = $service->capture();
        $this->assertSame([], $first->paths());
        $this->assertNotNull($first->continuation());
        $this->assertStringNotContainsString('PRIVATE', $first->continuation());
        $this->assertStringNotContainsString('private-', $first->evidence());
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'limit 49')));
        $second = $service->capture($first->continuation());
        $this->assertSame([], $second->paths());
        $this->assertNull($second->continuation());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_current_paths_match_public_catalog_and_reject_unknown_or_changed_evidence(): void
    {
        $f = QuoteFixtures::selection();
        $service = app(CurrentEligibleTrackSnapshot::class);
        $snapshot = $service->capture();
        $this->assertSame(['/tracks/'.$f['track']->slug], $snapshot->paths());
        $this->assertSame([$f['track']->slug], array_column(app(PublicCatalog::class)->selections([$f['track']->id])['tracks'], 'slug'));
        $this->assertSame($snapshot->paths(), $service->currentPaths($snapshot));
        $false = new EligibleTrackSnapshot(['/tracks/private'], null, $snapshot->evidence());
        try {
            $service->currentPaths($false);
            $this->fail('Altered public projection admitted.');
        } catch (LogicException) {
            $this->assertSame(0, DB::transactionLevel());
        }
        RightsDeclaration::create(['track_id' => $f['track']->id, 'status' => 'pending', 'provenance_reference' => 'PRIVATE-NEW-HOLD', 'sample_disclosure' => 'Synthetic hold']);
        $this->assertSame([], $service->capture()->paths());
        $this->assertSame([], app(PublicCatalog::class)->selections([$f['track']->id])['tracks']);
        $this->expectException(LogicException::class);
        $service->currentPaths($snapshot);
    }

    public function test_private_byte_loss_and_configuration_changes_refuse_retained_projection(): void
    {
        $f = QuoteFixtures::selection();
        $service = app(CurrentEligibleTrackSnapshot::class);
        $snapshot = $service->capture();
        Storage::disk('local')->delete($f['media']['preview_tagged']->storage_path);
        try {
            $service->currentPaths($snapshot);
            $this->fail('Missing private preview admitted.');
        } catch (LogicException) {
            $this->assertSame([], $service->capture()->paths());
        }
        config(['media.profile_version' => 'changed-without-database-write']);
        $this->expectException(LogicException::class);
        $service->currentPaths($snapshot);
    }

    public function test_caller_transaction_and_forged_continuation_are_refused(): void
    {
        $service = app(CurrentEligibleTrackSnapshot::class);
        DB::beginTransaction();
        try {
            $service->capture();
            $this->fail('Inherited transaction admitted.');
        } catch (LogicException) {
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        foreach (['forged', Crypt::encryptString('{"version":1,"epoch":0,"after":-1}')] as $cursor) {
            try {
                $service->capture($cursor);
                $this->fail('Invalid cursor admitted.');
            } catch (LogicException) {
                $this->assertSame(0, DB::transactionLevel());
            }
        }
    }

    public function test_default_and_explicit_time_remain_equivalent_and_epoch_changes_during_read_fail_closed(): void
    {
        $f = QuoteFixtures::selection();
        $at = now()->toImmutable();
        $readiness = app(PublicationReadiness::class);
        $this->assertSame($readiness->blockers($f['track']), $readiness->blockers($f['track'], $at));
        $inventory = app(SelectionInventory::class);
        $this->assertSame($inventory->available($f['revision']->id), $inventory->available($f['revision']->id, null, $at));
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && preg_match('/from ["`]tracks["`].*limit 49/i', $query->sql)) {
                $armed = false;
                DB::table('tracks')->where('id', '>', 0)->update(['title' => 'Ordinary DML change']);
            }
        });
        try {
            app(CurrentEligibleTrackSnapshot::class)->capture();
            $this->fail('Read callback changed epoch but capture escaped.');
        } catch (LogicException) {
            $this->assertFalse($armed);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('Synthetic quote recording', $f['track']->fresh()->title);
        }
    }

    public function test_explicit_license_and_hold_times_preserve_defaults_and_crossing_refuses_capture(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $f = QuoteFixtures::selection();
        $expiry = now()->addDay()->startOfDay();
        $license = LicenseFixtures::published($f['actor'], content: ['effective_until' => $expiry]);
        $offer = app(SaveOfferDraft::class)->handle($f['offer'], ['license_version_id' => $license->id], $f['actor']);
        app(PublishOffer::class)->handle($offer, $f['actor']);
        $readiness = app(PublicationReadiness::class);
        $this->assertSame([], $readiness->blockers($f['track']));
        $this->assertNotEmpty($readiness->blockers($f['track'], $expiry));
        $armed = true;
        DB::listen(function ($query) use (&$armed, $expiry): void {
            if ($armed && preg_match('/from ["`]tracks["`].*limit 49/i', $query->sql)) {
                $armed = false;
                $this->travelTo($expiry);
            }
        });
        try {
            app(CurrentEligibleTrackSnapshot::class)->capture();
            $this->fail('License clock crossing admitted.');
        } catch (LogicException) {
            $this->assertFalse($armed);
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->travelTo($expiry->copy()->subHour());
        ExclusiveSelectionFixtures::configure();
        $e = ExclusiveSelectionFixtures::active();
        $quote = ExclusiveSelectionFixtures::quote($e);
        $hold = app(ReserveQuoteInventory::class)->hold($quote->public_id, InventoryFixtures::OWNER);
        $inventory = app(SelectionInventory::class);
        $this->assertFalse($inventory->available($e['revision']->id));
        $this->assertTrue($inventory->available($e['revision']->id, null, $hold->expires_at));
        $this->assertFalse($inventory->available($e['revision']->id, null, now()));
        $this->travelTo($hold->expires_at->copy()->subSeconds(5));
        $snapshot = app(CurrentEligibleTrackSnapshot::class)->capture();
        $evidence = json_decode(Crypt::decryptString($snapshot->evidence()), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($hold->expires_at->toISOString(), $evidence['expires_at']);
        $this->travelTo($hold->expires_at);
        $this->expectException(LogicException::class);
        app(CurrentEligibleTrackSnapshot::class)->currentPaths($snapshot);
    }

    public function test_original_capture_deadline_cannot_be_extended_during_consumption(): void
    {
        $this->travelTo(now()->startOfSecond());
        $fixture = QuoteFixtures::selection();
        $service = app(CurrentEligibleTrackSnapshot::class);
        $snapshot = $service->capture();
        $this->assertSame(['/tracks/'.$fixture['track']->slug], $snapshot->paths());
        $evidence = json_decode(Crypt::decryptString($snapshot->evidence()), true, 16, JSON_THROW_ON_ERROR);
        $deadline = CarbonImmutable::parse($evidence['expires_at']);
        $this->assertSame(now()->toImmutable()->addSeconds(CurrentEligibleTrackSnapshot::SECONDS)->toISOString(), $deadline->toISOString());
        $this->travelTo($deadline->subSecond());
        $this->assertSame($snapshot->paths(), $service->currentPaths($snapshot));
        $this->travelTo($deadline);
        try {
            $service->currentPaths($snapshot);
            $this->fail('Original deadline equality admitted.');
        } catch (LogicException) {
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->travelTo($deadline->subSecond());
        $armed = true;
        DB::listen(function ($query) use (&$armed, $deadline): void {
            if ($armed && preg_match('/from ["`]tracks["`].*limit 49/i', $query->sql)) {
                $armed = false;
                $this->travelTo($deadline);
            }
        });
        try {
            $service->currentPaths($snapshot);
            $this->fail('Retained evidence escaped after its original capture deadline elapsed during consumption.');
        } catch (LogicException) {
            $this->assertFalse($armed);
            $this->assertSame(0, DB::transactionLevel());
        }
    }
}
