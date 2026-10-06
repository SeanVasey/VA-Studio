<?php

namespace Tests\Feature;

use App\Domain\Rights\ReviewedLicenseDraft;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Filament\Resources\LicenseTemplateResource;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class LicenseDraftAuthoringActionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function pending(): array
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $draft = LicenseFixtures::draft($actor);
        $data = $draft->only(ReviewedLicenseDraft::FIELDS);
        $this->actingAs($actor);

        return compact('actor', 'draft', 'data');
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['license_templates', 'license_versions', 'license_review_evidence', 'offers', 'offer_revisions', 'audit_events']);
    }

    private function assertConsumed(mixed $page): void
    {
        $page->assertSet('draftReview', null)->assertSet('draftReviewContext', null);
    }

    private function assertRecovery(mixed $page, string $source): void
    {
        $this->assertConsumed($page);
        $page->assertTableActionDataSet(['authored_source' => $source])
            ->assertDispatched('form-validation-error', livewireId: $page->instance()->getId());
        $document = new \DOMDocument;
        @$document->loadHTML($page->effects['partials']['action-modals.0']);
        $xpath = new \DOMXPath($document);
        $modal = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-modal-window ")][@*[name() = "x-on:form-validation-error.window"]]');
        $this->assertCount(1, $modal);
        $this->assertSame(LicenseTemplateResource::authoringModalAttributes()['x-on:form-validation-error.window'],
            $modal->item(0)->getAttribute('x-on:form-validation-error.window'));
        $this->assertGreaterThanOrEqual(1, $xpath->query('.//*[@data-validation-error]', $modal->item(0))->length);
    }

    public function test_actual_edit_captures_original_draft_and_saves_only_reviewed_fields_with_actor_audit(): void
    {
        ['actor' => $actor, 'draft' => $draft, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)
            ->assertTableActionDataSet(['authored_source' => $data['authored_source'], 'structured_terms.schema_version' => 1, 'structured_terms.features' => $data['structured_terms']['features'], 'structured_terms.required_asset_roles' => $data['structured_terms']['required_asset_roles']])->assertSet('draftReview.actor_id', $actor->id)
            ->assertSet('draftReview.version_id', $draft->id)->assertSet('draftReview.template_id', $draft->license_template_id);
        $review = $page->get('draftReview');
        $changed = array_replace($data, ['authored_source' => 'NONBINDING changed source', 'effective_from' => '2030-01-02 03:04:05', 'effective_until' => '2031-01-02 03:04:05']);
        $page->setTableActionData($changed)->assertSet('draftReview', $review)
            ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Saved');
        $this->assertConsumed($page);
        $saved = $draft->fresh();
        $this->assertSame($changed['authored_source'], $saved->authored_source);
        $this->assertSame(CanonicalJson::encode($data['structured_terms']), CanonicalJson::encode($saved->structured_terms));
        $this->assertSame($changed['effective_from'], $saved->effective_from->format('Y-m-d H:i:s'));
        $this->assertSame($changed['effective_until'], $saved->effective_until->format('Y-m-d H:i:s'));
        $this->assertSame('draft', $saved->status);
        $this->assertNull($saved->published_at);
        $audit = AuditEvent::where('action', 'rights.license.draft_updated')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame((string) $draft->id, (string) $audit->subject_id);
        $this->assertDatabaseCount('license_review_evidence', 0);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_invalid_form_keeps_inputs_and_consumes_review_until_explicit_reopen(): void
    {
        ['draft' => $draft, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)
            ->setTableActionData(array_replace($data, ['authored_source' => '']))
            ->callMountedTableAction()->assertHasTableActionErrors(['authored_source']);
        $this->assertRecovery($page, '');
        $page->setTableActionData(array_replace($data, ['authored_source' => 'Corrected but not reopened']))
            ->callMountedTableAction()->assertHasTableActionErrors(['authored_source'])->assertNotified('Reopen draft to continue');
        $this->assertRecovery($page, 'Corrected but not reopened');
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $draft)->assertTableActionDataSet(['authored_source' => $data['authored_source'], 'structured_terms.schema_version' => 1, 'structured_terms.features' => $data['structured_terms']['features'], 'structured_terms.required_asset_roles' => $data['structured_terms']['required_asset_roles']])
            ->setTableActionData(array_replace($data, ['authored_source' => 'Corrected after reopening']))
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Corrected after reopening', $draft->fresh()->authored_source);
    }

    public function test_real_competing_save_preserves_losing_editor_values_and_requires_current_review(): void
    {
        ['actor' => $actor, 'draft' => $draft, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $captured = $page->get('draftReview');
        $other = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)
            ->setTableActionData(array_replace($data, ['authored_source' => 'Other editor saved source']))
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertConsumed($other);
        $before = $this->evidence();
        $losing = array_replace($data, ['authored_source' => 'Unsaved local source', 'effective_from' => '2030-02-03 04:05:06']);
        $page->assertSet('draftReview', $captured)->setTableActionData($losing)
            ->callMountedTableAction()->assertHasTableActionErrors(['authored_source'])->assertNotified('Reopen draft to continue');
        $this->assertRecovery($page, $losing['authored_source']);
        $page->assertTableActionDataSet(['authored_source' => $losing['authored_source'], 'effective_from' => $losing['effective_from']])->callMountedTableAction()->assertHasTableActionErrors(['authored_source']);
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $draft)->assertTableActionDataSet(['authored_source' => 'Other editor saved source'])
            ->setTableActionData($losing)->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Unsaved local source', $draft->fresh()->authored_source);
    }

    public function test_same_second_aba_change_and_restore_cannot_reuse_mounted_baseline(): void
    {
        ['actor' => $actor, 'draft' => $draft, 'data' => $data] = $this->pending();
        $this->freezeSecond();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $command = app(UpdateLicenseDraft::class);
        $command->handle($draft, array_replace($data, ['authored_source' => 'Intervening source']), $actor);
        $command->handle($draft, $data, $actor);
        $before = $this->evidence();
        $page->setTableActionData(array_replace($data, ['authored_source' => 'Old tab source']))
            ->callMountedTableAction()->assertHasTableActionErrors(['authored_source']);
        $this->assertRecovery($page, 'Old tab source');
        $this->assertSame($before, $this->evidence());
    }

    public function test_review_submission_after_mount_keeps_inputs_and_cannot_modify_frozen_version(): void
    {
        ['actor' => $actor, 'draft' => $draft, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)
            ->setTableActionData(array_replace($data, ['authored_source' => 'Local text after review started']));
        app(ReviewLicense::class)->submit($draft, $actor);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasTableActionErrors(['authored_source']);
        $this->assertRecovery($page, 'Local text after review started');
        $this->assertSame($before, $this->evidence());
        Livewire::test(ManageLicenseVersions::class)->assertTableActionHidden('edit', $draft->fresh());
    }

    public static function revocations(): array
    {
        return ['role' => ['is_admin', false], 'email' => ['email_verified_at', null], 'MFA' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_current_operator_authority_is_required_after_mount(string $field, mixed $value): void
    {
        ['actor' => $actor, 'draft' => $draft, 'data' => $data] = $this->pending();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)->setTableActionData($data);
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $page->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_another_authorized_actor_cannot_submit_the_original_mounted_review(): void
    {
        ['draft' => $draft] = $this->pending();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $other = LicenseFixtures::admin();
        $other->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($other);
        $before = $this->evidence();
        $page->callMountedTableAction()->assertHasTableActionErrors(['authored_source']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_locked_baseline_and_direct_callbacks_cannot_forge_a_review_or_save(): void
    {
        ['draft' => $draft, 'data' => $data] = $this->pending();
        $before = $this->evidence();
        foreach (['draftReview.actor_id' => 999, 'draftReview.version_id' => 999, 'draftReview.version_hash' => str_repeat('a', 64),
            'draftReview.display.authored_source' => 'Forged', 'draftReviewContext.table.page' => '2'] as $property => $value) {
            try {
                Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)->set($property, $value);
                $this->fail('A client changed locked draft review evidence.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
        $component = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)->instance();
        $action = $component->getMountedAction();
        foreach ([fn () => $component->captureDraftReview($draft), fn () => $component->updateDraft($draft, $data, $action)] as $operation) {
            try {
                $operation();
                $this->fail('A direct callback bypassed real mounting or submission.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_action_record_table_context_and_unsupported_data_updates_consume_the_review(): void
    {
        ['actor' => $actor, 'draft' => $draft] = $this->pending();
        $other = LicenseFixtures::draft($actor);
        $mount = fn () => Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $before = $this->evidence();
        $this->assertConsumed($mount()->unmountAction());
        foreach (['mountedActions.0.context.recordKey' => (string) $other->id,
            'mountedActions.0.arguments.unreviewed' => true, 'mountedActions.0.data.published_at' => '2026-01-01',
            'mountedActions.0.data.license_template_id' => $other->license_template_id,
            'tableSearch' => 'changed', 'tableSort' => 'version:asc'] as $property => $value) {
            $page = $mount()->set($property, $value);
            $this->assertConsumed($page);
            if (str_starts_with($property, 'mountedActions.')) {
                $page->callMountedAction()->assertHasActionErrors(['authored_source']);
            }
            $this->assertSame($before, $this->evidence());
        }
        $page = $mount();
        $this->assertConsumed($page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName()));
        $page = $mount()->callMountedAction(['unexpected' => true])->assertHasTableActionErrors(['authored_source']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_exception_after_real_commit_keeps_inputs_and_reopening_reads_the_single_durable_save(): void
    {
        ['draft' => $draft, 'data' => $data] = $this->pending();
        $real = app(ReviewedLicenseDraft::class);
        $this->app->instance(ReviewedLicenseDraft::class, new class($real)
        {
            public function __construct(private ReviewedLicenseDraft $real) {}

            public function review($version, $actor): array
            {
                return $this->real->review($version, $actor);
            }

            public function updateReviewed(array $review, array $data, $actor): never
            {
                $this->real->updateReviewed($review, $data, $actor);
                throw new RuntimeException('Synthetic exception after durable commit');
            }
        });
        $source = 'NONBINDING source committed before the response failed';
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)
            ->setTableActionData(array_replace($data, ['authored_source' => $source]))
            ->callMountedAction()->assertHasTableActionErrors(['authored_source'])->assertNotified('The save result could not be confirmed.');
        $this->assertRecovery($page, $source);
        $this->assertSame($source, $draft->fresh()->authored_source);
        $this->assertSame(1, AuditEvent::where('action', 'rights.license.draft_updated')->count());
        $before = $this->evidence();
        $page->callMountedAction()->assertHasTableActionErrors(['authored_source'])->assertNotified('Reopen draft to continue');
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableAction('edit', $draft)->assertTableActionDataSet(['authored_source' => $source]);
        $this->assertSame($before, $this->evidence());
    }

    public function test_uncertain_failure_retains_inputs_without_success_or_exception_content_and_requires_reopen(): void
    {
        ['draft' => $draft, 'data' => $data] = $this->pending();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft)
            ->setTableActionData(array_replace($data, ['authored_source' => 'Unconfirmed local source']));
        $before = $this->evidence();
        Log::spy();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic private failure detail'));
        $page->callMountedAction()->assertHasTableActionErrors(['authored_source']);
        $sent = session('filament.claimed_notifications') ?? session('filament.notifications');
        $this->assertIsArray($sent);
        $this->assertCount(1, $sent);
        $page->assertNotified('The save result could not be confirmed.');
        $this->assertRecovery($page, 'Unconfirmed local source');
        $this->assertSame($before, $this->evidence());
        Log::shouldHaveReceived('warning')->once()->with('License draft UI could not confirm the result.', ['exception_class' => RuntimeException::class]);
        $notifications = json_encode($sent, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Synthetic private failure detail', $notifications);
        $this->assertStringNotContainsString('"title":"Saved"', $notifications);
        $page->callMountedAction()->assertHasTableActionErrors(['authored_source'])->assertNotified('Reopen draft to continue');
        $this->assertSame($before, $this->evidence());
    }

    public function test_real_economic_policy_add_delete_and_reorder_keep_the_original_review_until_save(): void
    {
        ['actor' => $actor] = $this->pending();
        $draft = EconomicLicenseFixtures::draft($actor);
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $review = $page->get('draftReview');
        $component = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('structured_terms.policies');
        $this->assertInstanceOf(Repeater::class, $component);
        $context = $component->getAction('add')->getContext();
        $initial = array_keys($page->get('mountedActions.0.data.structured_terms.policies'));
        $page->call('mountAction', 'add', [], $context)->assertSet('draftReview', $review);
        $rows = $page->get('mountedActions.0.data.structured_terms.policies');
        $this->assertCount(2, $rows);
        $added = array_values(array_diff(array_keys($rows), $initial))[0];
        $page->set('mountedActions.0.data.structured_terms.policies.'.$added, [
            'key' => 'synthetic-extra', 'version' => 'test-v1', 'text' => 'NONBINDING added policy for native editor verification.',
        ])->set('mountedActions.0.data.structured_terms.ownership.source_recording.policy_key', 'synthetic-extra');
        $page->call('mountAction', 'add', [], $context)->assertSet('draftReview', $review);
        $third = array_values(array_diff(array_keys($page->get('mountedActions.0.data.structured_terms.policies')), [$initial[0], $added]))[0];
        $page->call('mountAction', 'delete', ['item' => $third], $context)->assertSet('draftReview', $review);
        $page->call('mountAction', 'reorder', ['items' => [$added, $initial[0]]], $context)->assertSet('draftReview', $review);
        $this->assertSame([$added, $initial[0]], array_keys($page->get('mountedActions.0.data.structured_terms.policies')));
        $page->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Saved');
        $this->assertConsumed($page);
        $this->assertSame(['synthetic-extra', 'economic-fixture'], array_column($draft->fresh()->structured_terms['policies'], 'key'));
        $this->assertSame(1, AuditEvent::where('action', 'rights.license.draft_updated')->count());
    }

    public function test_policy_actions_cannot_preserve_a_review_with_foreign_context_or_unrecognized_arguments(): void
    {
        ['actor' => $actor] = $this->pending();
        $draft = EconomicLicenseFixtures::draft($actor);
        $before = $this->evidence();
        foreach (['foreign-record', 'unexpected-argument'] as $case) {
            $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
            $component = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('structured_terms.policies');
            $context = $component->getAction('add')->getContext();
            $arguments = [];
            if ($case === 'foreign-record') {
                $context['recordKey'] = '99999';
            } else {
                $arguments['unreviewed'] = true;
            }
            $page->call('mountAction', 'add', $arguments, $context);
            $this->assertConsumed($page);
            $page->callMountedAction()->assertHasTableActionErrors(['authored_source']);
            $this->assertSame($before, $this->evidence());
        }
    }

    public function test_rendered_edit_submit_retains_native_form_disabling_spinner_and_focus_recovery(): void
    {
        ['draft' => $draft] = $this->pending();
        $page = Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $draft);
        $document = new \DOMDocument;
        @$document->loadHTML($page->getMountedActionModalHtml());
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[@*[name()="wire:submit.prevent"]="callMountedAction"]');
        $this->assertCount(1, $forms);
        $submit = $xpath->query('.//button[@type="submit"]', $forms->item(0));
        $this->assertCount(1, $submit);
        $this->assertFalse($submit->item(0)->hasAttribute('wire:loading.attr'));
        $this->assertFalse($submit->item(0)->hasAttribute('disabled'));
        $this->assertGreaterThan(0, $xpath->query('.//*[@*[name()="wire:loading.delay.default"]]', $submit->item(0))->length);
        $this->assertStringContainsString('isProcessing', $submit->item(0)->getAttribute('x-bind:disabled'));
    }
}
