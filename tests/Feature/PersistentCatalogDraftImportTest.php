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
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DisposableNativeDatabase;
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
        if (DB::getDriverName() === 'mysql') {
            // The InnoDB contract is proved on the suite's own MySQL connection. Every case rebuilds the whole
            // database, so only the dedicated synthetic schema or the CI job's disposable database is admitted.
            $this->assertTrue(DisposableNativeDatabase::isAdmitted('vaseyaudio_catalog_import'),
                'A dedicated synthetic catalog-import schema, or the CI job\'s disposable database, is required.');
        } else {
            // This private retained-workspace surface keeps its explicit SQLite contract under every other
            // surrounding suite driver. It is not MySQL lock evidence.
            config(['database.connections.catalog_private_fixture' => array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'url' => null])]);
            DB::setDefaultConnection('catalog_private_fixture');
        }
        // Each application owns an isolated database; no outer testing transaction masks standalone admission.
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
                // MySQL refuses a subquery on the updated table (error 1093); SQLite has no UPDATE ... LIMIT.
                $pdo->exec($this->mysql() ? "UPDATE tracks SET description='SYNTHETIC late mutation' ORDER BY id LIMIT 1"
                    : "UPDATE tracks SET description='SYNTHETIC late mutation' WHERE id=(SELECT min(id) FROM tracks)");
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
                if (str_starts_with($query->sql, 'insert into '.$this->identifier('audit_events')) && in_array('migration.catalog_draft.mapped', $query->bindings, true) && ++$mapped === 2) {
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

    public static function schemaDrift(): array
    {
        return array_map(static fn (string $case): array => [$case], ['missing-trigger', 'foreign-trigger', 'missing-unique',
            'foreign-key-cascade', 'nullable-column', 'extra-column', 'foreign-keys-disabled', 'writable-schema', 'ignored-checks']);
    }

    #[DataProvider('schemaDrift')]
    public function test_foreign_or_incomplete_live_owned_schema_refuses_review_and_apply_without_repairs(string $case): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $this->changeSchema($case);
        $before = $this->evidence();
        foreach (['review', 'apply'] as $operation) {
            try {
                $operation === 'review' ? $importer->review($source, $this->release, $actor)
                    : $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
                $this->fail('Foreign installed schema admitted: '.$case.' '.$operation);
            } catch (RuntimeException $error) {
                $this->assertSame('catalog_target_schema_invalid', $error->getMessage());
            }
            $this->assertSame($before, $this->evidence());
        }
    }

    public function test_schema_changes_after_review_or_final_mapping_callbacks_roll_back_without_any_missing_constraint(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $schema = (new CatalogDatabaseEvidence)->schema();
        $before = $this->evidence();
        try {
            $importer->review($source, $this->release, $actor, fn () => $this->changeSchema('missing-trigger'));
            $this->fail('Late dry-run schema change admitted.');
        } catch (RuntimeException $error) {
            $this->assertSame('catalog_target_schema_invalid', $error->getMessage());
        }
        if ($this->mysql()) {
            // MySQL DDL commits implicitly, so the dropped trigger outlives the refused dry run and the live
            // digest keeps refusing until the owned trigger is restored; SQLite rolled the DDL back.
            $this->assertSchemaRefused();
            $this->restoreOwnedDeleteTrigger();
        }
        $this->assertSame($schema, (new CatalogDatabaseEvidence)->schema());
        $this->assertSame($before, $this->evidence());
        $review = $importer->review($source, $this->release, $actor);
        AuditEvent::created(function (AuditEvent $event): void {
            if ($event->action === 'migration.catalog_draft.mapped') {
                $this->changeSchema('missing-trigger');
            }
        });
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
            $this->fail('Final mapping callback changed the installed schema.');
        } catch (RuntimeException $error) {
            $this->assertSame('catalog_target_schema_invalid', $error->getMessage());
        }
        if (! $this->mysql()) {
            $this->assertSame($schema, (new CatalogDatabaseEvidence)->schema());
            $this->assertSame($before, $this->evidence());

            return;
        }
        $this->assertCommittedPrefixAfterCallbackDdl($importer, $source, $review, $actor, $schema);
    }

    public function test_final_direct_schema_proof_rejects_late_query_observer_and_target_mutations(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $schema = (new CatalogDatabaseEvidence)->schema();
        $before = $this->evidence();
        try {
            $importer->review($source, $this->release, $actor, function () use ($actor): void {
                AuditEvent::record('SYNTHETIC late dry-run write', $actor, ['synthetic' => true], $actor->id);
            });
            $this->fail('A late target write passed the read-only review proof.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->evidence());
        $review = $importer->review($source, $this->release, $actor);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into '.$this->identifier('audit_events')) && in_array('migration.catalog_draft.mapped', $query->bindings, true)) {
                $this->changeSchema('foreign-trigger');
            }
        });
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
            $this->fail('A final query observer replaced the immutable trigger.');
        } catch (RuntimeException $error) {
            $this->assertSame('catalog_target_schema_invalid', $error->getMessage());
        }
        if (! $this->mysql()) {
            $this->assertSame($schema, (new CatalogDatabaseEvidence)->schema());
            $this->assertSame($before, $this->evidence());

            return;
        }
        $this->assertCommittedPrefixAfterCallbackDdl($importer, $source, $review, $actor, $schema);
    }

    public static function replacementIdentity(): array
    {
        return array_map(static fn (string $case): array => [$case], ['batch-id', 'batch-digest', 'mapping-id', 'mapping-key', 'mapping-track']);
    }

    #[DataProvider('replacementIdentity')]
    public function test_raw_replace_cannot_rewrite_retained_evidence_when_recursive_delete_triggers_are_disabled(string $case): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $table = str_starts_with($case, 'batch') ? 'catalog_import_batches' : 'catalog_import_mappings';
        $row = (new CatalogDatabaseEvidence)->rows($table)[1]['attributes'];
        $pdo = DB::connection()->getPdo();
        if ($this->mysql()) {
            // MySQL REPLACE deletes the conflicting row before inserting, which fires the immutable delete trigger.
            $verb = 'REPLACE INTO';
        } else {
            $pdo->exec('PRAGMA recursive_triggers=OFF');
            $this->assertSame(0, $pdo->query('PRAGMA recursive_triggers')->fetchColumn());
            $verb = 'INSERT OR REPLACE INTO';
        }
        if (! str_ends_with($case, '-id')) {
            $row['id'] = 999;
        }
        if ($case === 'batch-id') {
            $row['review_sha256'] = str_repeat('e', 64);
        }
        if ($case === 'mapping-id') {
            $row['record_key'] = str_repeat('e', 64);
            $row['track_id'] = 999;
        }
        if ($case === 'mapping-key') {
            $row['track_id'] = 999;
        }
        if ($case === 'mapping-track') {
            $row['record_key'] = str_repeat('e', 64);
        }
        $row[str_starts_with($case, 'batch') ? 'review_ciphertext' : 'evidence_ciphertext'] = 'SYNTHETIC attempted replacement';
        $before = $this->evidence();
        try {
            $statement = $pdo->prepare($verb.' '.$this->identifier($table).' ('.implode(', ', array_map($this->identifier(...), array_keys($row))).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
            $statement->execute(array_values($row));
            $this->fail('Retained evidence replaced: '.$case);
        } catch (PDOException $error) {
            $this->assertStringContainsString('Catalog import evidence is immutable', $error->getMessage());
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_partial_failure_while_the_second_mapping_is_created_leaves_no_rows_and_the_next_apply_resumes_exactly(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(3));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $before = $this->evidence();
        $failing = true;
        $created = 0;
        CatalogImportMapping::creating(function () use (&$failing, &$created): void {
            if ($failing && ++$created === 2) {
                throw new RuntimeException('SYNTHETIC mapping failure');
            }
        });
        try {
            $importer->apply($source, $this->release, $review, $review['review_sha256'], 3, $actor);
            $this->fail('Mapping failure committed.');
        } catch (RuntimeException $error) {
            $this->assertSame('SYNTHETIC mapping failure', $error->getMessage());
        }
        // The first draft, its audits, the accepted batch and the first mapping all roll back together.
        $this->assertSame($before, $this->evidence());
        foreach (['tracks', 'catalog_import_batches', 'catalog_import_mappings', 'audit_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $failing = false;
        $result = $importer->apply($source, $this->release, $review, $review['review_sha256'], 3, $actor);
        $this->assertTrue($result['complete']);
        $this->assertCount(3, $result['created_track_ids']);
        $this->assertExactlyOneRowSetPerRecord(3);
        $complete = $this->evidence();
        $replay = $importer->apply($source, $this->release, $review, $review['review_sha256'], 25, $actor);
        $this->assertSame([], $replay['created_track_ids']);
        $this->assertSame($complete, $this->evidence());
    }

    public function test_overlapping_duplicate_applies_create_exactly_one_draft_mapping_and_audit_set_per_record(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(3));
        $importer = new CatalogDraftImporter;
        $review = $importer->review($source, $this->release, $actor);
        $first = $importer->apply($source, $this->release, $review, $review['review_sha256'], 2, $actor);
        $this->assertSame(2, $first['processed']);
        $this->assertCount(2, $first['created_track_ids']);
        // The same segment request again: the retained prefix is honoured and only the third record is created.
        $second = $importer->apply($source, $this->release, $review, $review['review_sha256'], 2, $actor);
        $this->assertSame(3, $second['processed']);
        $this->assertTrue($second['complete']);
        $this->assertCount(1, $second['created_track_ids']);
        $complete = $this->evidence();
        $third = $importer->apply($source, $this->release, $review, $review['review_sha256'], 2, $actor);
        $this->assertSame([], $third['created_track_ids']);
        $this->assertSame($complete, $this->evidence());
        $this->assertExactlyOneRowSetPerRecord(3);
        // A fresh review of the completed source records exact skips; applying it adds its own batch evidence only.
        $next = $importer->review($source, $this->release, $actor);
        $this->assertSame(['create_draft' => 0, 'skip' => 3, 'conflict' => 0], $next['counts']);
        $applied = $importer->apply($source, $this->release, $next, $next['review_sha256'], 25, $actor);
        $this->assertSame([], $applied['created_track_ids']);
        $this->assertTrue($applied['complete']);
        $this->assertDatabaseCount('catalog_import_batches', 2);
        $this->assertDatabaseCount('audit_events', 8);
        $this->assertSame(CatalogImportBatch::query()->min('id'), CatalogImportMapping::query()->distinct()->pluck('batch_id')->sole());
        $this->assertSame($complete[0]['tracks'], $this->evidence()[0]['tracks']);
        $this->assertSame($complete[2], $this->evidence()[2]);
    }

    public function test_nested_importers_and_foreign_sessions_cannot_interleave_while_a_segment_is_open(): void
    {
        $actor = LicenseFixtures::admin();
        $source = $this->source(NormalizedCatalogFixtures::snapshot(1));
        $importer = new CatalogDraftImporter;
        $schema = (new CatalogDatabaseEvidence)->schema();
        $review = $importer->review($source, $this->release, $actor);
        $outcomes = [];
        $result = $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor, function () use (&$outcomes, $actor, $source): void {
            // After the segment's writes and before its final proofs, another importer in this process fails
            // standalone admission (the transaction is open) before it reads or writes anything.
            try {
                (new CatalogDraftImporter)->review($source, $this->release, $actor);
                $outcomes['nested'] = 'admitted';
            } catch (ValidationException) {
                $outcomes['nested'] = 'refused';
            }
            if (! $this->mysql()) {
                return;
            }
            // On MySQL a second session also tries to change the owned schema and the evidence tables; each
            // attempt waits on the open transaction's metadata and next-key locks and times out (1205).
            $foreign = $this->foreignSession();
            foreach (['trigger' => 'DROP TRIGGER catalog_import_mappings_immutable_delete',
                'column' => 'ALTER TABLE catalog_import_mappings ADD COLUMN unreviewed TEXT',
                'track' => "INSERT INTO tracks (title, slug, artist, created_at, updated_at) VALUES ('SYNTHETIC foreign', 'synthetic-foreign', 'SYNTHETIC', '2026-10-10 00:00:00', '2026-10-10 00:00:00')",
                'actor' => 'UPDATE users SET is_admin = 0 WHERE id = '.(int) $actor->id] as $label => $sql) {
                try {
                    $foreign->exec($sql);
                    $outcomes[$label] = 'admitted';
                } catch (PDOException $error) {
                    $outcomes[$label] = (int) ($error->errorInfo[1] ?? 0);
                }
            }
            unset($foreign);
        });
        $this->assertSame($this->mysql() ? ['nested' => 'refused', 'trigger' => 1205, 'column' => 1205, 'track' => 1205, 'actor' => 1205]
            : ['nested' => 'refused'], $outcomes);
        $this->assertTrue($result['complete']);
        $this->assertSame($schema, (new CatalogDatabaseEvidence)->schema());
        $this->assertExactlyOneRowSetPerRecord(1);
        $this->assertSame(1, (new CatalogDatabaseEvidence)->rows('users')[$actor->id]['attributes']['is_admin']);
    }

    private function mysql(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    private function identifier(string $name): string
    {
        return $this->mysql() ? '`'.$name.'`' : '"'.$name.'"';
    }

    /** A second connection with one-second lock waits, so a blocked attempt reports 1205 instead of stalling the segment. */
    private function foreignSession(): PDO
    {
        $config = DB::connection()->getConfig();
        $address = ($config['unix_socket'] ?? '') !== '' ? 'unix_socket='.$config['unix_socket'] : 'host='.$config['host'].';port='.$config['port'];
        $pdo = new PDO('mysql:'.$address.';dbname='.$config['database'], $config['username'], $config['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION lock_wait_timeout = 1, innodb_lock_wait_timeout = 1');

        return $pdo;
    }

    private function assertSchemaRefused(): void
    {
        try {
            (new CatalogDatabaseEvidence)->schema();
            $this->fail('Drifted owned schema admitted.');
        } catch (RuntimeException $error) {
            $this->assertSame('catalog_target_schema_invalid', $error->getMessage());
        }
    }

    private function restoreOwnedDeleteTrigger(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TRIGGER IF EXISTS catalog_import_mappings_immutable_delete');
        $pdo->exec("CREATE TRIGGER catalog_import_mappings_immutable_delete BEFORE DELETE ON catalog_import_mappings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catalog import evidence is immutable'");
    }

    /**
     * MySQL DDL inside the importer's own session commits implicitly, so a callback's schema change commits the
     * segment written before it. The drift is still refused; once the owned trigger is restored, the committed
     * rows form the exact retained prefix and a replay creates nothing. Another session's DDL waits instead.
     */
    private function assertCommittedPrefixAfterCallbackDdl(CatalogDraftImporter $importer, array $source, array $review, $actor, string $schema): void
    {
        $this->assertSchemaRefused();
        $this->restoreOwnedDeleteTrigger();
        $this->assertSame($schema, (new CatalogDatabaseEvidence)->schema());
        $this->assertExactlyOneRowSetPerRecord(1);
        $committed = $this->evidence();
        $replay = $importer->apply($source, $this->release, $review, $review['review_sha256'], 1, $actor);
        $this->assertSame([], $replay['created_track_ids']);
        $this->assertTrue($replay['complete']);
        $this->assertSame($committed, $this->evidence());
    }

    private function assertExactlyOneRowSetPerRecord(int $records): void
    {
        $this->assertDatabaseCount('tracks', $records);
        $this->assertDatabaseCount('catalog_import_batches', 1);
        $this->assertDatabaseCount('catalog_import_mappings', $records);
        $this->assertDatabaseCount('audit_events', 2 * $records + 1);
        foreach (['catalog.track.created' => $records, 'migration.catalog_batch.accepted' => 1, 'migration.catalog_draft.mapped' => $records] as $action => $count) {
            $this->assertSame($count, AuditEvent::query()->where('action', $action)->count());
        }
        $this->assertSame($records, CatalogImportMapping::query()->distinct()->count('track_id'));
    }

    private function changeSchema(string $case): void
    {
        $pdo = DB::connection()->getPdo();
        if ($this->mysql()) {
            $this->changeMysqlSchema($pdo, $case);

            return;
        }
        switch ($case) {
            case 'missing-trigger': $pdo->exec('DROP TRIGGER catalog_import_mappings_immutable_delete');
                break;
            case 'foreign-trigger':
                $pdo->exec('DROP TRIGGER catalog_import_mappings_immutable_delete');
                $pdo->exec('CREATE TRIGGER catalog_import_mappings_immutable_delete BEFORE DELETE ON catalog_import_mappings BEGIN SELECT 1; END');
                break;
            case 'missing-unique': $pdo->exec('DROP INDEX catalog_import_mappings_record_key_unique');
                break;
            case 'extra-column': $pdo->exec('ALTER TABLE catalog_import_mappings ADD COLUMN unreviewed TEXT');
                break;
            case 'foreign-keys-disabled': $pdo->exec('PRAGMA foreign_keys=OFF');
                break;
            case 'writable-schema': $pdo->exec('PRAGMA writable_schema=ON');
                break;
            case 'ignored-checks': $pdo->exec('PRAGMA ignore_check_constraints=ON');
                break;
            case 'foreign-key-cascade':
            case 'nullable-column':
                $rows = $pdo->query("SELECT type, sql FROM sqlite_master WHERE tbl_name='catalog_import_mappings' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END")->fetchAll(PDO::FETCH_ASSOC);
                $pdo->exec('DROP TABLE catalog_import_mappings');
                foreach ($rows as $row) {
                    $sql = $row['sql'];
                    if ($row['type'] === 'table') {
                        $sql = $case === 'foreign-key-cascade' ? str_replace('on delete restrict', 'on delete cascade', $sql)
                            : str_replace('"actor_id" integer not null', '"actor_id" integer', $sql);
                    }
                    $pdo->exec($sql);
                }
                break;
        }
    }

    /** The InnoDB counterpart of each SQLite drift: owned objects, then the session switches the importer requires. */
    private function changeMysqlSchema(PDO $pdo, string $case): void
    {
        $statements = match ($case) {
            'missing-trigger' => ['DROP TRIGGER catalog_import_mappings_immutable_delete'],
            'foreign-trigger' => ['DROP TRIGGER catalog_import_mappings_immutable_delete',
                'CREATE TRIGGER catalog_import_mappings_immutable_delete BEFORE DELETE ON catalog_import_mappings FOR EACH ROW SET @catalog_import_noop = 1'],
            'missing-unique' => ['ALTER TABLE catalog_import_mappings DROP INDEX catalog_import_mappings_record_key_unique'],
            'extra-column' => ['ALTER TABLE catalog_import_mappings ADD COLUMN unreviewed TEXT'],
            'foreign-keys-disabled' => ['SET SESSION foreign_key_checks = 0'],
            'writable-schema' => ['SET SESSION unique_checks = 0'],
            'ignored-checks' => ["SET SESSION sql_mode = ''"],
            'foreign-key-cascade' => ['ALTER TABLE catalog_import_mappings DROP FOREIGN KEY catalog_import_mappings_actor_id_foreign',
                'ALTER TABLE catalog_import_mappings ADD CONSTRAINT catalog_import_mappings_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE CASCADE'],
            'nullable-column' => ['ALTER TABLE catalog_import_mappings MODIFY actor_id BIGINT UNSIGNED NULL'],
        };
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
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
