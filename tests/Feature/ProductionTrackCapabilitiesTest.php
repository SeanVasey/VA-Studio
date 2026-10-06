<?php

namespace Tests\Feature;

use App\Domain\Commerce\Policy\Models\ProductionTrackPolicyVersion;
use App\Domain\Commerce\Policy\PrepareProductionTrackPolicy;
use App\Domain\Commerce\Policy\ReviewProductionTrackPolicy;
use App\Domain\Commerce\Policy\SaveProductionTrackPolicy;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\CloseProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReadProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\Support\ProductionTrackPolicyFixtures;
use Tests\TestCase;

class ProductionTrackCapabilitiesTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Http::preventStrayRequests();
    }

    private function source(bool $acknowledged = true, bool $unresolved = false): array
    {
        $author = LicenseFixtures::admin();
        $reviewer = LicenseFixtures::admin();
        $authored = ProductionTrackPolicyFixtures::authored($unresolved);
        $source = ProductionTrackPolicyFixtures::create($author, $authored);
        if ($acknowledged) {
            $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $source->id)->sole();
            $capture = app(ReviewProductionTrackPolicy::class)->review($version, $reviewer);
            app(ReviewProductionTrackPolicy::class)->applyReviewed($capture, $this->reference('authored_source_acknowledged'), $reviewer);
        }

        return [$source, $author, $reviewer, $authored];
    }

    private function candidate(bool $approved = false): array
    {
        [$source, $author, $reviewer, $authored] = $this->source();
        $machine = ProductionTrackCapabilitiesFixtures::machine($authored);
        $capture = app(PrepareProductionTrackCapabilities::class)->review(null, $source, $machine, $author);
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
        if ($approved) {
            $approval = app(ReviewProductionTrackCapabilities::class)->review($candidate, $reviewer);
            app(ReviewProductionTrackCapabilities::class)->applyReviewed($approval, $this->reference('software_choices_reviewed'), $reviewer);
        }

        return [$candidate, $source, $author, $reviewer, $machine, $authored];
    }

    private function reference(string $affirmation): array
    {
        return ['reference' => 'synthetic:review-only', 'source_sha256' => hash('sha256', 'synthetic review only'), $affirmation => true];
    }

    private function reason(): array
    {
        return ['reason_code' => 'owner_withdrawal', ...$this->reference('closure_requested')];
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['users', 'production_track_policy_drafts', 'production_track_policy_versions', 'production_track_policy_source_reviews',
            CapabilityHistory::CANDIDATES, CapabilityHistory::APPROVALS, CapabilityHistory::CLOSURES, 'audit_events'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    public function test_encrypted_exact_candidate_and_separate_software_approval_project_without_execution(): void
    {
        [$candidate, $source, $author, $reviewer, $machine] = $this->candidate(true);
        $body = json_decode(Crypt::decryptString($candidate->payload_ciphertext), true, 12, JSON_THROW_ON_ERROR);
        $this->assertSame(CanonicalJson::encode($machine), CanonicalJson::encode($body['machine']));
        $this->assertFalse($body['execution_allowed']);
        $this->assertFalse($body['external_facts_verified']);
        $this->assertArrayNotHasKey('payload_ciphertext', $candidate->toArray());
        $context = PreparationContextV1::forMachine($machine);
        $before = $this->rows();
        $projection = app(ReadProductionTrackCapabilities::class)->project($candidate, $context, $author);
        $this->assertSame($before, $this->rows());
        $this->assertFalse($projection['execution_allowed']);
        $this->assertFalse($projection['external_facts_verified']);
        $this->assertSame(CanonicalJson::hash($machine), $projection['machine_hash']);
        $this->assertSame($candidate->id, $projection['candidate_id']);
        $this->assertSame($source->id, $projection['source']['draft_id']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $projection['signature']);
        foreach ([CapabilityHistory::CANDIDATES, CapabilityHistory::APPROVALS, 'audit_events'] as $table) {
            $this->assertStringNotContainsString('NONBINDING', json_encode($before[$table], JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('synthetic:', json_encode($before[$table], JSON_THROW_ON_ERROR));
        }
        foreach (['orders', 'checkout_intents', 'license_grants', 'pending_entitlements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_exact_noop_preserves_rows_and_successor_changes_generation_without_rewriting_history(): void
    {
        [$candidate, $source, $author, , $machine] = $this->candidate();
        $noop = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
        $before = $this->rows();
        $this->travel(2)->seconds();
        $same = app(SaveProductionTrackCapabilities::class)->applyReviewed($noop, $author);
        $this->assertSame($candidate->id, $same->id);
        $this->assertSame($before, $this->rows());
        $machine['version'] = 'synthetic-machine-v2';
        $machine['choices']['currency'] = ['code' => 'JPY', 'minor_unit_exponent' => 0];
        $capture = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
        $next = app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
        $this->assertSame(2, $next->generation);
        $this->assertSame($before[CapabilityHistory::CANDIDATES][0], $this->rows()[CapabilityHistory::CANDIDATES][0]);
        $this->assertNotSame($candidate->public_id, $next->public_id);
        $this->assertDatabaseCount(CapabilityHistory::CANDIDATES, 2);
    }

    public function test_closed_candidate_cannot_noop_project_or_reuse_its_version(): void
    {
        [$candidate, $source, $author, , $machine] = $this->candidate(true);
        $review = app(CloseProductionTrackCapabilities::class)->review($candidate, $author);
        $closure = app(CloseProductionTrackCapabilities::class)->applyReviewed($review, $this->reason(), $author);
        $body = json_decode(Crypt::decryptString($closure->closure_ciphertext), true, 12, JSON_THROW_ON_ERROR);
        $this->assertFalse($body['execution_allowed']);
        $before = $this->rows();
        foreach (['project', 'noop', 'reuse'] as $action) {
            try {
                if ($action === 'project') {
                    app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author);
                } else {
                    $machine['choices']['provider_account']['capture_method'] = $action === 'reuse' ? 'manual' : 'automatic';
                    $capture = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
                    app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
                }
                $this->fail('Closed candidate was revived.');
            } catch (ValidationException) {
                $this->assertSame($before, $this->rows());
            }
        }
        $machine['version'] = 'synthetic-machine-v2';
        $capture = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
        $this->assertSame(2, app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author)->generation);
    }

    public static function incompleteSources(): array
    {
        return ['missing source acknowledgment' => [false, false], 'unresolved acknowledged declaration' => [true, true]];
    }

    #[DataProvider('incompleteSources')]
    public function test_every_source_category_and_independent_acknowledgment_are_required(bool $acknowledged, bool $unresolved): void
    {
        [$source, $author, , $authored] = $this->source($acknowledged, $unresolved);
        $before = $this->rows();
        try {
            app(PrepareProductionTrackCapabilities::class)->review(null, $source, ProductionTrackCapabilitiesFixtures::machine($authored), $author);
            $this->fail('Incomplete source admitted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_source_creator_source_editor_and_prior_candidate_author_cannot_approve(): void
    {
        [$candidate, $source, $author, $reviewer, $machine, $authored] = $this->candidate();
        try {
            app(ReviewProductionTrackCapabilities::class)->review($candidate, $author);
            $this->fail('Source creator approved.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount(CapabilityHistory::APPROVALS, 0);
        }
        $editor = LicenseFixtures::admin();
        $authored['version'] = 'synthetic-source-v2';
        $source = app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($source, $authored, $editor), $editor);
        $version = ProductionTrackPolicyVersion::where('production_track_policy_draft_id', $source->id)->latest('id')->firstOrFail();
        app(ReviewProductionTrackPolicy::class)->applyReviewed(app(ReviewProductionTrackPolicy::class)->review($version, $reviewer), $this->reference('authored_source_acknowledged'), $reviewer);
        $priorCandidateAuthor = LicenseFixtures::admin();
        $machine['version'] = 'synthetic-machine-v2';
        $next = app(SaveProductionTrackCapabilities::class)->applyReviewed(app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $priorCandidateAuthor), $priorCandidateAuthor);
        foreach ([$editor, $priorCandidateAuthor] as $excluded) {
            try {
                app(ReviewProductionTrackCapabilities::class)->review($next, $excluded);
                $this->fail('Family author approved.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount(CapabilityHistory::APPROVALS, 0);
            }
        }
        $machine['version'] = 'synthetic-machine-v3';
        $third = app(SaveProductionTrackCapabilities::class)->applyReviewed(app(PrepareProductionTrackCapabilities::class)->review($next, $source, $machine, $author), $author);
        $this->expectException(AuthorizationException::class);
        app(ReviewProductionTrackCapabilities::class)->review($third, $priorCandidateAuthor);
    }

    public function test_new_source_invalidates_projection_and_prepared_approval_but_old_candidate_closure_still_works(): void
    {
        [$candidate, $source, $author, $reviewer, $machine, $authored] = $this->candidate();
        $approval = app(ReviewProductionTrackCapabilities::class)->review($candidate, $reviewer);
        $closure = app(CloseProductionTrackCapabilities::class)->review($candidate, $author);
        $authored['version'] = 'synthetic-source-v2';
        $authored['declarations']['currency']['note'] = 'Changed authored source; supplied machine choices still match.';
        app(SaveProductionTrackPolicy::class)->applyReviewed(app(PrepareProductionTrackPolicy::class)->review($source, $authored, $author), $author);
        foreach (['approval', 'closure', 'project'] as $action) {
            try {
                match ($action) {
                    'approval' => app(ReviewProductionTrackCapabilities::class)->applyReviewed($approval, $this->reference('software_choices_reviewed'), $reviewer),
                    'closure' => app(CloseProductionTrackCapabilities::class)->applyReviewed($closure, $this->reason(), $author),
                    'project' => app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author),
                };
                $this->fail('Stale source baseline admitted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount(CapabilityHistory::APPROVALS, 0);
                $this->assertDatabaseCount(CapabilityHistory::CLOSURES, 0);
            }
        }
        $fresh = app(CloseProductionTrackCapabilities::class)->review($candidate, $author);
        app(CloseProductionTrackCapabilities::class)->applyReviewed($fresh, $this->reason(), $author);
        $this->assertDatabaseCount(CapabilityHistory::CLOSURES, 1);
    }

    public function test_stale_candidate_approval_and_competing_save_cannot_apply_after_new_generation(): void
    {
        [$candidate, $source, $author, $reviewer, $machine] = $this->candidate();
        $approval = app(ReviewProductionTrackCapabilities::class)->review($candidate, $reviewer);
        $machine['version'] = 'synthetic-machine-v2';
        $save = app(PrepareProductionTrackCapabilities::class)->review($candidate, $source, $machine, $author);
        app(SaveProductionTrackCapabilities::class)->applyReviewed($save, $author);
        $before = $this->rows();
        foreach (['save', 'approval'] as $action) {
            try {
                $action === 'save' ? app(SaveProductionTrackCapabilities::class)->applyReviewed($save, $author)
                    : app(ReviewProductionTrackCapabilities::class)->applyReviewed($approval, $this->reference('software_choices_reviewed'), $reviewer);
                $this->fail('Stale generation admitted.');
            } catch (ValidationException) {
                $this->assertSame($before, $this->rows());
            }
        }
    }

    public function test_projection_requires_separate_software_approval(): void
    {
        [$candidate, , $author, , $machine] = $this->candidate();
        $this->expectException(ValidationException::class);
        app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author);
    }

    public static function mismatchedContexts(): array
    {
        return [
            ['purpose', 'production_payment_collection'], ['schema_version', 2], ['provider', 'other'], ['account_id', 'acct_OTHER'],
            ['mode', 'test'], ['api_version', '2026-08-27.dahlia'], ['capture_method', 'manual'], ['currency', 'JPY'],
            ['minor_unit_exponent', '2'], ['storage_adapter', 'private_object_store'], ['storage_adapter_version', 'other'],
            ['storage_boundary_id', 'other'], ['renderer_profile', 'other'], ['delivery_transfer', 'other'], ['assent_version', 'other'],
            ['execution_allowed', true],
        ];
    }

    #[DataProvider('mismatchedContexts')]
    public function test_all_explicit_adapter_context_bindings_must_match(string $key, mixed $value): void
    {
        [$candidate, , $author, , $machine] = $this->candidate(true);
        $context = PreparationContextV1::forMachine($machine);
        $context[$key] = $value;
        $this->expectException(ValidationException::class);
        app(ReadProductionTrackCapabilities::class)->project($candidate, $context, $author);
    }

    public static function editedCaptures(): array
    {
        return [['signature'], ['after'], ['authority_hash'], ['baseline_hash'], ['source_id'], ['public_id'], ['intent'], ['actor_id']];
    }

    #[DataProvider('editedCaptures')]
    public function test_a_capture_cannot_be_edited_or_retargeted(string $key): void
    {
        [$source, $author] = $this->source();
        $capture = app(PrepareProductionTrackCapabilities::class)->review(null, $source, ProductionTrackCapabilitiesFixtures::machine(), $author);
        $capture[$key] = match ($key) {
            'signature', 'authority_hash', 'baseline_hash' => str_repeat('0', 64),
            'after' => ProductionTrackCapabilitiesFixtures::machine() + ['execution_allowed' => true],
            'source_id', 'actor_id' => $capture[$key] + 1,
            'public_id' => '00000000-0000-4000-8000-000000000000',
            'intent' => 'approve',
        };
        $this->expectException(ValidationException::class);
        app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
    }

    public function test_actor_change_and_durable_authority_aba_invalidate_prepared_save(): void
    {
        [$source, $author] = $this->source();
        $capture = app(PrepareProductionTrackCapabilities::class)->review(null, $source, ProductionTrackCapabilitiesFixtures::machine(), $author);
        DB::table('users')->where('id', $author->id)->update(['is_admin' => false]);
        DB::table('users')->where('id', $author->id)->update(['is_admin' => true]);
        AuditEvent::create(['actor_id' => $author->id, 'action' => 'synthetic.authority.changed', 'subject_type' => User::class,
            'subject_id' => $author->id, 'context' => ['synthetic' => true], 'created_at' => now()->utc()->format('Y-m-d H:i:s')]);
        $before = $this->rows();
        try {
            app(SaveProductionTrackCapabilities::class)->applyReviewed($capture, $author);
            $this->fail('Authority ABA accepted.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_nested_transaction_and_stale_or_missing_operator_authority_are_refused(): void
    {
        [$candidate, , $author, , $machine] = $this->candidate(true);
        DB::beginTransaction();
        try {
            app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author);
            $this->fail('Nested transaction admitted.');
        } catch (LogicException) {
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        DB::table('users')->where('id', $author->id)->update(['is_admin' => false]);
        $this->expectException(AuthorizationException::class);
        app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author);
    }

    public function test_withdrawn_production_mfa_cannot_use_a_retained_operator_model(): void
    {
        [$candidate, , $author, , $machine] = $this->candidate(true);
        Filament::getPanel('admin')->multiFactorAuthentication(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders(), isRequired: true);
        $this->expectException(AuthorizationException::class);
        app(ReadProductionTrackCapabilities::class)->project($candidate, PreparationContextV1::forMachine($machine), $author);
    }
}
