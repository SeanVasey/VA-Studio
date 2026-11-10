<?php

namespace Tests\Feature\MediaLifecycle;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Lifecycle\MediaReferenceJson;
use App\Domain\Media\Lifecycle\MediaReferenceProof;
use App\Domain\Media\Lifecycle\MediaReferenceRegistry;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Jobs\ProcessMedia;
use App\Jobs\ProcessSiteImage;
use App\Jobs\ProcessSoundKit;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/**
 * FP-034 delete prerequisites P2-2 (track-level references), P2-3 (id-only references in plaintext JSON) and P2-4 (a
 * schema-reviewed registry instead of column-name detection). Rows are synthetic and written raw: these tests exercise the
 * reader, so the producers' insert guards are dropped for the fixture rows only.
 */
final class MediaReferenceRegistryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->travelTo(now()->startOfSecond());
        $this->actor = LicenseFixtures::admin();
    }

    /** One processed master on its own draft track: [family root, track id, derived assets by role, run id]. */
    private function family(string $slug, int $frequency): array
    {
        $track = Track::create(['title' => 'SYNTHETIC '.$slug, 'slug' => $slug]);
        $source = MediaFixtures::source($track, 'master_wav', MediaFixtures::wav(1.2, $frequency));
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        $this->assertSame('completed', app(MediaProcessor::class)->handle($run->id)->status);

        return [(int) $source->id, (int) $track->id, MediaAsset::where('parent_asset_id', $source->id)->get()->keyBy('role')->all(), (int) $run->id];
    }

    private function prove(array $roots, array $extra = []): array
    {
        return (new MediaReferenceProof(new MediaReferenceRegistry($extra)))->prove($roots);
    }

    /** A synthetic row written past foreign keys and the producers' triggers; unnamed required columns get filler. */
    private function raw(string $table, array $row): int
    {
        foreach ($this->triggers($table) as $trigger) {
            DB::statement('DROP TRIGGER '.DB::getQueryGrammar()->wrapTable($trigger));
        }
        foreach (Schema::getColumns($table) as $column) {
            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment'] || array_key_exists($column['name'], $row)) {
                continue;
            }
            $type = strtolower($column['type_name']);
            $length = preg_match('/\((\d+)\)/', (string) $column['type'], $match) === 1 ? (int) $match[1] : 255;
            $value = match (true) {
                str_ends_with($column['name'], '_at') => now()->format('Y-m-d H:i:s'),
                str_contains($type, 'int') => 1,
                str_ends_with($column['name'], 'public_id') || $column['name'] === 'uuid' => (string) Str::uuid(),
                str_contains($column['name'], 'hash') || str_contains($column['name'], 'sha256') => hash('sha256', $table.$column['name'].Str::uuid()),
                $type === 'json' => '{}',
                $column['name'] === 'currency' => 'USD',
                default => 'synthetic-'.Str::random(8),
            };
            $row[$column['name']] = is_string($value) && $type !== 'json' ? substr($value, 0, $length) : $value;
        }
        Schema::disableForeignKeyConstraints();
        try {
            return (int) DB::table($table)->insertGetId($row);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** @return list<string> */
    private function triggers(string $table): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->pluck('name')->all()
            : array_column(DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$table]), 'name');
    }

    private function attached(array $result, int $root): array
    {
        return array_keys(array_filter($result['families'][$root]['checks']));
    }

    public function test_every_candidate_column_of_the_live_schema_is_registered_or_reviewed(): void
    {
        $registry = new MediaReferenceRegistry;
        $classified = $registry->classified();
        $tables = [];
        $candidates = [];
        foreach (Schema::getTables(Schema::getCurrentSchemaListing()) as $table) {
            $name = $table['name'];
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }
            foreach (Schema::getColumns($name) as $column) {
                $tables[$name][$column['name']] = strtolower($column['type_name']);
            }
            // Derived here independently of the registry's own candidate rule.
            foreach (Schema::getForeignKeys($name) as $key) {
                if (in_array($key['foreign_table'], ['tracks', 'media_assets', 'media_processing_runs', 'media_upload_sessions'], true)) {
                    foreach ($key['columns'] as $column) {
                        $candidates[$name.'.'.$column] = 'foreign key to '.$key['foreign_table'];
                    }
                }
            }
            foreach ($tables[$name] as $column => $type) {
                if (preg_match('/asset|track_id|track_source|manifest|recording|artwork|preview/', $column) === 1 && ! str_starts_with($column, 'production_track_')) {
                    $candidates[$name.'.'.$column] ??= 'media-like name';
                }
                if (in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'json'], true)) {
                    $candidates[$name.'.'.$column] ??= 'long text or JSON';
                }
                if (str_ends_with($column, '_ciphertext')) {
                    $this->assertContains($name.'.'.$column, MediaReferenceRegistry::OPAQUE, 'An encrypted column must be registered opaque.');
                }
            }
        }
        $this->assertGreaterThan(200, count($candidates));
        $missing = array_keys(array_diff_key($candidates, $classified));
        $this->assertSame([], $missing, 'Classify these columns in MediaReferenceRegistry (a reference list, OPAQUE, or REVIEWED with a reason): '
            .implode(', ', array_map(fn (string $column): string => $column.' ('.$candidates[$column].')', $missing)));
        $this->assertSame([], app(MediaReferenceProof::class)->unregistered());

        // Foreign keys land in a reference list or are reviewed, never in the opaque or digest lists.
        foreach ($candidates as $column => $why) {
            if (str_starts_with($why, 'foreign key')) {
                $this->assertNotSame([], array_intersect($classified[$column], ['media_ids', 'track_ids', 'reviewed']), $column);
            }
        }
        // No stale or doubly classified entry: every registered column exists, in exactly one list.
        foreach ($classified as $column => $lists) {
            $this->assertCount(1, $lists, $column.' is classified more than once.');
            [$table, $name] = explode('.', $column, 2);
            $this->assertTrue(isset($tables[$table][$name]) || isset($tables[$table][$name.'_type'], $tables[$table][$name.'_id']), $column.' is registered but not in the schema.');
        }
        foreach (MediaReferenceRegistry::REVIEWED as $column => $reason) {
            $this->assertGreaterThan(8, strlen($reason), $column);
        }
        foreach (array_keys(MediaReferenceRegistry::JOBS) as $class) {
            $this->assertTrue(class_exists($class), $class);
        }
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $this->assertArrayHasKey('App\\Jobs\\'.basename($file, '.php'), MediaReferenceRegistry::JOBS, 'Review this job\'s arguments in MediaReferenceRegistry::JOBS.');
        }
        // Differently named digests and the track-level columns named by the review are registered explicitly.
        foreach (['production_free_definitions.source_manifest_hash', 'production_free_definitions.asset_manifest_hash',
            'production_member_origins.artifact_manifest_hash'] as $digest) {
            $this->assertContains($digest, MediaReferenceRegistry::MANIFEST_DIGESTS);
        }
        foreach (['catalog_import_mappings.track_id', 'product_draft_members.track_id', 'production_checkout_order_lines.track_id', 'offer_revisions.track_id'] as $column) {
            $this->assertContains($column, MediaReferenceRegistry::TRACK_IDS);
        }
        $this->assertSame(['legacy_history_lines.track_source_id'], MediaReferenceRegistry::TRACK_SOURCE_IDS);
    }

    public function test_an_unregistered_reference_column_fails_the_registry_and_blocks_the_proof_until_it_is_classified(): void
    {
        [$master, , $outputs] = $this->family('synthetic-registry', 440);
        $this->assertSame(['complete', 'unattached'], [$this->prove([$master])['coverage'], $this->prove([$master])['families'][$master]['state']]);
        Schema::create('fp034_probe', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('song_id')->nullable()->constrained('tracks');
            $table->foreignId('cover_id')->nullable()->constrained('media_assets');
            $table->unsignedBigInteger('bonus_asset_id')->nullable();
            $table->char('source_manifest_hash', 64)->nullable();
            $table->text('notes')->nullable();
            $table->json('extra')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('label')->nullable();
        });
        $expected = ['fp034_probe.bonus_asset_id', 'fp034_probe.cover_id', 'fp034_probe.extra', 'fp034_probe.notes', 'fp034_probe.song_id',
            'fp034_probe.source_manifest_hash', 'fp034_probe.subject'];

        // What the schema test asserts empty: the new columns are reported, and the plain label is not.
        $this->assertSame($expected, (new MediaReferenceProof)->unregistered());
        $result = $this->prove([$master]);
        $this->assertSame(['incomplete', array_map(fn (string $column): string => 'unregistered:'.$column, $expected)], [$result['coverage'], $result['blockers']]);
        $this->assertSame('unknown', $result['families'][$master]['state']);

        $extra = ['track_ids' => ['fp034_probe.song_id' => 'tracks'],
            'media_ids' => ['fp034_probe.cover_id' => 'media_assets', 'fp034_probe.bonus_asset_id' => 'media_assets'],
            'manifest_digests' => ['fp034_probe.source_manifest_hash' => 'digest'], 'opaque' => ['fp034_probe.notes' => 'opaque'],
            'json_deep' => ['fp034_probe.extra' => 'ids'], 'polymorphic' => ['fp034_probe.subject' => 'poly']];
        $this->assertSame(['complete', 'unattached'], [$this->prove([$master], $extra)['coverage'], $this->prove([$master], $extra)['families'][$master]['state']]);

        // A digest that is not named *asset_manifest_hash, and an opaque payload, each make coverage incomplete.
        DB::table('fp034_probe')->insert(['source_manifest_hash' => str_repeat('d', 64)]);
        $this->assertSame(['hash_only_manifest:fp034_probe.source_manifest_hash'], $this->prove([$master], $extra)['blockers']);
        DB::table('fp034_probe')->delete();
        DB::table('fp034_probe')->insert(['notes' => 'synthetic unreviewed payload']);
        $result = $this->prove([$master], $extra);
        $this->assertSame([['opaque:fp034_probe.notes'], 'unknown'], [$result['blockers'], $result['families'][$master]['state']]);
        DB::table('fp034_probe')->delete();
        DB::table('fp034_probe')->insert(['notes' => '']);
        $this->assertSame([], $this->prove([$master], $extra)['blockers']);
        DB::table('fp034_probe')->delete();

        // Each registered class counts a reference by id.
        foreach (['fk:fp034_probe.cover_id' => ['cover_id' => $outputs['download_mp3']->id], 'fk:fp034_probe.bonus_asset_id' => ['bonus_asset_id' => $master],
            'poly:fp034_probe.subject' => ['subject_type' => MediaAsset::class, 'subject_id' => $outputs['master_wav']->id],
            'json:fp034_probe.extra' => ['extra' => json_encode(['bonus' => ['cover_asset_id' => $outputs['preview_tagged']->id]])]] as $check => $row) {
            DB::table('fp034_probe')->insert($row);
            $result = $this->prove([$master], $extra);
            $this->assertSame(['complete', 'attached', [$check]], [$result['coverage'], $result['families'][$master]['state'], $this->attached($result, $master)], $check);
            DB::table('fp034_probe')->delete();
        }
    }

    public function test_track_level_references_keep_every_family_of_the_track_attached_and_legacy_track_ids_make_coverage_incomplete(): void
    {
        $families = [];
        foreach (['catalog_import_mappings', 'product_draft_members', 'production_checkout_order_lines',
            'production_track_preparation_packet_lines', 'free_definitions', 'offer_revisions', 'offers', 'rights_declarations', 'none'] as $index => $table) {
            $families[$table] = $this->family('synthetic-track-'.$index, 300 + 40 * $index);
        }
        $roots = array_column($families, 0);
        foreach ($roots as $root) {
            $this->assertSame('unattached', $this->prove($roots)['families'][$root]['state']);
        }
        $write = function (array $tables) use ($families): void {
            foreach ($tables as $table) {
                $this->raw($table, ['track_id' => $families[$table][1]] + match ($table) {
                    'offers' => ['deliverable_asset_ids' => '[]', 'is_active' => false],
                    'offer_revisions' => ['snapshot' => json_encode(['schema_version' => 1])],
                    default => [],
                });
            }
        };
        $write(['product_draft_members', 'production_track_preparation_packet_lines', 'offer_revisions', 'offers', 'rights_declarations']);

        $result = $this->prove($roots);

        $this->assertSame(['complete', []], [$result['coverage'], $result['blockers']]);
        foreach (['product_draft_members', 'production_track_preparation_packet_lines', 'offer_revisions'] as $table) {
            [$root] = $families[$table];
            $this->assertSame(['attached', ['track:'.$table.'.track_id']], [$result['families'][$root]['state'], $this->attached($result, $root)], $table);
        }
        // Mutable track set-up (an offer without deliverables, a rights declaration) is reviewed as not a reference.
        foreach (['offers', 'rights_declarations', 'none', 'catalog_import_mappings', 'production_checkout_order_lines', 'free_definitions'] as $table) {
            $this->assertSame('unattached', $result['families'][$families[$table][0]]['state'], $table);
        }

        // A legacy line names a track by an id from another system. Nothing links it to a store track, so nothing is proven.
        $this->raw('legacy_history_lines', ['track_source_id' => 'TK-SYNTHETIC-1', 'exclusive' => false, 'record_ciphertext' => '']);
        $result = $this->prove($roots);
        $this->assertSame(['incomplete', ['unresolved_track_source:legacy_history_lines.track_source_id']], [$result['coverage'], $result['blockers']]);
        $this->assertSame('unknown', $result['families'][$families['none'][0]]['state']);
        $this->assertSame('attached', $result['families'][$families['offer_revisions'][0]]['state']);

        // Import mappings, production order lines and free definitions also carry payloads the proof cannot read (P2-1 is
        // open), so coverage is incomplete; their track-level reference still keeps the family attached, not merely unknown.
        $write(['catalog_import_mappings', 'production_checkout_order_lines', 'free_definitions']);
        $result = $this->prove($roots);
        $this->assertSame(['incomplete', ['opaque:catalog_import_mappings.evidence_ciphertext', 'opaque:free_definitions.payload',
            'opaque:production_checkout_order_lines.payload_ciphertext', 'unresolved_track_source:legacy_history_lines.track_source_id']],
            [$result['coverage'], $result['blockers']]);
        foreach (MediaReferenceRegistry::TRACK_IDS as $column) {
            [$root] = $families[explode('.', $column)[0]];
            $this->assertSame(['attached', ['track:'.$column]], [$result['families'][$root]['state'], $this->attached($result, $root)], $column);
        }
        foreach (['offers', 'rights_declarations', 'none'] as $table) {
            $this->assertSame('unknown', $result['families'][$families[$table][0]]['state'], $table);
        }
        // The cheap structural pass (the staff page) sees the same track-level references.
        $structural = app(MediaReferenceProof::class)->structural($roots);
        $this->assertSame(1, $structural[$families['product_draft_members'][0]]['checks']['track:product_draft_members.track_id']);
        $this->assertSame(0, array_sum($structural[$families['none'][0]]['checks']));

        // The report prints unknown, never unattached, while coverage is incomplete.
        $this->travel(31)->days();
        $this->assertSame(0, Artisan::call('vasey:media-unattached-report', ['--older-than-days' => 30, '--proof' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Coverage: incomplete; blockers: ', $output);
        $this->assertStringContainsString('unresolved_track_source:legacy_history_lines.track_source_id', $output);
        $this->assertMatchesRegularExpression('/^#'.$families['none'][0].' unknown /m', $output);
        $this->assertStringContainsString('Unattached: 0.', $output);
        $this->assertDoesNotMatchRegularExpression('/^#\d+ unattached /m', $output);
    }

    public function test_id_only_references_in_plaintext_json_keep_a_family_attached(): void
    {
        $families = [];
        foreach (['quotes.snapshot', 'quote_pricings.snapshot', 'inventory_reservations.snapshot', 'exclusive_activations.snapshot',
            'audit_events.context', 'site_releases.content', 'jobs.payload', 'failed_jobs.payload', 'product_draft_versions.manifest', 'none'] as $index => $column) {
            $families[$column] = $this->family('synthetic-json-'.$index, 300 + 40 * $index);
        }
        $roots = array_column($families, 0);
        $this->assertCount(MediaReferenceProof::MAX_PROOF_FAMILIES, $roots);
        $id = fn (string $column, string $role): int => (int) $families[$column][2][$role]->id;
        $job = fn (object $command): string => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => $command::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => $command::class, 'command' => serialize($command)]]);
        // Ids only: no hash and no path, so the content check cannot see any of these.
        $this->raw('quotes', ['request' => '[]', 'snapshot' => json_encode(['schema_version' => 1, 'lines' => [['offer_revision_id' => 999,
            'offer_snapshot' => ['product' => ['id' => 999999], 'assets' => [['id' => $id('quotes.snapshot', 'master_wav'), 'role' => 'master_wav']]]]]])]);
        $this->raw('quote_pricings', ['snapshot' => json_encode(['lines' => [['offer_snapshot' => ['preview' => ['id' => $id('quote_pricings.snapshot', 'preview_tagged')]]]]])]);
        // The track of a sold line (`product.id`), with no asset named at all.
        $this->raw('inventory_reservations', ['state' => 'reserved', 'snapshot' => json_encode(['lines' => [['offer_snapshot' => ['product' => ['id' => $families['inventory_reservations.snapshot'][1], 'slug' => 'synthetic']]]]])]);
        $this->raw('exclusive_activations', ['snapshot' => json_encode(['schema_version' => 1, 'offer_revision_id' => 999, 'inventory' => ['scope_id' => 1,
            'deliverable_asset_ids' => [(string) $id('exclusive_activations.snapshot', 'download_mp3')]]])]);
        // An audit event about something else that names the asset, and one about the family's own row (lineage, not a reference).
        DB::table('audit_events')->insert(['action' => 'synthetic.reference', 'subject_type' => User::class, 'subject_id' => $this->actor->id,
            'context' => json_encode(['media_asset_id' => $id('audit_events.context', 'master_wav')]), 'created_at' => now()]);
        DB::table('audit_events')->insert(['action' => 'synthetic.lineage', 'subject_type' => MediaAsset::class, 'subject_id' => $families['none'][0],
            'context' => json_encode(['asset_ids' => [$id('none', 'master_wav')], 'track_id' => $families['none'][1]]), 'created_at' => now()]);
        $this->raw('site_releases', ['content' => json_encode(['news' => ['entries' => [['slug' => 'synthetic', 'related_track_ids' => [$families['site_releases.content'][1]]]]]])]);
        DB::table('jobs')->insert(['queue' => 'media', 'payload' => $job(new ProcessMedia($families['jobs.payload'][3])), 'attempts' => 0,
            'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'media',
            'payload' => $job(new ProcessMedia($families['failed_jobs.payload'][3])), 'exception' => 'Synthetic failure', 'failed_at' => now()]);
        $this->raw('product_draft_versions', ['manifest' => json_encode(['members' => [['track_id' => $families['product_draft_versions.manifest'][1], 'title' => 'Synthetic']]])]);
        // Reviewed shapes that name nothing: a schema 1 outbox payload and a reviewed job without media arguments.
        $this->raw('fulfillment_outbox', ['state' => 'pending', 'license_grant_id' => null,
            'payload' => json_encode(['schema_version' => 1, 'finalization_id' => (string) Str::uuid(), 'grant_id' => null, 'evidence_hash' => str_repeat('e', 64)])]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => $job(new ProcessSiteImage(1)), 'attempts' => 0,
            'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);

        $result = $this->prove($roots);

        $this->assertSame(['complete', []], [$result['coverage'], $result['blockers']]);
        foreach ($families as $column => [$root]) {
            $this->assertSame($column === 'none' ? ['unattached', []] : ['attached', ['json:'.$column]],
                [$result['families'][$root]['state'], $this->attached($result, $root)], $column);
        }
        // These checks belong to the explicit proof only: the cheap structural pass (every page render) does not read them.
        $this->assertSame(0, array_sum(app(MediaReferenceProof::class)->structural($roots)[$families['quotes.snapshot'][0]]['checks']));
    }

    public function test_plaintext_json_the_proof_cannot_parse_makes_coverage_incomplete(): void
    {
        [$master] = $this->family('synthetic-unparsed', 440);
        $this->assertSame('unattached', $this->prove([$master])['families'][$master]['state']);
        $jobs = fn (string $payload): bool => DB::table('jobs')->insert(['queue' => 'default', 'payload' => $payload, 'attempts' => 0,
            'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        $command = serialize(new ProcessMedia(1));
        $cases = [
            // A job class nobody reviewed (a queued closure, mail or notification), an encrypted command, and a lost argument.
            'unparsed:jobs.payload' => [
                fn () => $jobs(json_encode(['displayName' => 'Closure', 'data' => ['commandName' => 'Illuminate\\Queue\\CallQueuedClosure', 'command' => 'O:34:"Illuminate\\Queue\\CallQueuedClosure":0:{}']])),
                fn () => $jobs(json_encode(['displayName' => ProcessMedia::class, 'data' => ['commandName' => ProcessMedia::class, 'command' => base64_encode($command)]])),
                fn () => $jobs(json_encode(['displayName' => ProcessMedia::class, 'data' => ['commandName' => ProcessMedia::class, 'command' => str_replace('runId', 'ranId', $command)]])),
                fn () => $jobs('not json'),
            ],
            // A newer outbox schema, or an extra key, is not the reviewed shape. (MySQL JSON columns refuse invalid JSON, so
            // the unreadable values below are valid JSON of the wrong shape.)
            'unparsed:fulfillment_outbox.payload' => [
                fn () => $this->raw('fulfillment_outbox', ['state' => 'pending', 'license_grant_id' => null, 'payload' => json_encode(['schema_version' => 2, 'finalization_id' => 'x', 'grant_id' => null, 'evidence_hash' => 'y'])]),
                fn () => $this->raw('fulfillment_outbox', ['state' => 'pending', 'license_grant_id' => null, 'payload' => json_encode(['schema_version' => 1, 'finalization_id' => 'x', 'grant_id' => null, 'evidence_hash' => 'y', 'assets' => [1]])]),
            ],
            // The structural id lists are held to the same rule.
            'unparsed:offers.deliverable_asset_ids' => [fn () => $this->raw('offers', ['deliverable_asset_ids' => '"7"', 'is_active' => false])],
            'unparsed:quotes.snapshot' => [fn () => $this->raw('quotes', ['request' => '[]', 'snapshot' => '"asset 12"'])],
            'unparsed:audit_events.context' => [fn () => DB::table('audit_events')->insert(['action' => 'synthetic.truncated', 'subject_type' => User::class,
                'subject_id' => $this->actor->id, 'context' => '"media_asset_id 7"', 'created_at' => now()])],
        ];
        foreach ($cases as $blocker => $writers) {
            [$table] = explode('.', substr($blocker, 9));
            foreach ($writers as $index => $write) {
                $write();
                $result = $this->prove([$master]);
                $this->assertSame([['incomplete', [$blocker]], 'unknown'], [[$result['coverage'], $result['blockers']], $result['families'][$master]['state']], $blocker.' #'.$index);
                foreach ($this->triggers($table) as $trigger) {
                    DB::statement('DROP TRIGGER '.DB::getQueryGrammar()->wrapTable($trigger));
                }
                $table === 'audit_events' ? DB::table($table)->where('action', 'synthetic.truncated')->delete() : DB::table($table)->delete();
                $this->assertSame('unattached', $this->prove([$master])['families'][$master]['state'], $blocker.' #'.$index);
            }
        }

        // More candidate rows than one proof reads is incomplete coverage, not a silent truncation.
        foreach (array_chunk(range(1, MediaReferenceProof::MAX_JSON_ROWS + 1), 500) as $chunk) {
            DB::table('audit_events')->insert(array_map(fn (int $i): array => ['action' => 'synthetic.bulk', 'subject_type' => User::class,
                'subject_id' => $this->actor->id, 'context' => '{"asset_id":999999999}', 'created_at' => now()], $chunk));
        }
        $result = $this->prove([$master]);
        $this->assertSame([['json_bound:audit_events.context'], 'unknown'], [$result['blockers'], $result['families'][$master]['state']]);
    }

    public function test_the_json_vocabulary_reads_assets_runs_and_tracks_and_nothing_else(): void
    {
        $read = fn (string $parser, mixed $value): ?array => MediaReferenceJson::read($parser, $value, MediaReferenceRegistry::JOBS);
        $none = ['media_assets' => [], 'media_processing_runs' => [], 'tracks' => []];

        $this->assertSame(['media_assets' => [3, 4, 5, 6, 7, 8], 'media_processing_runs' => [9], 'tracks' => [10, 11, 12, 13, 14]], $read('ids', json_encode([
            'Assets' => [['id' => 3, 'processing_run_id' => 9, 'recording_binding' => ['id' => 77, 'master_asset_id' => '4']]], 'preview' => ['id' => 5],
            'files' => [6], 'deliverable_asset_ids' => [7], 'asset' => 8, 'rights' => ['id' => 88], 'price_minor' => 100, 'license' => ['id' => 99],
            'product' => ['id' => 10, 'bpm' => 120], 'track_id' => 11, 'related_track_ids' => ['12'], 'tracks' => [['id' => 13]], 'track' => 14])));
        $this->assertSame(['media_assets' => [3], 'media_processing_runs' => [], 'tracks' => []], $read('assets', ['asset_id' => 3, 'track_id' => 11]));
        $this->assertSame(['media_assets' => [3, 4], 'media_processing_runs' => [], 'tracks' => []], $read('asset_list', '[3,"4",0,-1,"x"]'));
        $this->assertSame($none, $read('ids', ['id' => 3, 'offer_revision_id' => 4, 'track_title' => 'Synthetic', 'soundtrack' => 5]));
        foreach (['"3"', 'null', '', '{"asset_id":', 3] as $unreadable) {
            $this->assertNull($read('ids', $unreadable));
        }
        $this->assertSame(['media_assets' => [], 'media_processing_runs' => [41], 'tracks' => []],
            $read('job', ['data' => ['commandName' => ProcessMedia::class, 'command' => serialize(new ProcessMedia(41))]]));
        $this->assertSame($none, $read('job', ['data' => ['commandName' => ProcessSoundKit::class, 'command' => serialize(new ProcessSoundKit(41))]]));
        $this->assertNull($read('job', ['data' => ['commandName' => 'App\\Jobs\\Unreviewed', 'command' => 'O:19:"App\\Jobs\\Unreviewed":0:{}']]));
        $this->assertNull($read('job', ['data' => ['commandName' => ProcessSoundKit::class, 'command' => 'ciphertext']]));
    }
}
