<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Migration\CatalogOnboarding\CatalogDatabaseEvidence;
use App\Domain\Migration\CatalogOnboarding\CatalogDraftImporter;
use App\Domain\Migration\CatalogOnboarding\Models\CatalogImportBatch;
use App\Domain\Migration\CatalogOnboarding\Models\CatalogImportMapping;
use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\NormalizedCatalogFixtures;
use Tests\TestCase;

class PersistentCatalogDraftImportTest extends TestCase
{
    private array $release = ['commit' => '1111111111111111111111111111111111111111',
        'tree' => '2222222222222222222222222222222222222222', 'schema_hash' => '3333333333333333333333333333333333333333333333333333333333333333'];

    protected function setUp(): void
    {
        parent::setUp();
        // Each application owns an isolated in-memory database; no outer testing transaction masks standalone admission.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_dry_run_is_read_only_and_atomic_segments_resume_without_duplicate_rows_or_audits(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(3));
        $before = $this->evidence();
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $this->assertSame($before, $this->evidence());
        $this->assertSame(['create_draft' => 3, 'skip' => 0, 'conflict' => 0], $review['counts']);
        $this->assertSame(0, $review['database_writes']);
        $first = $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $this->assertSame(1, $first['processed']);
        $this->assertFalse($first['complete']);
        $this->assertCount(1, $first['created_track_ids']);
        $second = $importer->apply($source, $this->release, $review, $review['review_sha256'], 2, $actor);
        $this->assertSame(3, $second['processed']);
        $this->assertTrue($second['complete']);
        $this->assertCount(2, $second['created_track_ids']);
        $this->assertDatabaseCount('tracks', 3);
        $this->assertDatabaseCount('catalog_import_batches', 1);
        $this->assertDatabaseCount('catalog_import_mappings', 3);
        $this->assertDatabaseCount('audit_events', 7);
        $complete = $this->evidence();
        // Lost-success acknowledgment: replay the exact same reviewed input after the full commit.
        $replay = $importer->apply($source, $this->release, $review, $review['review_sha256'], 25, $actor);
        $this->assertSame([], $replay['created_track_ids']);
        $this->assertTrue($replay['complete']);
        $this->assertSame($complete, $this->evidence());
        foreach (Track::all() as $track) {
            $this->assertSame('draft', $track->status);
            $this->assertNull($track->published_slug);
            $this->assertSame(0, $track->publication_version);
            $this->assertSame(1, $track->metadata_version);
            $this->get('/tracks/'.$track->slug)->assertNotFound();
        }
        $stored = CatalogImportBatch::sole();
        $this->assertStringNotContainsString('SYNTHETIC', $stored->getRawOriginal('review_ciphertext'));
        $this->assertStringContainsString('SYNTHETIC', Crypt::decryptString($stored->getRawOriginal('review_ciphertext')));
        $this->assertStringNotContainsString('SYNTHETIC private note', AuditEvent::all()->toJson());
        (new PrivateSourceFiles)->unchanged($source);
    }

    public static function drift(): array
    {
        return array_map(static fn (string $case): array => [$case], ['review-digest', 'review-field', 'target-commit', 'target-tree',
            'target-schema', 'target-row', 'target-audit', 'source-bytes', 'source-replaced', 'zero-limit', 'over-limit']);
    }

    #[DataProvider('drift')]
    public function test_exact_review_source_and_current_target_binding_is_required_before_writes(string $case): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $digest = $review['review_sha256'];
        $release = $this->release;
        $limit = 1;
        switch ($case) {
            case 'review-digest': $digest = str_repeat('0', 64);
                break;
            case 'review-field': $review['entries'][0]['proposed_metadata']['title'] = 'SYNTHETIC forged';
                break;
            case 'target-commit': $release['commit'] = str_repeat('4', 40);
                break;
            case 'target-tree': $release['tree'] = str_repeat('4', 40);
                break;
            case 'target-schema': $release['schema_hash'] = str_repeat('4', 64);
                break;
            case 'target-row': app(SaveTrackMetadata::class)->handle(null, ['title' => 'SYNTHETIC unrelated', 'slug' => 'synthetic-unrelated'], $actor);
                break;
            case 'target-audit': AuditEvent::record('synthetic.observation', $actor, ['synthetic' => true], $actor->id);
                break;
            case 'source-bytes': file_put_contents($source['directory'].'/raw/source.csv', str_repeat('X', strlen(NormalizedCatalogFixtures::ARTIFACT)));
                break;
            case 'source-replaced': $path = $source['directory'].'/catalog.json';
                $replacement = $source['directory'].'/replacement.json';
                file_put_contents($replacement, file_get_contents($path));
                chmod($replacement, 0600);
                rename($replacement, $path);
                break;
            case 'zero-limit': $limit = 0;
                break;
            case 'over-limit': $limit = 26;
                break;
        }
        $before = $this->evidence();
        try {
            $importer->apply($source, $release, $review, $digest, $limit, $actor);
            $this->fail('Changed review admitted: '.$case);
        } catch (\InvalidArgumentException|ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertDatabaseCount('catalog_import_batches', 0);
        $this->assertDatabaseCount('catalog_import_mappings', 0);
    }

