<?php

namespace Tests\Feature;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyDraft;
use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
use App\Domain\Commerce\Policy\PrepareProductionTrackPolicy;
use App\Domain\Commerce\Policy\ReviewProductionTrackPolicy;
use App\Domain\Commerce\Policy\SaveProductionTrackPolicy;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

class ProductionTrackPolicyDraftTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
    }

    private function snapshot(): array
    {
        $tables = ['production_track_policy_drafts', 'production_track_policy_versions', 'production_track_policy_source_reviews', 'audit_events', 'users'];

        return array_combine($tables, array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), $tables));
    }

    public function test_unresolved_and_declared_drafts_are_encrypted_and_never_enable_commerce(): void
    {
        foreach ([true, false] as $unresolved) {
            $source = ProductionTrackPolicyFixtures::authored($unresolved);
            $draft = ProductionTrackPolicyFixtures::create(authored: $source);
            $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
            $this->assertSame(1, $draft->revision);
            $this->assertSame(CanonicalJson::encode($source), Crypt::decryptString($version->payload_ciphertext));
            $this->assertSame(hash('sha256', $version->payload_ciphertext), $version->payload_hash);
            $this->assertStringNotContainsString('NONBINDING', json_encode($this->snapshot()['production_track_policy_versions']));
            $this->assertArrayNotHasKey('active', $draft->getAttributes());
            $audit = AuditEvent::where('subject_id', $draft->id)->where('subject_type', ProductionTrackPolicyDraft::class)->sole();
            $this->assertFalse($audit->context['activation_allowed']);
            $this->assertFalse($audit->context['external_facts_verified']);
            $this->assertStringNotContainsString('synthetic:', json_encode($audit->getAttributes()));
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('checkout_intents', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('production_track_policy_source_reviews', 0);
        Http::assertNothingSent();
    }

    public function test_exact_noop_writes_no_version_timestamp_author_or_audit_and_changed_source_appends(): void
    {
        $author = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $source = ProductionTrackPolicyFixtures::authored();
        $draft = ProductionTrackPolicyFixtures::create($author, $source);
        $review = app(PrepareProductionTrackPolicy::class)->review($draft, $source, $editor);
        $before = $this->snapshot();
        $this->travel(2)->seconds();
        app(SaveProductionTrackPolicy::class)->applyReviewed($review, $editor);
        $this->assertSame($before, $this->snapshot());
        $source['declarations']['tax_calculation']['note'] = 'Still nonbinding; a revised authored declaration.';
        $review = app(PrepareProductionTrackPolicy::class)->review($draft, $source, $editor);
        $saved = app(SaveProductionTrackPolicy::class)->applyReviewed($review, $editor);
        $this->assertSame(2, $saved->revision);
        $this->assertSame($author->id, $saved->created_by);
        $this->assertSame($before['production_track_policy_versions'][0], $this->snapshot()['production_track_policy_versions'][0]);
        $this->assertDatabaseCount('production_track_policy_versions', 2);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_independent_review_acknowledges_exact_source_without_verifying_facts_or_activating(): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author, ProductionTrackPolicyFixtures::authored(true));
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $review = app(ReviewProductionTrackPolicy::class)->review($version, $reviewer);
        $ack = app(ReviewProductionTrackPolicy::class)->applyReviewed($review, $this->reference(), $reviewer);
        $payload = json_decode(Crypt::decryptString($ack->review_ciphertext), true, 8, JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['activation_allowed']);
        $this->assertFalse($payload['external_facts_verified']);
        $this->assertSame('authored_production_policy_source_acknowledgment', $payload['purpose']);
        $this->assertSame($version->payload_hash, $ack->version_evidence_hash);
        $this->assertSame($reviewer->id, $ack->reviewed_by);
        $this->assertStringNotContainsString('PRIVATE SYNTHETIC REVIEW', $ack->review_ciphertext);
        $this->assertStringNotContainsString('PRIVATE SYNTHETIC REVIEW', json_encode(DB::table('audit_events')->get()));
        $this->assertDatabaseCount('production_track_policy_versions', 1);
        $this->assertDatabaseCount('audit_events', 2);
    }

    private function reference(): array
    {
        return ['reference' => 'PRIVATE SYNTHETIC REVIEW', 'source_sha256' => hash('sha256', 'nonbinding review only'), 'authored_source_acknowledged' => true];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_malformed_declarations_cannot_create_partial_preparation(string $path, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $source = ProductionTrackPolicyFixtures::authored();
        Arr::set($source, $path, $value);
        $before = $this->snapshot();
        try {
            app(PrepareProductionTrackPolicy::class)->review(null, $source, $actor);
            $this->fail('Malformed policy was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(['policy'], array_keys($exception->errors()));
        }
    }

    public static function invalidDeclarations(): array
    {
        $field = 'declarations.tax_calculation.';

        return [
            'schema string' => ['schema_version', '1'], 'other purpose' => ['purpose', 'production_active_policy'],
            'unknown top field' => ['active', true], 'wrong version type' => ['version', 1], 'version bound' => ['version', str_repeat('v', 81)],
            'missing declaration' => ['declarations.privacy', null], 'extra declaration' => ['declarations.unknown', []],
            'declaration wrong type' => ['declarations.tax_calculation', 'declared'], 'unknown field' => [$field.'verified', true],
            'unknown state' => [$field.'state', 'approved'], 'empty choice' => [$field.'choice', ''], 'choice type' => [$field.'choice', true],
            'choice bound' => [$field.'choice', str_repeat('c', 513)], 'choice invalid UTF8' => [$field.'choice', "\xff"],
            'control character' => [$field.'choice', "synthetic\0value"], 'empty reference' => [$field.'source_reference', ''],
            'reference bound' => [$field.'source_reference', str_repeat('r', 513)], 'hash uppercase' => [$field.'source_sha256', str_repeat('A', 64)],
            'hash missing' => [$field.'source_sha256', null], 'hash length' => [$field.'source_sha256', str_repeat('a', 63)],
            'note empty' => [$field.'note', ' '], 'note bound' => [$field.'note', str_repeat('n', 1025)],
            'unresolved cannot retain declared choice' => [$field.'state', 'unresolved'],
            'secret-shaped source' => [$field.'note', 'Do not retain sk_live_SyntheticCredential'],
        ];
    }

    public function test_every_required_category_and_every_unresolved_null_field_is_explicit(): void
    {
        foreach (\App\Domain\Commerce\Policy\ProductionTrackPolicyDraft::CATEGORIES as $category) {
            $source = ProductionTrackPolicyFixtures::authored();
            unset($source['declarations'][$category]);
            try {
                \App\Domain\Commerce\Policy\ProductionTrackPolicyDraft::validateAuthored($source);
                $this->fail('Missing declaration was accepted.');
            } catch (ValidationException) {
                $this->assertCount(13, $source['declarations']);
            }
        }
        foreach (['choice', 'source_reference', 'source_sha256'] as $field) {
            $source = ProductionTrackPolicyFixtures::authored(true);
            unset($source['declarations']['privacy'][$field]);
            try {
                \App\Domain\Commerce\Policy\ProductionTrackPolicyDraft::validateAuthored($source);
                $this->fail('Missing unresolved field was accepted.');
            } catch (ValidationException) {
                $this->assertCount(4, $source['declarations']['privacy']);
            }
        }
    }

    #[DataProvider('tamperedReviews')]
    public function test_signed_capture_rejects_tampering_without_any_write(string $path, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($actor);
        $review = app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $actor);
        Arr::set($review, $path, $value);
        $before = $this->snapshot();
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
            $this->fail('Tampered review was accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function tamperedReviews(): array
    {
        return [['schema_version', '1'], ['intent', 'activate'], ['extra', true], ['signature', str_repeat('a', 64)],
            ['draft_id', '1'], ['draft_id', 999999], ['public_id', 'invalid'], ['baseline.revision', 999],
            ['after.version', 'changed'], ['before.version', 'changed'], ['after.active', true]];
    }

    public function test_stale_review_and_same_content_aba_cannot_overwrite_source(): void
    {
        $actor = LicenseFixtures::admin();
        $source = ProductionTrackPolicyFixtures::authored();
        $draft = ProductionTrackPolicyFixtures::create($actor, $source);
        $old = app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $actor);
        foreach ([ProductionTrackPolicyFixtures::authored(true), $source] as $next) {
            app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($draft, $next, $actor), $actor);
        }
        $before = $this->snapshot();
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($old, $actor);
            $this->fail('ABA review was accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_create_capture_is_consumed_and_actor_bound(): void
    {
        $actor = LicenseFixtures::admin();
        $other = LicenseFixtures::admin();
        $review = app(PrepareProductionTrackPolicy::class)->review(null, ProductionTrackPolicyFixtures::authored(), $actor);
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($review, $other);
            $this->fail('Foreign actor was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('production_track_policy_drafts', 0);
        }
        app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
        $before = $this->snapshot();
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
            $this->fail('Consumed creation was accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_original_author_and_every_editor_cannot_acknowledge_their_own_policy(): void
    {
        $author = LicenseFixtures::admin();
        $editor = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $editor), $editor);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->orderByDesc('number')->firstOrFail();
        foreach ([$author, $editor] as $actor) {
            try {
                app(ReviewProductionTrackPolicy::class)->review($version, $actor);
                $this->fail('Author was accepted as reviewer.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('production_track_policy_source_reviews', 0);
            }
        }
    }

    public function test_new_version_or_source_acknowledgment_invalidates_older_review(): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $review = app(ReviewProductionTrackPolicy::class)->review($version, $reviewer);
        app(ReviewProductionTrackPolicy::class)->applyReviewed($review, $this->reference(), $reviewer);
        $before = $this->snapshot();
        try {
            app(ReviewProductionTrackPolicy::class)->applyReviewed($review, $this->reference(), $reviewer);
            $this->fail('Consumed acknowledgment was accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        }
        app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $author), $author);
        try {
            app(ReviewProductionTrackPolicy::class)->review($version, $reviewer);
            $this->fail('Old version was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('production_track_policy_source_reviews', 1);
        }
    }

    #[DataProvider('authorityChanges')]
    public function test_fresh_authority_and_production_mfa_are_required_after_capture(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $review = app(PrepareProductionTrackPolicy::class)->review(null, ProductionTrackPolicyFixtures::authored(), $actor);
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        if ($field === 'app_authentication_secret') {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        }
        DB::table('users')->where('id', $actor->id)->update([$field => $value]);
        $before = $this->snapshot();
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
            $this->fail('Withdrawn authority was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->snapshot());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public static function authorityChanges(): array
    {
        return [['is_admin', false], ['email_verified_at', null], ['app_authentication_secret', null]];
    }

    public function test_late_audit_callback_actor_revocation_rolls_back_every_policy_and_audit_write(): void
    {
        $actor = LicenseFixtures::admin();
        $review = app(PrepareProductionTrackPolicy::class)->review(null, ProductionTrackPolicyFixtures::authored(), $actor);
        $before = $this->snapshot();
        AuditEvent::created(fn () => DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]));
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
            $this->fail('Late actor revocation was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->snapshot());
        } finally {
            AuditEvent::flushEventListeners();
            AuditEvent::clearBootedModels();
        }
    }

    public function test_late_audit_callback_tampering_is_detected_by_raw_final_proof(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($actor);
        $review = app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $actor);
        $before = $this->snapshot();
        AuditEvent::created(fn (AuditEvent $event) => DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered']));
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($review, $actor);
            $this->fail('Late audit tampering was accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        } finally {
            AuditEvent::flushEventListeners();
            AuditEvent::clearBootedModels();
        }
    }

    public function test_nested_transaction_is_refused_before_authority_or_policy_writes(): void
    {
        $actor = LicenseFixtures::admin();
        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            app(PrepareProductionTrackPolicy::class)->review(null, ProductionTrackPolicyFixtures::authored(), $actor);
        } finally {
            DB::rollBack();
        }
    }

    #[DataProvider('immutableRows')]
    public function test_raw_sql_cannot_mutate_or_delete_retained_policy_evidence(string $table, string $operation): void
    {
        $draft = ProductionTrackPolicyFixtures::create();
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $reviewer = LicenseFixtures::admin();
        app(ReviewProductionTrackPolicy::class)->applyReviewed(app(ReviewProductionTrackPolicy::class)->review($version, $reviewer), $this->reference(), $reviewer);
        $before = $this->snapshot();
        try {
            $query = DB::table($table);
            $operation === 'delete' ? $query->delete() : $query->update($table === 'production_track_policy_drafts' ? ['public_id' => (string) Str::uuid()]
                : ($table === 'production_track_policy_versions' ? ['payload_hash' => str_repeat('a', 64)] : ['review_hash' => str_repeat('a', 64)]));
            $this->fail('Immutable preparation evidence was mutated.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function immutableRows(): array
    {
        return [['production_track_policy_drafts', 'update'], ['production_track_policy_drafts', 'delete'],
            ['production_track_policy_versions', 'update'], ['production_track_policy_versions', 'delete'],
            ['production_track_policy_source_reviews', 'update'], ['production_track_policy_source_reviews', 'delete']];
    }

    #[DataProvider('replacementCollisions')]
    public function test_raw_sql_replace_cannot_substitute_retained_policy_identity(string $table, string $collision): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        app(ReviewProductionTrackPolicy::class)->applyReviewed(app(ReviewProductionTrackPolicy::class)->review($version, $reviewer), $this->reference(), $reviewer);
        $row = (array) DB::table($table)->first();
        if ($collision === 'unique' && $table !== 'production_track_policy_source_reviews') {
            // A raw insert can leave the command's revision-zero parent intermediate.
            // Even then an already inserted identity must never be replaced.
            $parent = ['public_id' => (string) Str::uuid(), 'revision' => 0, 'created_by' => $author->id,
                'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'updated_at' => now()->utc()->format('Y-m-d H:i:s')];
            $parent['id'] = DB::table('production_track_policy_drafts')->insertGetId($parent);
            if ($table === 'production_track_policy_drafts') {
                $row = $parent;
            } else {
                unset($row['id']);
                $row['production_track_policy_draft_id'] = $parent['id'];
                $row['created_at'] = $parent['created_at'];
                $row['id'] = DB::table($table)->insertGetId($row);
            }
        }
        if ($collision === 'unique') {
            $row['id'] += 1000;
        } elseif ($table === 'production_track_policy_drafts') {
            $row['public_id'] = (string) Str::uuid();
            $row['revision'] = 0;
            $row['updated_at'] = $row['created_at'];
        } elseif ($table === 'production_track_policy_versions') {
            $row['number'] = 2;
        } else {
            app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $author), $author);
            $latest = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->orderByDesc('number')->firstOrFail();
            $row['production_track_policy_version_id'] = $latest->id;
            $row['version_evidence_hash'] = $latest->payload_hash;
            $row['created_at'] = now()->utc()->format('Y-m-d H:i:s');
        }
        if ($table !== 'production_track_policy_drafts') {
            $field = $table === 'production_track_policy_versions' ? 'payload' : 'review';
            $row[$field.'_ciphertext'] = Crypt::encryptString(CanonicalJson::encode(['replacement' => 'NONBINDING PRIVATE TEST']));
            $row[$field.'_hash'] = hash('sha256', $row[$field.'_ciphertext']);
        } else {
            $row['created_by'] = $reviewer->id;
        }
        $recursive = null;
        if (DB::getDriverName() === 'sqlite') {
            $recursive = (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers;
            DB::statement('PRAGMA recursive_triggers = OFF');
            $this->assertSame(0, (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers);
        }
        $before = $this->snapshot();
        try {
            $columns = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', array_keys($row)));
            DB::statement('REPLACE INTO `'.$table.'` ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')', array_values($row));
            $this->fail('REPLACE substituted immutable production policy identity.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshot());
        } finally {
            if ($recursive !== null) {
                DB::statement('PRAGMA recursive_triggers = '.$recursive);
            }
        }
    }

    public static function replacementCollisions(): array
    {
        $cases = [];
        foreach (['production_track_policy_drafts', 'production_track_policy_versions', 'production_track_policy_source_reviews'] as $table) {
            foreach (['id', 'unique'] as $collision) {
                $cases[$table.' '.$collision] = [$table, $collision];
            }
        }

        return $cases;
    }

    public function test_retained_revision_zero_without_a_version_is_refused_without_partial_repair(): void
    {
        $actor = LicenseFixtures::admin();
        $row = ['public_id' => (string) Str::uuid(), 'revision' => 0, 'created_by' => $actor->id,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'updated_at' => now()->utc()->format('Y-m-d H:i:s')];
        $id = DB::table('production_track_policy_drafts')->insertGetId($row);
        $draft = ProductionTrackPolicyDraft::findOrFail($id);
        $before = $this->snapshot();
        try {
            app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(), $actor);
            $this->fail('Malformed retained revision-zero source was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['policy'], array_keys($exception->errors()));
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_populated_rollback_refuses_before_removing_any_guard_or_table(): void
    {
        ProductionTrackPolicyFixtures::create();
        $migration = require database_path('migrations/2026_10_06_233000_production_track_policy_drafts.php');
        $before = $this->snapshot();
        try {
            $migration->down();
            $this->fail('Populated policy preparation was removed.');
        } catch (\RuntimeException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_canonical_byte_bound_applies_after_escaping_each_individually_valid_field(): void
    {
        $source = ProductionTrackPolicyFixtures::authored();
        foreach ($source['declarations'] as &$declaration) {
            $declaration['choice'] = str_repeat('"', 512);
            $declaration['source_reference'] = str_repeat('"', 512);
            $declaration['note'] = str_repeat('\\', 1024);
        }
        unset($declaration);
        $this->assertGreaterThan(32768, strlen(CanonicalJson::encode($source)));
        $this->expectException(ValidationException::class);
        \App\Domain\Commerce\Policy\ProductionTrackPolicyDraft::validateAuthored($source);
    }

    #[DataProvider('invalidReviewReferences')]
    public function test_source_acknowledgment_requires_an_explicit_bounded_nonsecret_reference(string $path, mixed $value): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $capture = app(ReviewProductionTrackPolicy::class)->review($version, $reviewer);
        $reference = $this->reference();
        Arr::set($reference, $path, $value);
        $before = $this->snapshot();
        try {
            app(ReviewProductionTrackPolicy::class)->applyReviewed($capture, $reference, $reviewer);
            $this->fail('Invalid source acknowledgment reference was accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function invalidReviewReferences(): array
    {
        return [['reference', ''], ['reference', str_repeat('r', 513)], ['reference', 'whsec_SyntheticCredential'],
            ['source_sha256', null], ['source_sha256', str_repeat('A', 64)], ['authored_source_acknowledged', 'true'],
            ['authored_source_acknowledged', false], ['external_facts_verified', true]];
    }

    public function test_historical_acknowledgment_survives_reviewer_later_authoring_a_successor_but_blocks_self_review(): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $review = app(ReviewProductionTrackPolicy::class)->review($version, $reviewer);
        $ack = app(ReviewProductionTrackPolicy::class)->applyReviewed($review, $this->reference(), $reviewer);
        $original = $ack->getAttributes();
        app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $reviewer), $reviewer);
        $this->assertSame(CanonicalJson::encode($original), CanonicalJson::encode($ack->fresh()->getAttributes()));
        $latest = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->orderByDesc('number')->firstOrFail();
        $this->expectException(AuthorizationException::class);
        app(ReviewProductionTrackPolicy::class)->review($latest, $reviewer);
    }

    public function test_raw_sql_cannot_insert_original_author_as_source_reviewer(): void
    {
        $author = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $ciphertext = Crypt::encryptString('{}');
        try {
            DB::table('production_track_policy_source_reviews')->insert([
                'production_track_policy_version_id' => $version->id, 'reviewed_by' => $author->id, 'version_evidence_hash' => $version->payload_hash,
                'review_ciphertext' => $ciphertext, 'review_hash' => hash('sha256', $ciphertext), 'canonicalization_version' => CanonicalJson::VERSION,
                'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
            $this->fail('SQL accepted self-review.');
        } catch (QueryException) {
            $this->assertDatabaseCount('production_track_policy_source_reviews', 0);
        }
    }

    public function test_retained_malformed_source_acknowledgment_is_refused_without_repair_or_source_write(): void
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($author);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $draft->id)->sole();
        $ciphertext = Crypt::encryptString(CanonicalJson::encode(['activation_allowed' => true]));
        DB::table('production_track_policy_source_reviews')->insert([
            'production_track_policy_version_id' => $version->id, 'reviewed_by' => $reviewer->id, 'version_evidence_hash' => $version->payload_hash,
            'review_ciphertext' => $ciphertext, 'review_hash' => hash('sha256', $ciphertext), 'canonicalization_version' => CanonicalJson::VERSION,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
        $before = $this->snapshot();
        try {
            app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(), $author);
            $this->fail('Malformed retained acknowledgment was treated as verified source.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_late_audit_callback_cannot_append_an_unreviewed_policy_successor(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = ProductionTrackPolicyFixtures::create($actor);
        $capture = app(PrepareProductionTrackPolicy::class)->review($draft, ProductionTrackPolicyFixtures::authored(true), $actor);
        $before = $this->snapshot();
        AuditEvent::created(function () use ($draft, $actor): void {
            $ciphertext = Crypt::encryptString(CanonicalJson::encode(ProductionTrackPolicyFixtures::authored()));
            DB::table('production_track_policy_versions')->insert([
                'production_track_policy_draft_id' => $draft->id, 'number' => 3, 'schema_version' => 1, 'payload_ciphertext' => $ciphertext,
                'payload_hash' => hash('sha256', $ciphertext), 'canonicalization_version' => CanonicalJson::VERSION,
                'created_by' => $actor->id, 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
            DB::table('production_track_policy_drafts')->where('id', $draft->id)->update(['revision' => 3]);
        });
        try {
            app(SaveProductionTrackPolicy::class)->applyReviewed($capture, $actor);
            $this->fail('An unreviewed late successor committed.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        } finally {
            AuditEvent::flushEventListeners();
            AuditEvent::clearBootedModels();
        }
    }
}
