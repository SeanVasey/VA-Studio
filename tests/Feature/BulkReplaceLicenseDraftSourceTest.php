<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Rights\BulkReplaceLicenseDraftSource;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewedLicenseDraft;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BulkReplaceLicenseDraftSourceFixtures as Fixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class BulkReplaceLicenseDraftSourceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function evidence(): array
    {
        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['users', 'license_templates', 'license_versions', 'license_review_evidence', 'offers', 'offer_revisions', 'audit_events']);
    }

    private function refused(callable $operation, string $key = 'licenses'): void
    {
        $before = $this->evidence();
        try {
            $operation();
            $this->fail('An invalid or stale bulk source review was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_explicit_review_orders_reversed_selection_and_shows_exact_source_changes_without_writes(): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(3, true);
        $other = Fixtures::drafts(1, actor: $actor)['versions'][0];
        $versions[] = $other;
        $before = $this->evidence();
        $review = app(BulkReplaceLicenseDraftSource::class)->review(array_reverse($versions), Fixtures::replacement(), $actor);
        $this->assertSame(['schema_version', 'intent', 'actor_id', 'authored_source', 'drafts'], array_keys($review));
        $this->assertSame('replace_license_draft_source', $review['intent']);
        $this->assertSame(1, $review['schema_version']);
        $this->assertSame($actor->id, $review['actor_id']);
        $this->assertSame(array_column($versions, 'id'), array_column($review['drafts'], 'version_id'));
        foreach ($review['drafts'] as $index => $row) {
            $this->assertSame(['template_id', 'version_id', 'version', 'template_hash', 'version_hash', 'template_audit_id', 'version_audit_id', 'template', 'before', 'after'], array_keys($row));
            $this->assertSame(['authored_source', 'structured_terms', 'effective_from', 'effective_until'], array_keys($row['before']));
            $this->assertSame($versions[$index]->authored_source, $row['before']['authored_source']);
            $this->assertSame($review['authored_source'], $row['after']['authored_source']);
            $this->assertSame($row['before']['structured_terms'], $row['after']['structured_terms']);
            $this->assertSame($versions[$index]->template->only(SaveLicenseTemplate::FIELDS), $row['template']);
            $this->assertSame(CanonicalJson::hash($versions[$index]->getAttributes()), $row['version_hash']);
        }
        $this->assertSame($before, $this->evidence());
    }

    public static function schemas(): array
    {
        return ['original' => [1], 'typed' => [2], 'scoped' => [3], 'economic' => [4]];
    }

    #[DataProvider('schemas')]
    public function test_each_supported_schema_preserves_raw_terms_dates_lifecycle_and_original_author_exclusion(int $schema): void
    {
        ['actor' => $author, 'versions' => $versions] = Fixtures::drafts(2, true, $schema);
        $editor = LicenseFixtures::admin();
        foreach ($versions as $version) {
            $version->forceFill(['effective_from' => '2030-01-02 03:04:05', 'effective_until' => '2031-02-03 04:05:06'])->save();
        }
        $versions = array_map(fn ($version) => $version->fresh(), $versions);
        $raw = array_map(fn ($version) => $version->getAttributes(), $versions);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $noop = $command->review($versions, Fixtures::content($schema)['authored_source'], $editor);
        $before = $this->evidence();
        $this->assertSame(['changed_ids' => [], 'unchanged_ids' => array_column($versions, 'id')], $command->applyReviewed($noop, $editor));
        $this->assertSame($before, $this->evidence());
        $review = $command->review($versions, Fixtures::replacement($schema), $editor);
        AuditEvent::record('synthetic.unrelated', $editor, [], $editor->id);
        $this->assertSame(['changed_ids' => array_column($versions, 'id'), 'unchanged_ids' => []], $command->applyReviewed($review, $editor));
        foreach ($versions as $index => $version) {
            $saved = $version->fresh();
            $after = $saved->getAttributes();
            foreach (['authored_source', 'author_id', 'content_author_ids', 'updated_at'] as $key) {
                unset($raw[$index][$key], $after[$key]);
            }
            $this->assertSame($raw[$index], $after);
            $this->assertSame([$author->id, $editor->id], $saved->content_author_ids);
            $this->assertSame($editor->id, $saved->author_id);
            $audit = AuditEvent::where('subject_type', LicenseVersion::class)->where('subject_id', $saved->id)->latest('id')->firstOrFail();
            $this->assertSame('rights.license.draft_source_bulk_updated', $audit->action);
            $this->assertSame(CanonicalJson::hash($review), $audit->context['batch_review_hash']);
            $this->assertSame(['authored_source'], $audit->context['changed_fields']);
            $this->assertSame(CanonicalJson::hash($review['drafts'][$index]['before']), $audit->context['before_hash']);
            $this->assertSame(CanonicalJson::hash($review['drafts'][$index]['after']), $audit->context['after_hash']);
            $this->assertStringNotContainsString('NONBINDING', json_encode($audit->context));
        }
        $this->refused(fn () => $command->applyReviewed($review, $editor));
        $reopened = $command->review($versions, Fixtures::replacement($schema), $editor);
        $before = $this->evidence();
        $command->applyReviewed($reopened, $editor);
        $this->assertSame($before, $this->evidence());
        $submitted = app(ReviewLicense::class)->submit($versions[0]->fresh(), $editor);
        foreach ([$author, $editor] as $denied) {
            $this->refused(fn () => app(ReviewLicense::class)->approve($submitted, $denied, [
                'approval_reference' => 'SYNTHETIC', 'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]), 'license');
        }
    }

    public function test_mixed_no_op_and_changed_rows_have_complete_disjoint_results_and_only_one_edit_audit(): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2);
        $source = Fixtures::replacement();
        app(UpdateLicenseDraft::class)->handle($versions[0], array_replace(Fixtures::content(), ['authored_source' => $source]), $actor);
        $versions = array_map(fn ($version) => $version->fresh(), $versions);
        $untouched = $versions[0]->getAttributes();
        $count = AuditEvent::count();
        $command = app(BulkReplaceLicenseDraftSource::class);
        $result = $command->applyReviewed($command->review($versions, $source, $actor), $actor);
        $this->assertSame(['changed_ids' => [$versions[1]->id], 'unchanged_ids' => [$versions[0]->id]], $result);
        $this->assertSame($untouched, $versions[0]->fresh()->getAttributes());
        $this->assertSame($count + 1, AuditEvent::count());
    }

    public static function selections(): array
    {
        return ['empty' => ['empty'], 'associative' => ['associative'], 'duplicate' => ['duplicate'], 'unsaved' => ['unsaved'],
            'wrong model' => ['wrong'], 'missing row' => ['missing'], 'reparent hint' => ['parent']];
    }

    #[DataProvider('selections')]
    public function test_invalid_identity_selection_is_refused_without_any_effect(string $kind): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2);
        $selected = match ($kind) {
            'empty' => [], 'associative' => ['draft' => $versions[0]], 'duplicate' => [$versions[0], $versions[0]],
            'unsaved' => [new LicenseVersion], 'wrong' => [$actor], default => [clone $versions[0]],
        };
        if ($kind === 'missing') {
            $selected[0]->id = 999999;
        } elseif ($kind === 'parent') {
            $selected[0]->license_template_id = $versions[1]->license_template_id;
        }
        $this->refused(fn () => app(BulkReplaceLicenseDraftSource::class)->review($selected, Fixtures::replacement(), $actor));
    }

    public function test_count_and_canonical_byte_bounds_refuse_whole_selections_without_truncation(): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(26, true);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $this->refused(fn () => $command->review($versions, Fixtures::replacement(), $actor));
        $this->assertCount(25, $command->review(array_slice($versions, 0, 25), Fixtures::replacement(), $actor)['drafts']);
        $this->refused(fn () => $command->review(array_slice($versions, 0, 25), str_repeat('x', 100000), $actor));
        $review = $command->review([$versions[0]], Fixtures::replacement(), $actor);
        $review['drafts'][0]['before']['structured_terms']['synthetic_large'] = str_repeat('x', BulkReplaceLicenseDraftSource::MAX_REVIEW_BYTES);
        $review['drafts'][0]['after']['structured_terms'] = $review['drafts'][0]['before']['structured_terms'];
        $this->refused(fn () => $command->applyReviewed($review, $actor));
    }

    public static function invalidSources(): array
    {
        return ['empty' => [''], 'whitespace' => [" \n "], 'too long' => [str_repeat('x', 100001)],
            'invalid utf8' => ["bad\xFF"], 'control' => ["bad\x00source"], 'missing typed variables' => ['NONBINDING text without the retained required variables']];
    }

    #[DataProvider('invalidSources')]
    public function test_supplied_source_must_validate_against_each_retained_terms_schema(string $source): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true, 2);
        $this->refused(fn () => app(BulkReplaceLicenseDraftSource::class)->review($versions, $source, $actor), 'authored_source');
    }

    public function test_heterogeneous_drafts_require_one_source_compatible_with_every_target_before_any_save(): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(1, schema: 2);
        $versions[] = Fixtures::drafts(1, schema: 3, actor: $actor)['versions'][0];
        $this->refused(fn () => app(BulkReplaceLicenseDraftSource::class)->review($versions, Fixtures::replacement(2), $actor), 'authored_source');
    }

    public static function malformedReviews(): array
    {
        return ['schema' => ['schema_version', 2], 'intent' => ['intent', 'publish'], 'actor string' => ['actor_id', '1'],
            'extra top' => ['extra', true], 'string id' => ['drafts.0.version_id', '1'], 'float id' => ['drafts.0.template_id', 1.0],
            'negative cursor' => ['drafts.0.version_audit_id', -1], 'bad hash' => ['drafts.0.template_hash', str_repeat('g', 64)],
            'hash suffix' => ['drafts.0.version_hash', str_repeat('a', 64)."\n"], 'template extra' => ['drafts.0.template.extra', true],
            'source changed after review' => ['authored_source', 'Forged replacement'], 'before changed' => ['drafts.0.before.authored_source', 'Forged baseline'],
            'after changed' => ['drafts.0.after.authored_source', 'Forged after'], 'terms changed' => ['drafts.0.after.structured_terms.required_asset_roles', []],
            'date changed' => ['drafts.0.after.effective_until', '2035-01-01 00:00:00'], 'version changed' => ['drafts.0.version', 999],
            'unknown row key' => ['drafts.0.extra', true], 'missing projection' => ['drafts.0.before', null]];
    }

    #[DataProvider('malformedReviews')]
    public function test_forged_or_mutated_review_cannot_change_any_selected_draft(string $key, mixed $value): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, Fixtures::replacement(), $actor);
        data_set($review, $key, $value);
        $this->refused(fn () => $command->applyReviewed($review, $actor));
    }

    public function test_review_reordering_duplicate_ids_missing_keys_and_inconsistent_shared_parent_are_refused(): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, Fixtures::replacement(), $actor);
        $bad = $review;
        $bad['drafts'] = array_reverse($bad['drafts']);
        $this->refused(fn () => $command->applyReviewed($bad, $actor));
        $bad['drafts'] = [$review['drafts'][0], $review['drafts'][0]];
        $this->refused(fn () => $command->applyReviewed($bad, $actor));
        $bad = $review;
        unset($bad['intent']);
        $this->refused(fn () => $command->applyReviewed($bad, $actor));
        $bad = $review;
        $bad['drafts'][1]['template']['name'] = 'Forged same parent';
        $this->refused(fn () => $command->applyReviewed($bad, $actor));
    }

    public static function drifts(): array
    {
        return ['legacy edit' => ['legacy'], 'legacy ABA' => ['legacy-aba'], 'legacy no-op audit' => ['legacy-noop'],
            'single reviewed edit' => ['single'], 'template edit' => ['template'], 'template ABA' => ['template-aba']];
    }

    #[DataProvider('drifts')]
    public function test_any_one_changed_draft_or_parent_invalidates_the_whole_captured_batch_including_aba(string $kind): void
    {
        $this->freezeTime();
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, Fixtures::replacement(), $actor);
        $version = $versions[1];
        if (str_starts_with($kind, 'template')) {
            $template = $version->template;
            $service = app(SaveLicenseTemplate::class);
            $before = $template->only(SaveLicenseTemplate::FIELDS);
            $service->updateReviewed($service->review($template, $actor), array_replace($before, ['name' => 'Temporary synthetic identity']), $actor);
            if ($kind === 'template-aba') {
                $service->updateReviewed($service->review($template, $actor), $before, $actor);
                $this->assertSame($review['drafts'][1]['template_hash'], $command->review($versions, Fixtures::replacement(), $actor)['drafts'][1]['template_hash']);
            }
        } elseif ($kind === 'single') {
            $single = app(ReviewedLicenseDraft::class);
            $capture = $single->review($version, $actor);
            $single->updateReviewed($capture, array_replace(array_intersect_key($capture['display'], array_flip(ReviewedLicenseDraft::FIELDS)), ['authored_source' => 'Synthetic single winner']), $actor);
        } else {
            $service = app(UpdateLicenseDraft::class);
            $before = $review['drafts'][1]['before'];
            $service->handle($version, $kind === 'legacy-noop' ? $before : array_replace($before, ['authored_source' => 'Synthetic legacy winner']), $actor);
            if ($kind === 'legacy-aba') {
                $service->handle($version, $before, $actor);
                $this->assertSame($review['drafts'][1]['version_hash'], $command->review($versions, Fixtures::replacement(), $actor)['drafts'][1]['version_hash']);
            }
        }
        $this->refused(fn () => $command->applyReviewed($review, $actor));
    }

    public static function lifecycles(): array
    {
        return ['reviewed' => ['legal_review'], 'approved' => ['approved'], 'published' => ['published']];
    }

    #[DataProvider('lifecycles')]
    public function test_one_no_longer_editable_target_refuses_review_and_atomic_apply(string $status): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, Fixtures::replacement(), $actor);
        $selected = app(ReviewLicense::class)->submit($versions[1], $actor);
        if ($status !== 'legal_review') {
            $selected = app(ReviewLicense::class)->approve($selected, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC',
                'review_hash' => $selected->submission_hash, 'summary_consistency_confirmed' => true]);
        }
        if ($status === 'published') {
            app(PublishLicense::class)->handle($selected, $actor);
        }
        $this->refused(fn () => $command->review($versions, Fixtures::replacement(), $actor));
        $this->refused(fn () => $command->applyReviewed($review, $actor));
    }

    public static function authority(): array
    {
        $rows = [];
        foreach (['review', 'apply'] as $operation) {
            foreach (['role', 'email', 'mfa', 'unsaved', 'customer'] as $state) {
                $rows[$operation.' / '.$state] = [$operation, $state];
            }
        }

        return $rows;
    }

    #[DataProvider('authority')]
    public function test_fresh_actor_email_and_required_mfa_guard_private_capture_and_mutation(string $operation, string $state): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, Fixtures::replacement(), $actor);
        if ($state === 'role' || $state === 'email') {
            DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['email_verified_at' => null]);
        } elseif ($state === 'mfa') {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
            DB::table('users')->where('id', $actor->id)->update(['app_authentication_secret' => null]);
        } elseif ($state === 'unsaved') {
            $actor->exists = false;
        } else {
            $actor = User::factory()->create();
            $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);
        }
        $before = $this->evidence();
        try {
            $operation === 'review' ? $command->review($versions, Fixtures::replacement(), $actor) : $command->applyReviewed($review, $actor);
            $this->fail('Unavailable current authority accessed private selected drafts.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public static function lateAuthority(): array
    {
        return ['capture role' => ['review', 'role'], 'capture MFA' => ['review', 'mfa'], 'last audit role' => ['apply', 'role'], 'last audit MFA' => ['apply', 'mfa']];
    }

    #[DataProvider('lateAuthority')]
    public function test_authority_withdrawn_during_projection_or_last_audit_rolls_back_before_return(string $operation, string $state): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, Fixtures::replacement(), $actor);
        if ($state === 'mfa') {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        }
        $withdraw = fn () => DB::table('users')->where('id', $actor->id)->update($state === 'role' ? ['is_admin' => false] : ['app_authentication_secret' => null]);
        if ($operation === 'review') {
            LicenseVersion::retrieved($withdraw);
        } else {
            AuditEvent::created(function ($audit) use ($versions, $withdraw): void {
                if ($audit->subject_id === $versions[1]->id) {
                    $withdraw();
                }
            });
        }
        $before = $this->evidence();
        try {
            $operation === 'review' ? $command->review($versions, Fixtures::replacement(), $actor) : $command->applyReviewed($review, $actor);
            $this->fail('Late authority withdrawal returned a private review or committed bulk edits.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->evidence());
        }
    }

    public static function sabotage(): array
    {
        return ['earlier changed row' => ['earlier'], 'unchanged row' => ['noop'], 'template row' => ['template'],
            'template cursor' => ['template-audit'], 'earlier newer audit' => ['version-audit'],
            'own audit context' => ['context'], 'own audit action' => ['action'], 'own audit actor' => ['actor'], 'own audit subject' => ['subject']];
    }

    #[DataProvider('sabotage')]
    public function test_after_all_saves_every_row_and_actual_own_audit_identity_are_rechecked(string $kind): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true);
        $source = Fixtures::replacement();
        if ($kind === 'noop') {
            app(UpdateLicenseDraft::class)->handle($versions[0], array_replace(Fixtures::content(), ['authored_source' => $source]), $actor);
        }
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, $source, $actor);
        $other = LicenseFixtures::admin();
        if (in_array($kind, ['context', 'action', 'actor', 'subject'], true)) {
            AuditEvent::creating(function ($audit) use ($kind, $other, $versions): void {
                if ($audit->subject_id !== $versions[1]->id) {
                    return;
                }
                match ($kind) {
                    'context' => $audit->context = ['synthetic' => true],
                    'action' => $audit->action = 'synthetic.wrong',
                    'actor' => $audit->actor_id = $other->id,
                    'subject' => $audit->subject_id = $versions[0]->id,
                };
            });
        } else {
            AuditEvent::created(function ($audit) use ($kind, $actor, $versions): void {
                if ($audit->subject_id !== $versions[1]->id) {
                    return;
                }
                if (in_array($kind, ['earlier', 'noop', 'template'], true)) {
                    DB::table($kind === 'template' ? 'license_templates' : 'license_versions')
                        ->where('id', $kind === 'template' ? $versions[0]->license_template_id : $versions[0]->id)
                        ->update($kind === 'template' ? ['name' => 'Synthetic observer drift'] : ['authored_source' => 'Synthetic observer drift']);
                } else {
                    DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'synthetic.observer',
                        'subject_type' => $kind === 'template-audit' ? LicenseTemplate::class : LicenseVersion::class,
                        'subject_id' => $kind === 'template-audit' ? $versions[0]->license_template_id : $versions[0]->id,
                        'context' => '{}', 'created_at' => now()]);
                }
            });
        }
        $this->refused(fn () => $command->applyReviewed($review, $actor));
    }

    public function test_later_audit_failure_rolls_back_earlier_draft_and_author_updates(): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true);
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, Fixtures::replacement(), $actor);
        $before = $this->evidence();
        AuditEvent::creating(function ($audit) use ($versions): void {
            if ($audit->subject_id === $versions[1]->id) {
                throw new RuntimeException('Synthetic final batch audit outage');
            }
        });
        try {
            $command->applyReviewed($review, $actor);
            $this->fail('A later failed audit allowed earlier edits to commit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic final batch audit outage', $exception->getMessage());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame($review, $command->review($versions, Fixtures::replacement(), $actor));
    }

    public static function finalProofObservers(): array
    {
        return ['later version changes earlier row' => ['apply', 'version-row'],
            'later version changes no-op row' => ['apply', 'version-noop'],
            'later version changes template' => ['apply', 'version-template'],
            'later version changes earlier actual audit' => ['apply', 'version-audit'],
            'later actual audit retrieval changes earlier row' => ['apply', 'audit-row'],
            'final actor retrieval changes earlier row' => ['apply', 'actor-row'],
            'final actor retrieval withdraws role' => ['apply', 'actor-role'],
            'capture final version changes earlier row' => ['review', 'capture-row'],
            'capture final actor changes earlier row' => ['review', 'actor-row'],
            'capture final actor withdraws role' => ['review', 'actor-role']];
    }

    #[DataProvider('finalProofObservers')]
    public function test_final_observer_free_batch_proof_follows_all_model_and_authority_callbacks(string $operation, string $kind): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true);
        $source = Fixtures::replacement();
        if ($kind === 'version-noop') {
            app(UpdateLicenseDraft::class)->handle($versions[0], array_replace(Fixtures::content(), ['authored_source' => $source]), $actor);
        }
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review($versions, $source, $actor);
        $seen = [];
        $change = function () use ($kind, $actor, $versions): void {
            if ($kind === 'actor-role') {
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            } elseif ($kind === 'version-template') {
                DB::table('license_templates')->where('id', $versions[0]->license_template_id)->update(['name' => 'Synthetic late proof drift']);
            } elseif ($kind === 'version-audit') {
                $id = DB::table('audit_events')->where('action', 'rights.license.draft_source_bulk_updated')
                    ->where('subject_id', $versions[0]->id)->value('id');
                DB::table('audit_events')->where('id', $id)->update(['context' => '{"synthetic":true}']);
            } else {
                DB::table('license_versions')->where('id', $versions[0]->id)->update(['authored_source' => 'SYNTHETIC LATE FINAL-PROOF OBSERVER']);
            }
        };
        if (str_starts_with($kind, 'actor-')) {
            User::retrieved(function ($user) use (&$seen, $actor, $change): void {
                $seen[$user->id] = ($seen[$user->id] ?? 0) + 1;
                // Initial actor/Gate/MFA reads precede resources; the fourth is the final actor read.
                if ($user->id === $actor->id && $seen[$user->id] === 4) {
                    $change();
                }
            });
        } elseif ($kind === 'audit-row') {
            AuditEvent::retrieved(function ($audit) use ($versions, $change): void {
                if ($audit->action === 'rights.license.draft_source_bulk_updated' && $audit->subject_id === $versions[1]->id) {
                    $change();
                }
            });
        } else {
            LicenseVersion::retrieved(function ($version) use (&$seen, $versions, $operation, $change): void {
                $seen[$version->id] = ($seen[$version->id] ?? 0) + 1;
                if ($version->id === $versions[1]->id && $seen[$version->id] === ($operation === 'review' ? 2 : 3)) {
                    $change();
                }
            });
        }
        $before = $this->evidence();
        try {
            $operation === 'review' ? $command->review($versions, $source, $actor) : $command->applyReviewed($review, $actor);
            $this->fail('A later Eloquent callback invalidated an earlier proof and returned a successful batch.');
        } catch (AuthorizationException) {
            $this->assertSame('actor-role', $kind);
        } catch (ValidationException $error) {
            $this->assertNotSame('actor-role', $kind);
            $this->assertArrayHasKey('licenses', $error->errors());
        }
        $this->assertSame($before, $this->evidence());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_every_parent_and_version_fence_precedes_audit_baselines_and_caller_transactions_are_refused(): void
    {
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, true);
        $versions[] = Fixtures::drafts(1, actor: $actor)['versions'][0];
        $trace = [];
        $active = true;
        DB::listen(function ($query) use (&$trace, &$active): void {
            if ($active && preg_match('/\Aselect\b/i', $query->sql)) {
                $trace[] = [$query->sql, $query->bindings, DB::transactionLevel()];
            }
        });
        $command = app(BulkReplaceLicenseDraftSource::class);
        $review = $command->review(array_reverse($versions), Fixtures::replacement(), $actor);
        $active = false;
        $firstAudit = array_find_key($trace, fn ($row) => str_contains($row[0], 'audit_events'));
        $resource = array_values(array_filter(array_slice($trace, 0, $firstAudit), fn ($row) => str_contains($row[0], 'license_templates') || str_contains($row[0], 'license_versions')));
        $this->assertCount(5, $resource);
        $this->assertStringContainsString('license_templates', $resource[0][0]);
        $this->assertStringContainsString('license_templates', $resource[1][0]);
        $this->assertSame(array_column($versions, 'id'), array_map(fn ($row) => $row[1][0], array_slice($resource, 2)));
        foreach ($resource as [$sql, $bindings, $level]) {
            $this->assertSame(1, $level);
            if (DB::getDriverName() === 'mysql') {
                $this->assertStringContainsString('for update', $sql);
            }
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        DB::beginTransaction();
        try {
            foreach ([fn () => $command->review($versions, Fixtures::replacement(), $actor), fn () => $command->applyReviewed($review, $actor)] as $operation) {
                $start = $queries;
                try {
                    $operation();
                    $this->fail('Bulk review inherited a caller-owned snapshot.');
                } catch (LogicException) {
                    $this->assertSame($start, $queries);
                    $this->assertSame(1, DB::transactionLevel());
                }
            }
            app(UpdateLicenseDraft::class)->handle($versions[0], array_replace(Fixtures::content(), ['authored_source' => 'Synthetic nested legacy edit']), $actor);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }

    public function test_bulk_drafts_preserve_real_purchased_original_and_published_license_graphs(): void
    {
        DeliveryFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        app()->instance(StripeCheckoutGateway::class, $gateway);
        app()->instance(StripePaymentGateway::class, $gateway);
        app()->instance(ContractRenderer::class, ContractFixtures::renderer());
        $paid = DeliveryFixtures::ready($gateway);
        ['actor' => $actor, 'versions' => $versions] = Fixtures::drafts(2, actor: $paid['actor']);
        $historical = DeliveryFixtures::retained();
        $rows = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['offer_revisions', 'offers', 'license_review_evidence']);
        $published = LicenseVersion::findOrFail($paid['offer']->fresh()->currentRevision->license_version_id);
        $original = $published->fresh()->getAttributes();
        $this->assertNotEmpty(DB::table('license_grants')->get());
        $this->assertNotEmpty(DB::table('grant_contracts')->get());
        $command = app(BulkReplaceLicenseDraftSource::class);
        $command->applyReviewed($command->review($versions, Fixtures::replacement(), $actor), $actor);
        $this->assertSame($historical, DeliveryFixtures::retained());
        $this->assertSame($original, $published->fresh()->getAttributes());
        $this->assertSame($rows, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['offer_revisions', 'offers', 'license_review_evidence']));
        $this->refused(fn () => $command->review([$versions[0], $published], Fixtures::replacement(), $actor));
    }
}