    public static function conflicts(): array
    {
        return array_map(static fn (string $case): array => [$case], ['duplicate-source', 'source-slug-collision', 'target-slug', 'retained-url', 'sold', 'unknown']);
    }

    #[DataProvider('conflicts')]
    public function test_conflicts_have_explicit_disposition_and_never_overwrite_or_publish(string $case): void
    {
        $actor = LicenseFixtures::admin();
        $snapshot = NormalizedCatalogFixtures::snapshot(2);
        switch ($case) {
            case 'duplicate-source': $snapshot['records'][1]['source_id'] = $snapshot['records'][0]['source_id'];
                break;
            case 'source-slug-collision': $snapshot['records'][1]['metadata']['slug'] = $snapshot['records'][0]['metadata']['slug'];
                break;
            case 'target-slug':
            case 'retained-url':
                $track = app(SaveTrackMetadata::class)->handle(null, $snapshot['records'][0]['metadata'], $actor);
                if ($case === 'retained-url') {
                    DB::table('tracks')->where('id', $track->id)->update(['published_slug' => $track->slug]);
                }
                break;
            case 'sold':
            case 'unknown': $snapshot['records'][0]['visibility'] = $case;
                break;
        }
        $snapshot['records'] = array_map(NormalizedCatalogFixtures::seal(...), $snapshot['records']);
        $source = $this->source($snapshot);
        $before = $this->evidence();
        $review = (new CatalogDraftImporter)->review($source, $this->release, $actor);
        $this->assertGreaterThan(0, $review['counts']['conflict']);
        try {
            (new CatalogDraftImporter)->apply($source, $this->release, $review, $review['review_sha256'], 25, $actor);
            $this->fail('Conflict was applied.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_audit_failure_after_the_second_track_rolls_back_the_whole_segment(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(3));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $before = $this->evidence();
        $created = 0;
        AuditEvent::creating(function (AuditEvent $audit) use (&$created): void {
            if ($audit->action === 'catalog.track.created' && ++$created === 2) {
                throw new RuntimeException('SYNTHETIC audit failure');
            }
        });
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 3, $actor);
            $this->fail('Audit failure committed.');
        } catch (RuntimeException $error) {
            $this->assertSame('SYNTHETIC audit failure', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function lateMutation(): array
    {
        return array_map(static fn (string $case): array => [$case], ['prior-track', 'actor', 'source', 'audit', 'query-observer']);
    }

    #[DataProvider('lateMutation')]
    public function test_final_direct_current_proof_rejects_late_application_and_query_callbacks(string $case): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(2));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $before = $this->evidence();
        $mutate = function () use ($case, $source, $actor): void {
            $pdo = DB::connection()->getPdo();
            if (in_array($case, ['prior-track', 'query-observer'], true)) {
                $pdo->exec("UPDATE tracks SET description='SYNTHETIC late mutation' WHERE id=(SELECT min(id) FROM tracks)");
            }
            if ($case === 'actor') {
                $pdo->exec('UPDATE users SET is_admin=0 WHERE id='.(int) $actor->id);
            }
            if ($case === 'source') {
                file_put_contents($source['directory'].'/raw/source.csv', str_repeat('X', strlen(NormalizedCatalogFixtures::ARTIFACT)));
            }
            if ($case === 'audit') {
                $pdo->exec("UPDATE audit_events SET actor_id=NULL WHERE action='catalog.track.created' AND subject_id=(SELECT min(id) FROM tracks)");
            }
        };
        $mapped = 0;
        if ($case === 'query-observer') {
            DB::listen(function (QueryExecuted $query) use (&$mapped, $mutate): void {
                if (str_starts_with($query->sql, 'insert into "audit_events"') && in_array('migration.catalog_draft.mapped', $query->bindings, true) && ++$mapped === 2) {
                    $mutate();
                }
            });
        } else {
            AuditEvent::created(function (AuditEvent $audit) use (&$mapped, $mutate): void {
                if ($audit->action === 'migration.catalog_draft.mapped' && ++$mapped === 2) {
                    $mutate();
                }
            });
        }
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 2, $actor);
            $this->fail('Late mutation committed: '.$case);
        } catch (ValidationException|AuthorizationException|\InvalidArgumentException) {
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(1, (new CatalogDatabaseEvidence)->rows('users')[$actor->id]['attributes']['is_admin']);
    }

