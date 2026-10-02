<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class RightsEvidenceGuardTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function pending(): array
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic rights source', 'slug' => 'synthetic-rights-source']);
        $pending = RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-REVIEWED-SOURCE',
            'sample_disclosure' => 'Synthetic source only', 'status' => 'pending']);

        return compact('actor', 'track', 'pending');
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'rights_declarations', 'audit_events']);
    }

    private function sqlRefused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('SQL changed or replaced retained verified rights evidence.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Verified rights evidence', $error->getMessage(), 'The owned guard must refuse the operation, rather than an unrelated constraint.');
        }
    }

    private function nonRecursive(callable $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $operation();

            return;
        }
        $before = (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers;
        DB::statement('PRAGMA recursive_triggers = OFF');
        try {
            $this->assertSame(0, (int) DB::selectOne('PRAGMA recursive_triggers')->recursive_triggers);
            $operation();
        } finally {
            DB::statement('PRAGMA recursive_triggers = '.$before);
        }
    }

    private function withoutForeignKeys(callable $operation): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'The foreign-key control must take effect outside a transaction.');
        Schema::disableForeignKeyConstraints();
        try {
            $enabled = DB::getDriverName() === 'sqlite'
                ? DB::selectOne('PRAGMA foreign_keys')->foreign_keys
                : DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled;
            $this->assertSame(0, (int) $enabled);
            $operation();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function insertSql(string $verb, string $table, array $row): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $columns = implode(', ', array_map($grammar->wrap(...), array_keys($row)));
        $parameters = implode(', ', array_fill(0, count($row), '?'));

        return ["{$verb} ".$grammar->wrapTable($table)." ({$columns}) VALUES ({$parameters})", array_values($row)];
    }

    private function explicitZeroIds(callable $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $operation();

            return;
        }
        $original = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $modes = array_filter(explode(',', $original));
        $modes[] = 'NO_AUTO_VALUE_ON_ZERO';
        DB::statement('SET SESSION sql_mode = ?', [implode(',', array_unique($modes))]);
        try {
            $operation();
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$original]);
        }
    }

    public function test_fresh_verified_model_control_preserves_the_original_verifier_time_row_and_audit(): void
    {
        ['actor' => $actor, 'pending' => $pending] = $this->pending();
        $verified = app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        $before = $this->evidence();
        try {
            $verified->update(['provenance_reference' => 'SYNTHETIC-UNREVIEWED']);
            $this->fail('A fresh verified model was editable.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('rights', $error->errors());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame($actor->id, $verified->fresh()->verified_by);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'rights.declaration.verified')->count());
    }

    public static function retainedChanges(): array
    {
        return ['provenance' => ['provenance_reference'], 'sample disclosure' => ['sample_disclosure'],
            'cross-track retarget' => ['track_id'], 'delete' => ['delete']];
    }

    #[DataProvider('retainedChanges')]
    public function test_a_model_retained_while_pending_cannot_change_or_delete_the_persisted_verified_row(string $field): void
    {
        ['actor' => $actor, 'pending' => $pending] = $this->pending();
        $target = Track::create(['title' => 'Synthetic target', 'slug' => 'synthetic-rights-target']);
        $this->assertSame('pending', $pending->getOriginal('status'));
        $verified = app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        $row = $verified->getAttributes();
        $hash = app(OfferSnapshot::class)->rightsHash($verified);
        $before = $this->evidence();
        $this->assertSame('pending', $pending->getOriginal('status'), 'Use a genuinely stale pending instance.');
        $this->sqlRefused(fn () => $field === 'delete' ? $pending->delete()
            : $pending->update([$field => $field === 'track_id' ? $target->id : 'SYNTHETIC-UNREVIEWED-REPLACEMENT']));
        $this->assertSame($before, $this->evidence());
        $this->assertSame($row, $verified->fresh()->getAttributes());
        $this->assertSame($hash, app(OfferSnapshot::class)->rightsHash($verified->fresh()));
        $this->assertNull($target->rightsDeclarations()->first());
    }

    public static function fieldMutations(): array
    {
        $cases = [];
        foreach (['id', 'track_id', 'provenance_reference', 'sample_disclosure', 'status', 'verified_by', 'verified_at', 'created_at', 'updated_at'] as $field) {
            foreach (['query builder', 'raw SQL'] as $path) {
                $cases[$path.' '.$field] = [$field, $path === 'raw SQL'];
            }
        }

        return $cases;
    }

    #[DataProvider('fieldMutations')]
    public function test_query_and_raw_sql_cannot_change_any_column_of_the_persisted_verified_row(string $field, bool $raw): void
    {
        ['actor' => $actor, 'pending' => $pending] = $this->pending();
        $target = Track::create(['title' => 'Synthetic target', 'slug' => 'synthetic-rights-target']);
        $otherActor = LicenseFixtures::admin();
        $verified = app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        $value = match ($field) {
            'id' => $verified->id + 100,
            'track_id' => $target->id,
            'verified_by' => $otherActor->id,
            'status' => 'pending',
            'verified_at', 'created_at', 'updated_at' => '2026-01-02 03:04:05',
            default => 'SYNTHETIC-UNREVIEWED-REPLACEMENT',
        };
        $before = $this->evidence();
        $this->sqlRefused(function () use ($verified, $field, $value, $raw): void {
            if ($raw) {
                $column = DB::connection()->getQueryGrammar()->wrap($field);
                DB::update("UPDATE rights_declarations SET {$column} = ? WHERE id = ?", [$value, $verified->id]);
            } else {
                DB::table('rights_declarations')->where('id', $verified->id)->update([$field => $value]);
            }
        });
        $this->assertSame($before, $this->evidence());
    }

    public static function deletePaths(): array
    {
        return ['fresh model' => ['model'], 'query builder' => ['query'], 'raw SQL' => ['raw']];
    }

    #[DataProvider('deletePaths')]
    public function test_an_unreferenced_verified_declaration_cannot_be_deleted_through_any_supported_sql_path(string $path): void
    {
        ['actor' => $actor, 'pending' => $pending] = $this->pending();
        $verified = app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        $this->assertSame(0, DB::table('offer_revisions')->count(), 'No downstream foreign key may supply a false-positive refusal.');
        $before = $this->evidence();
        $this->sqlRefused(fn () => match ($path) {
            'model' => $verified->delete(),
            'query' => DB::table('rights_declarations')->where('id', $verified->id)->delete(),
            'raw' => DB::delete('DELETE FROM rights_declarations WHERE id = ?', [$verified->id]),
        });
        $this->assertSame($before, $this->evidence());
    }

    public static function insertConflicts(): array
    {
        return ['explicit-id replacement' => ['replace'], 'explicit-id upsert' => ['upsert']];
    }

    #[DataProvider('insertConflicts')]
    public function test_replacement_or_upsert_cannot_erase_verified_evidence_even_without_recursive_delete_triggers(string $kind): void
    {
        ['actor' => $actor, 'pending' => $pending] = $this->pending();
        $verified = app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        $row = (array) DB::table('rights_declarations')->where('id', $verified->id)->first();
        $row['provenance_reference'] = 'SYNTHETIC-REPLACEMENT';
        $before = $this->evidence();
        $this->nonRecursive(function () use ($kind, $row): void {
            $verbs = $kind === 'replace' && DB::getDriverName() === 'sqlite' ? ['REPLACE INTO', 'INSERT OR REPLACE INTO'] : [$kind === 'replace' ? 'REPLACE INTO' : 'INSERT INTO'];
            foreach ($verbs as $verb) {
                [$sql, $bindings] = $this->insertSql($verb, 'rights_declarations', $row);
                if ($kind === 'upsert') {
                    $sql .= DB::getDriverName() === 'sqlite'
                        ? ' ON CONFLICT(id) DO UPDATE SET provenance_reference = excluded.provenance_reference'
                        : ' ON DUPLICATE KEY UPDATE provenance_reference = VALUES(provenance_reference)';
                }
                $this->sqlRefused(fn () => DB::statement($sql, $bindings));
            }
        });
        $this->assertSame($before, $this->evidence());
    }

    public function test_a_pending_row_cannot_replace_a_different_verified_primary_identity_during_update(): void
    {
        ['actor' => $actor, 'track' => $track, 'pending' => $pending] = $this->pending();
        $verified = app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        $other = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-PENDING-OTHER',
            'sample_disclosure' => 'New pending source', 'status' => 'pending']);
        $before = $this->evidence();
        $this->nonRecursive(function () use ($verified, $other): void {
            // SQLite's aliases change INTEGER PRIMARY KEY; MySQL has no UPDATE OR REPLACE dialect.
            $aliases = DB::getDriverName() === 'sqlite' ? ['id', 'rowid', '_rowid_', 'oid'] : ['id'];
            foreach ($aliases as $column) {
                $verb = DB::getDriverName() === 'sqlite' ? 'UPDATE OR REPLACE' : 'UPDATE';
                $this->sqlRefused(fn () => DB::update("{$verb} rights_declarations SET {$column} = ? WHERE id = ?", [$verified->id, $other->id]));
            }
        });
        $this->assertSame($before, $this->evidence());
        $this->assertSame('pending', $other->fresh()->status);
        $this->assertSame('verified', $verified->fresh()->status);
    }

    public function test_pending_edit_retarget_upsert_verify_and_new_declarations_remain_available(): void
    {
        ['actor' => $actor, 'track' => $track, 'pending' => $pending] = $this->pending();
        $target = Track::create(['title' => 'Synthetic target', 'slug' => 'synthetic-rights-target']);
        $this->assertTrue($pending->update(['track_id' => $target->id, 'provenance_reference' => 'SYNTHETIC-EDITED-PENDING']));
        $this->assertSame(1, DB::table('rights_declarations')->where('id', $pending->id)->update(['sample_disclosure' => 'Edited pending source']));
        $row = (array) DB::table('rights_declarations')->where('id', $pending->id)->first();
        $row['provenance_reference'] = 'SYNTHETIC-PENDING-UPSERT';
        DB::table('rights_declarations')->upsert([$row], ['id'], ['provenance_reference']);
        $this->assertSame('SYNTHETIC-PENDING-UPSERT', $pending->fresh()->provenance_reference);
        $verified = app(VerifyRightsDeclaration::class)->handle($pending->fresh(), $actor);
        $this->assertSame('verified', $verified->status);
        $this->assertSame($target->id, $verified->track_id);
        $this->assertSame($actor->id, $verified->verified_by);
        $this->assertNotNull($verified->verified_at);
        $original = $verified->getAttributes();
        $successor = $target->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-NEW-PENDING',
            'sample_disclosure' => 'New source awaiting verification', 'status' => 'pending']);
        $this->assertGreaterThan($verified->id, $successor->id);
        $this->assertSame($original, $verified->fresh()->getAttributes());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'rights.declaration.verified')->count());
        $this->assertTrue($successor->delete(), 'Unverified unused drafts remain removable.');
        $this->assertNull($track->rightsDeclarations()->first());
        $this->assertSame($original, $verified->fresh()->getAttributes());
    }

    public function test_actual_verified_identities_are_positive_without_blocking_generated_ids_or_unverified_legacy_rows(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->pending();
        $this->explicitZeroIds(fn () => $this->withoutForeignKeys(function () use ($actor, $track): void {
            // MySQL's unsigned ID columns cannot represent -1; zero is made an actual stored identity here.
            $nonpositive = DB::getDriverName() === 'sqlite' ? [-1, 0] : [0];
            $before = $this->evidence();
            foreach ($nonpositive as $value) {
                foreach (['id', 'track_id'] as $field) {
                    $row = ['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-INVALID-IDENTITY',
                        'sample_disclosure' => 'Synthetic identity control', 'status' => 'verified',
                        'verified_by' => $actor->id, 'verified_at' => now()];
                    $row[$field] = $value;
                    $this->sqlRefused(fn () => DB::table('rights_declarations')->insert($row));
                    $this->assertSame($before, $this->evidence());
                }
            }
            $legacyId = $nonpositive[0];
            DB::table('rights_declarations')->insert(['id' => $legacyId, 'track_id' => $track->id,
                'provenance_reference' => 'SYNTHETIC-UNVERIFIED-LEGACY', 'sample_disclosure' => 'Unverified synthetic legacy source', 'status' => 'pending']);
            $this->assertSame(1, DB::table('rights_declarations')->where('id', $legacyId)->update(['sample_disclosure' => 'Edited unverified legacy source']));
            $legacyBefore = $this->evidence();
            $this->sqlRefused(fn () => DB::table('rights_declarations')->where('id', $legacyId)
                ->update(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]));
            $this->assertSame($legacyBefore, $this->evidence());
            $generated = RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-NEW-VERIFIED',
                'sample_disclosure' => 'Synthetic positive-ID control', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
            $this->assertGreaterThan(0, $generated->id);
            $this->assertSame('verified', $generated->fresh()->status);
            $this->assertSame('pending', DB::table('rights_declarations')->where('id', $legacyId)->value('status'));
        }));
    }

    public function test_verified_status_is_byte_exact_including_binary_storage_and_other_labels_do_not_invent_verification(): void
    {
        ['actor' => $actor, 'track' => $track] = $this->pending();
        $expression = DB::getDriverName() === 'sqlite' ? 'CAST(? AS BLOB)' : 'CAST(? AS BINARY)';
        DB::insert("INSERT INTO rights_declarations (track_id, provenance_reference, sample_disclosure, status, verified_by, verified_at) VALUES (?, ?, ?, {$expression}, ?, ?)",
            [$track->id, 'SYNTHETIC-BINARY-VERIFIED', 'Synthetic exact-byte status', 'verified', $actor->id, now()]);
        $id = (int) DB::table('rights_declarations')->max('id');
        $before = $this->evidence();
        $this->sqlRefused(fn () => DB::table('rights_declarations')->where('id', $id)->update(['sample_disclosure' => 'SYNTHETIC-UNREVIEWED']));
        $this->assertSame($before, $this->evidence());
        foreach (['Verified', 'verified '] as $label) {
            $unverified = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-UNVERIFIED-LABEL',
                'sample_disclosure' => 'Unverified synthetic status', 'status' => $label]);
            $this->assertTrue($unverified->update(['sample_disclosure' => 'Edited unverified synthetic status']));
            $this->assertSame($label, $unverified->fresh()->status);
            $this->assertNull($unverified->fresh()->verified_at);
            $this->assertNull($unverified->fresh()->verified_by);
        }
        $this->assertSame('verified', RightsDeclaration::findOrFail($id)->status);
    }

    public static function mixedStatements(): array
    {
        return ['pending update before refusal' => ['update'], 'pending delete before refusal' => ['delete'],
            'new row upsert before refusal' => ['upsert']];
    }

    #[DataProvider('mixedStatements')]
    public function test_a_statement_that_reaches_an_editable_row_before_verified_refusal_rolls_back_every_row(string $operation): void
    {
        ['actor' => $actor, 'track' => $track, 'pending' => $editable] = $this->pending();
        $other = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-SECOND-ORIGINAL',
            'sample_disclosure' => 'Second synthetic source', 'status' => 'pending']);
        $verified = app(VerifyRightsDeclaration::class)->handle($other, $actor);
        $this->assertLessThan($verified->id, $editable->id);
        $newId = $verified->id + 100;
        $observedId = $operation === 'upsert' ? $newId : $editable->id;
        $event = $operation === 'upsert' ? 'INSERT' : strtoupper($operation);
        $alias = $event === 'INSERT' ? 'NEW' : 'OLD';
        $visited = false;
        if (DB::getDriverName() === 'sqlite') {
            // The callback is outside database storage, so rollback cannot erase this observation.
            DB::connection()->getPdo()->sqliteCreateFunction('synthetic_rights_row_visited', function () use (&$visited): int {
                $visited = true;

                return 1;
            });
            DB::unprepared("CREATE TEMP TRIGGER synthetic_rights_statement_probe AFTER {$event} ON main.rights_declarations WHEN {$alias}.id = {$observedId} BEGIN SELECT synthetic_rights_row_visited(); END");
        } else {
            DB::statement('SET @synthetic_rights_row_visited = 0');
            DB::unprepared("CREATE TRIGGER synthetic_rights_statement_probe AFTER {$event} ON rights_declarations FOR EACH ROW BEGIN IF {$alias}.id = {$observedId} THEN SET @synthetic_rights_row_visited = 1; END IF; END");
        }
        $before = $this->evidence();
        try {
            $this->sqlRefused(function () use ($operation, $editable, $verified, $newId): void {
                if ($operation === 'upsert') {
                    $new = (array) DB::table('rights_declarations')->where('id', $editable->id)->first();
                    $new['id'] = $newId;
                    $new['provenance_reference'] = 'SYNTHETIC-NEW-ROW-BEFORE-REFUSAL';
                    $protected = (array) DB::table('rights_declarations')->where('id', $verified->id)->first();
                    $protected['provenance_reference'] = 'SYNTHETIC-UNREVIEWED-UPSERT';
                    DB::table('rights_declarations')->upsert([$new, $protected], ['id'], ['provenance_reference']);
                } else {
                    $sql = $operation === 'update'
                        ? 'UPDATE rights_declarations SET provenance_reference = ? WHERE id IN (?, ?)'
                        : 'DELETE FROM rights_declarations WHERE id IN (?, ?)';
                    if (DB::getDriverName() === 'mysql') {
                        $sql .= ' ORDER BY id';
                    }
                    DB::statement($sql, $operation === 'update'
                        ? ['SYNTHETIC-MIXED-STATEMENT', $editable->id, $verified->id]
                        : [$editable->id, $verified->id]);
                }
            });
            if (DB::getDriverName() === 'mysql') {
                // User variables are session observations, not rolled-back evidence rows.
                $visited = (int) DB::selectOne('SELECT @synthetic_rights_row_visited AS visited')->visited === 1;
            }
            $this->assertTrue($visited, 'The editable/new row must actually execute before the later verified-row refusal.');
            $this->assertSame($before, $this->evidence(), 'ABORT/SIGNAL must roll back the complete mixed statement, including its earlier writable row.');
        } finally {
            DB::unprepared(DB::getDriverName() === 'sqlite' ? 'DROP TRIGGER temp.synthetic_rights_statement_probe' : 'DROP TRIGGER synthetic_rights_statement_probe');
        }
    }

    public function test_parent_track_deletion_is_guarded_even_when_foreign_key_checks_are_disabled(): void
    {
        ['actor' => $actor, 'track' => $track, 'pending' => $pending] = $this->pending();
        app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        $before = $this->evidence();
        $this->withoutForeignKeys(fn () => $this->sqlRefused(fn () => DB::delete('DELETE FROM tracks WHERE id = ?', [$track->id])));
        $this->assertSame($before, $this->evidence());
    }

    public static function parentConflicts(): array
    {
        return ['primary identity' => ['id'], 'unique slug' => ['slug'], 'reserved published slug also uses unique slug' => ['published_slug']];
    }

    #[DataProvider('parentConflicts')]
    public function test_parent_replacement_preserves_the_actual_unique_identity_before_any_implicit_delete(string $conflict): void
    {
        ['actor' => $actor, 'track' => $track, 'pending' => $pending] = $this->pending();
        app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        if ($conflict === 'published_slug') {
            DB::table('tracks')->where('id', $track->id)->update(['published_slug' => $track->slug]);
        }
        $row = (array) DB::table('tracks')->where('id', $track->id)->first();
        $row['title'] = 'Synthetic parent replacement';
        if ($conflict !== 'id') {
            $row['id'] += 100;
        }
        $before = $this->evidence();
        $this->withoutForeignKeys(fn () => $this->nonRecursive(function () use ($row): void {
            [$sql, $bindings] = $this->insertSql('REPLACE INTO', 'tracks', $row);
            $this->sqlRefused(fn () => DB::statement($sql, $bindings));
        }));
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('parentConflicts')]
    public function test_pending_parent_updates_cannot_replace_a_different_parent_of_verified_evidence(string $conflict): void
    {
        ['actor' => $actor, 'track' => $track, 'pending' => $pending] = $this->pending();
        app(VerifyRightsDeclaration::class)->handle($pending, $actor);
        if ($conflict === 'published_slug') {
            DB::table('tracks')->where('id', $track->id)->update(['published_slug' => $track->slug]);
        }
        $other = Track::create(['title' => 'Synthetic other parent', 'slug' => 'synthetic-other-parent']);
        $before = $this->evidence();
        $this->withoutForeignKeys(fn () => $this->nonRecursive(function () use ($track, $other, $conflict): void {
            $verb = DB::getDriverName() === 'sqlite' ? 'UPDATE OR REPLACE' : 'UPDATE';
            $set = $conflict === 'id' ? 'id = ?' : ($conflict === 'slug' ? 'slug = ?' : 'slug = ?, published_slug = ?');
            $bindings = $conflict === 'id' ? [$track->id, $other->id] : ($conflict === 'slug' ? [$track->slug, $other->id] : [$track->slug, $track->slug, $other->id]);
            $this->sqlRefused(fn () => DB::update("{$verb} tracks SET {$set} WHERE id = ?", $bindings));
        }));
        $this->assertSame($before, $this->evidence());
    }

    public function test_a_new_pending_declaration_blocks_new_readiness_without_rewriting_original_commerce_or_contract_evidence(): void
    {
        DeliveryFixtures::configure();
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = DeliveryFixtures::activate(ActivationFixtures::issue(ContractFixtures::finalize(FinalizationFixtures::confirm(PaymentFixtures::started($gateway)))));
        $track = $fixture['track'];
        $verified = $track->rightsDeclarations()->latest('id')->firstOrFail();
        $rights = $verified->getAttributes();
        $historyTables = ['offers', 'offer_revisions', 'quotes', 'quote_lines', 'quote_pricings', 'orders', 'order_lines',
            'order_attempts', 'verified_payments', 'order_finalizations', 'license_grants', 'pending_entitlements', 'fulfillment_outbox',
            'contract_render_requests', 'contract_render_work', 'grant_contracts', 'test_fulfillment_activations', 'test_delivery_controls', 'audit_events'];
        $history = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $historyTables);
        $files = Storage::disk('local')->allFiles();
        sort($files, SORT_STRING);
        $fileHashes = fn () => array_map(fn ($path) => [$path, hash_file('sha256', Storage::disk('local')->path($path))], $files);
        $before = [$history(), $fileHashes()];
        $this->sqlRefused(fn () => DB::table('rights_declarations')->where('id', $verified->id)->update(['sample_disclosure' => 'SYNTHETIC-UNREVIEWED']));
        $successor = $track->rightsDeclarations()->create(['provenance_reference' => 'SYNTHETIC-NEW-PENDING',
            'sample_disclosure' => 'New source awaiting verification', 'status' => 'pending']);
        $this->assertSame($successor->id, $track->rightsDeclarations()->latest('id')->firstOrFail()->id);
        $this->assertNotEmpty(app(PublicationReadiness::class)->blockers($track->fresh()));
        $this->assertSame($rights, $verified->fresh()->getAttributes());
        $this->assertSame($before, [$history(), $fileHashes()]);
        $this->assertDatabaseCount('grant_contracts', 1);
        $this->assertDatabaseCount('license_grants', 1);
        $this->assertSame('verified', $verified->fresh()->status);
    }
}
