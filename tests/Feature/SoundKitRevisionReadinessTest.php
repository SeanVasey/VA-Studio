<?php

namespace Tests\Feature;

use App\Domain\Catalog\FamilyRevisionSelection;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteSelection;
use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
use App\Domain\SoundKits\SoundKitRevisionReadiness;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitRevisionReadinessTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private function fixture(bool $process = true): array
    {
        MediaFixtures::configure();
        $actor = LicenseFixtures::admin();
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic technical kit',
            'description' => 'No commercial license or price', 'provenance' => 'Synthetic test generator'], $actor);
        $upload = UploadedFile::fake()->createWithContent('samples.zip', StemsFixtures::zip([
            ['name' => 'Kick.wav'], ['name' => 'Bass.wav', 'bytes' => MediaFixtures::wav(0.35, 120)],
        ]));
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        if ($process) {
            $revision = app(SoundKitProcessor::class)->handle($revision->id);
            $this->assertSame('ready', $revision->status, $revision->failure_code ?? '');
        }

        return [$actor, $draft, $revision];
    }

    private function selection(string $id, string $hash): FamilyRevisionSelection
    {
        return FamilyRevisionSelection::fromArray(['family' => 'wav_sample_kit', 'revisionId' => $id, 'manifestHash' => $hash]);
    }

    public function test_preparation_is_literal_default_off_before_any_resource_read(): void
    {
        $this->assertFalse(config('product-families.wav_sample_kit.technical_revision_preparation'));
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $selection = $this->selection('11111111-2222-3333-4444-555555555555', str_repeat('a', 64));
        foreach ([false, null, 1, 'true'] as $value) {
            config(['product-families.wav_sample_kit.technical_revision_preparation' => $value]);
            try {
                app(SoundKitRevisionReadiness::class)->capture($selection, new User);
                $this->fail('Only literal true can admit internal preparation.');
            } catch (MediaFailure $error) {
                $this->assertSame('family_preparation_disabled', $error->failureCode);
            }
        }
        $this->assertSame([], $queries);
    }

    public function test_exact_ready_revision_produces_only_a_private_noncommercial_technical_snapshot(): void
    {
        [$actor, $draft, $revision] = $this->fixture();
        config(['product-families.wav_sample_kit.technical_revision_preparation' => true]);
        $before = $revision->fresh()->getAttributes();
        $selected = $this->selection($revision->public_id, $revision->manifest_sha256);
        $result = app(SoundKitRevisionReadiness::class)->capture($selected, $actor);
        $snapshot = $result['snapshot'];
        $this->assertSame(CanonicalJson::hash($snapshot), $result['snapshot_sha256']);
        $this->assertSame([$draft->public_id, $revision->public_id, $revision->number], [$snapshot['kit_id'], $snapshot['revision_id'], $snapshot['revision_number']]);
        $this->assertSame($revision->manifest, $snapshot['manifest']);
        $this->assertSame($revision->manifest['archive'], $snapshot['archive']);
        $this->assertSame(2, $snapshot['member_count']);
        $this->assertFalse($snapshot['licensed']);
        $this->assertFalse($snapshot['payable']);
        $this->assertSame(['kit_license_policy_unset'], $snapshot['commercial_blockers']);
        $this->assertArrayNotHasKey('price_minor', $snapshot);
        $this->assertArrayNotHasKey('currency', $snapshot);
        $this->assertStringNotContainsString($revision->source_path, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($revision->archive_path, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $revision->fresh()->getAttributes());
        foreach (['tracks', 'offers', 'quotes'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->get('/sound-kits/'.$revision->public_id)->assertNotFound();
        $this->get('/storage/'.$revision->archive_path)->assertNotFound();
        try {
            app(QuoteSelection::class)->resolveFamily($selected);
            $this->fail('A technical kit snapshot cannot substitute for a licensed offer.');
        } catch (QuoteException $error) {
            $this->assertSame('FAMILY_LICENSE_POLICY_UNSET', $error->errorCode);
        }
        $this->assertSame(0, DB::table('quotes')->count());
    }

    public function test_missing_and_nonready_revisions_refuse_without_mutating_the_retained_revision(): void
    {
        [$actor, , $revision] = $this->fixture(false);
        config(['product-families.wav_sample_kit.technical_revision_preparation' => true]);
        $before = $revision->fresh()->getAttributes();
        foreach ([$revision->public_id, (string) Str::uuid()] as $id) {
            try {
                app(SoundKitRevisionReadiness::class)->capture($this->selection($id, str_repeat('a', 64)), $actor);
                $this->fail('No missing or unverified revision may be captured.');
            } catch (MediaFailure $error) {
                $this->assertSame('family_revision_changed', $error->failureCode);
            }
        }
        $this->assertSame($before, $revision->fresh()->getAttributes());
    }

    public function test_a_changed_expected_manifest_refuses_the_exact_existing_revision(): void
    {
        [$actor, , $revision] = $this->fixture();
        config(['product-families.wav_sample_kit.technical_revision_preparation' => true]);
        $wrong = ($revision->manifest_sha256[0] === '0' ? '1' : '0').substr($revision->manifest_sha256, 1);
        $this->expectException(MediaFailure::class);
        app(SoundKitRevisionReadiness::class)->capture($this->selection($revision->public_id, $wrong), $actor);
    }

    public function test_replaced_private_archive_bytes_refuse_even_when_the_retained_manifest_hash_matches(): void
    {
        [$actor, , $revision] = $this->fixture();
        config(['product-families.wav_sample_kit.technical_revision_preparation' => true]);
        $path = Storage::disk('local')->path($revision->archive_path);
        chmod($path, 0600);
        file_put_contents($path, 'replaced archive');
        $this->expectException(MediaFailure::class);
        app(SoundKitRevisionReadiness::class)->capture($this->selection($revision->public_id, $revision->manifest_sha256), $actor);
    }

    public function test_a_deleted_private_archive_refuses_instead_of_trusting_retained_scan_records(): void
    {
        [$actor, , $revision] = $this->fixture();
        config(['product-families.wav_sample_kit.technical_revision_preparation' => true]);
        $this->assertTrue(unlink(Storage::disk('local')->path($revision->archive_path)));
        $this->expectException(MediaFailure::class);
        app(SoundKitRevisionReadiness::class)->capture($this->selection($revision->public_id, $revision->manifest_sha256), $actor);
    }

    public function test_preparation_does_not_bypass_the_existing_staff_authority(): void
    {
        config(['product-families.wav_sample_kit.technical_revision_preparation' => true]);
        $actor = User::factory()->create(['is_admin' => false]);
        $this->expectException(AuthorizationException::class);
        app(SoundKitRevisionReadiness::class)->capture($this->selection((string) Str::uuid(), str_repeat('a', 64)), $actor);
    }
}