    public function test_current_revoked_authority_and_required_mfa_refuse_stale_actor_objects(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
            $this->fail('Revoked actor admitted.');
        } catch (AuthorizationException) {
        }
        DB::table('users')->where('id', $actor->id)->update(['is_admin' => true]);
        Filament::getPanel('admin')->multiFactorAuthentication(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $importer->review($source, $this->release, $actor);
            $this->fail('Missing MFA admitted.');
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('catalog_import_batches', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_mappings_are_immutable_and_changed_authored_or_public_tracks_require_a_new_disposition(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(2));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $mapping = CatalogImportMapping::sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    DB::table('catalog_import_mappings')->where('id', $mapping->id)->update(['record_key' => str_repeat('0', 64)]);
                } else {
                    DB::table('catalog_import_mappings')->where('id', $mapping->id)->delete();
                }
                $this->fail('Immutable mapping mutated.');
            } catch (QueryException) {
            }
        }
        $track = Track::sole();
        app(SaveTrackMetadata::class)->handle($track, ['metadata_version' => 1, 'title' => 'SYNTHETIC manually revised'], $actor);
        $before = $this->evidence();
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
            $this->fail('Modified track overwritten.');
        } catch (ValidationException) {
        }
        $next = $importer->review($source, $this->release, $actor);
        $this->assertContains('mapped_target_changed', $next['entries'][0]['reasons']);
        $this->assertSame($before, $this->evidence());
    }

    public function test_nested_transaction_is_refused_and_no_query_callbacks_follow_the_final_mapping_audit(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        DB::beginTransaction();
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
            $this->fail('Nested transaction admitted.');
        } catch (ValidationException) {
        } finally {
            DB::rollBack();
        }
        $lastAudit = false;
        $lateQueries = 0;
        AuditEvent::created(function (AuditEvent $audit) use (&$lastAudit): void {
            if ($audit->action === 'migration.catalog_draft.mapped') {
                $lastAudit = true;
            }
        });
        DB::listen(function () use (&$lastAudit, &$lateQueries): void {
            if ($lastAudit) {
                $lateQueries++;
            }
        });
        $result = $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $this->assertTrue($result['complete']);
        $this->assertSame(0, $lateQueries);
    }

    public function test_fresh_review_of_exact_existing_mappings_skips_without_duplicate_drafts_or_mappings(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(2));
        $importer = new CatalogDraftImporter;
        $first = $importer->review($source, $this->release, $actor);
        $importer->apply($source, $this->release, $first, $first['review_sha256'], 2, $actor);
        $beforeTracks = (new CatalogDatabaseEvidence)->rows('tracks');
        $beforeMappings = (new CatalogDatabaseEvidence)->rows('catalog_import_mappings');
        $review = $importer->review($source, $this->release, $actor);
        $this->assertSame(['create_draft' => 0, 'skip' => 2, 'conflict' => 0], $review['counts']);
        $result = $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $this->assertTrue($result['complete']);
        $this->assertSame(2, $result['processed']);
        $this->assertSame([], $result['created_track_ids']);
        $this->assertSame($beforeTracks, (new CatalogDatabaseEvidence)->rows('tracks'));
        $this->assertSame($beforeMappings, (new CatalogDatabaseEvidence)->rows('catalog_import_mappings'));
        $after = $this->evidence();
        $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $this->assertSame($after, $this->evidence());
    }

    public function test_declared_media_and_source_visibility_remain_evidence_without_any_commercial_effect(): void
    {
        $actor = LicenseFixtures::admin();
        $snapshot = NormalizedCatalogFixtures::snapshot(1);
        $snapshot['records'][0]['visibility'] = 'public';
        $snapshot['records'][0]['assets'] = [['source_asset_id' => 'SYNTHETIC media reference',
            'artifact_id' => 'synthetic-raw', 'role' => 'master_wav', 'original_name' => 'SYNTHETIC.wav',
            'sha256' => hash('sha256', NormalizedCatalogFixtures::ARTIFACT), 'bytes' => strlen(NormalizedCatalogFixtures::ARTIFACT)]];
        $snapshot['records'][0] = NormalizedCatalogFixtures::seal($snapshot['records'][0]);
        $source = $this->source($snapshot);
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $this->assertSame(['separate_media_quarantine_intake'], $review['entries'][0]['dependent_work']);
        $this->assertSame('public', $review['entries'][0]['source_visibility']);
        $this->assertSame('draft', $review['entries'][0]['proposed_visibility']);
        $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        foreach (['media_assets', 'rights_declarations', 'offers', 'offer_revisions', 'license_versions', 'orders', 'pending_entitlements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('draft', Track::sole()->status);
        $evidence = json_decode(Crypt::decryptString(CatalogImportMapping::sole()->getRawOriginal('evidence_ciphertext')), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(CanonicalJson::encode($snapshot['records'][0]['assets']), CanonicalJson::encode($evidence['record']['assets']));
        $this->get('/tracks/'.Track::sole()->slug)->assertNotFound();
    }

    public function test_source_os_lease_refuses_simultaneous_apply_and_is_released_after_failed_operation(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $before = $this->evidence();
        (new PrivateSourceFiles)->leased($source, function () use ($importer, $source, $review, $actor): void {
            try {
                $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
                $this->fail('A second source lease was admitted.');
            } catch (\InvalidArgumentException) {
            }
        });
        $this->assertSame($before, $this->evidence());
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor,
                static fn () => throw new RuntimeException('SYNTHETIC changed installation'));
            $this->fail('External verification failure committed.');
        } catch (RuntimeException $error) {
            $this->assertSame('SYNTHETIC changed installation', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $result = $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $this->assertTrue($result['complete']);
    }

    public function test_batch_evidence_is_immutable_and_its_referenced_actor_and_track_are_retained(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $before = $this->evidence();
        foreach (['update', 'delete', 'actor-delete', 'track-delete'] as $operation) {
            try {
                match ($operation) {
                    'update' => DB::table('catalog_import_batches')->update(['transform_version' => 'SYNTHETIC changed']),
                    'delete' => DB::table('catalog_import_batches')->delete(),
                    'actor-delete' => DB::table('users')->where('id', $actor->id)->delete(),
                    'track-delete' => DB::table('tracks')->delete(),
                };
                $this->fail('Retained evidence was destroyed: '.$operation);
            } catch (QueryException) {
            }
        }
        $this->assertSame($before, $this->evidence());
        $migration = require base_path('database/migrations/2026_10_06_237000_catalog_import_progress.php');
        try {
            $migration->down();
            $this->fail('Retained import history was rolled back.');
        } catch (\LogicException) {
        }
        $this->assertSame($before, $this->evidence());
    }

    private function source(array $snapshot): array
    {
        $base = sys_get_temp_dir().'/vasey-import-fixture-'.bin2hex(random_bytes(12));
        mkdir($base, 0700);
        mkdir($base.'/source', 0700);
        mkdir($base.'/source/raw', 0700);
        file_put_contents($base.'/source/catalog.json', CanonicalJson::encode($snapshot)."\n");
        file_put_contents($base.'/source/raw/source.csv', NormalizedCatalogFixtures::ARTIFACT);
        chmod($base.'/source/catalog.json', 0600);
        chmod($base.'/source/raw/source.csv', 0600);
        $this->beforeApplicationDestroyed(static fn () => (new Filesystem)->deleteDirectory($base));

        return (new PrivateSourceFiles)->snapshot($base.'/source');
    }

    private function evidence(): array
    {
        $database = new CatalogDatabaseEvidence;

        return [$database->target(), $database->rows('catalog_import_batches'), $database->rows('catalog_import_mappings')];
    }
}
