<?php

namespace Tests\Feature;

use App\Domain\Rights\BulkReplaceLicenseDraftSource;
use App\Domain\Rights\ReviewedLicenseDraft;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Filament\Resources\LicenseTemplateResource;
use App\Filament\Resources\LicenseVersionResource\Pages\ManageLicenseVersions;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
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

class BulkLicenseDraftSourceAuthoringActionTest extends TestCase
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
        $first = LicenseFixtures::draft($actor, content: ['authored_source' => 'NONBINDING first source']);
        $second = LicenseFixtures::draft($actor, content: ['authored_source' => 'NONBINDING second source',
            'effective_from' => '2030-01-02 03:04:05', 'effective_until' => '2031-01-02 03:04:05']);
        $unselected = LicenseFixtures::draft($actor, content: ['authored_source' => 'NONBINDING unselected source']);
        $this->actingAs($actor);

        return compact('actor', 'first', 'second', 'unselected');
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['license_templates', 'license_versions', 'license_review_evidence', 'offers', 'offer_revisions', 'audit_events']);
    }

    private function input(array $drafts): mixed
    {
        return Livewire::test(ManageLicenseVersions::class)->set('tableRecordsPerPage', 25)
            ->mountTableBulkAction('replaceDraftSource', $drafts);
    }

    private function preview(array $drafts, string $source = 'NONBINDING replacement source'): mixed
    {
        return $this->input($drafts)->setTableBulkActionData(['authored_source' => $source])
            ->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('reviewBulkSource');
    }

    private function assertConsumed(mixed $page): void
    {
        $page->assertSet('bulkSourceReview', null)->assertSet('bulkSourceContext', null)
            ->assertSet('bulkSourceInputContext', null);
    }

    private function assertRetained(mixed $page, string $source): void
    {
        $this->assertConsumed($page);
        $page->assertActionDataSet(['authored_source' => $source])->assertSet('bulkAuthoredSource', $source)
            ->assertDispatched('form-validation-error', livewireId: $page->instance()->getId());
        if ($page->get('mountedActions.0.name') === 'reviewBulkSource') {
            $this->assertTrue($page->instance()->getMountedAction()->isDisabled());
            $document = new \DOMDocument;
            @$document->loadHTML($page->getMountedActionModalHtml());
            $xpath = new \DOMXPath($document);
            $copy = $xpath->query('//textarea[@readonly]');
            $this->assertCount(1, $copy);
            $this->assertFalse($copy->item(0)->hasAttribute('disabled'));
            $submit = $xpath->query('//form[@*[name()="wire:submit.prevent"]="callMountedAction"]//button[@type="submit"]');
            $this->assertCount(1, $submit);
            $this->assertTrue($submit->item(0)->hasAttribute('disabled'));
        }
        $this->assertStringContainsString('Keep a copy', $page->getMountedActionModalHtml());
    }

    public function test_actual_preview_is_read_only_and_apply_changes_only_selected_source_with_complete_actor_audits(): void
    {
        ['actor' => $actor, 'first' => $first, 'second' => $second, 'unselected' => $unselected] = $this->pending();
        $before = $this->evidence();
        $original = [$first->fresh()->getAttributes(), $second->fresh()->getAttributes(), $unselected->fresh()->getAttributes()];
        $page = $this->preview([$second, $first])->assertSet('bulkSourceReview.actor_id', $actor->id)
            ->assertSee('2 drafts will change; 0 already match this source.')
            ->assertSee('NONBINDING first source')->assertSee('NONBINDING second source');
        $review = $page->get('bulkSourceReview');
        $this->assertSame([$first->id, $second->id], array_column($review['drafts'], 'version_id'));
        $this->assertSame($before, $this->evidence());
        $this->assertSame($review['drafts'][1]['before']['structured_terms'], $review['drafts'][1]['after']['structured_terms']);
        $this->assertSame('2030-01-02 03:04:05', $review['drafts'][1]['after']['effective_from']);
        $page->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified('Source saved for 2 drafts. 0 drafts already matched.');
        $this->assertConsumed($page);
        foreach ([$first, $second] as $index => $draft) {
            $saved = $draft->fresh()->getAttributes();
            $this->assertSame('NONBINDING replacement source', $saved['authored_source']);
            foreach (['authored_source', 'author_id', 'content_author_ids', 'updated_at'] as $key) {
                unset($saved[$key], $original[$index][$key]);
            }
            $this->assertSame(CanonicalJson::encode($original[$index]), CanonicalJson::encode($saved));
        }
        $this->assertSame($original[2], $unselected->fresh()->getAttributes());
        $events = AuditEvent::where('action', 'rights.license.draft_source_bulk_updated')->orderBy('subject_id')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$first->id, $second->id], $events->pluck('subject_id')->all());
        foreach ($events as $event) {
            $this->assertSame($actor->id, $event->actor_id);
            $this->assertSame(['authored_source'], $event->context['changed_fields']);
            $this->assertSame(CanonicalJson::hash($review), $event->context['batch_review_hash']);
        }
        $this->assertDatabaseCount('license_review_evidence', 0);
        $this->assertDatabaseCount('offers', 0);
        $page->assertDispatched('deselectAllTableRecords');
    }

    public function test_no_op_reuses_current_values_without_writes_or_audits(): void
    {
        ['first' => $first] = $this->pending();
        $before = $this->evidence();
        $page = $this->preview([$first], $first->authored_source)->assertSee('0 drafts will change; 1 already match this source.')
            ->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified('No draft source changed. 1 reviewed drafts already matched.');
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_back_and_cancel_consume_comparison_without_recapture_and_retain_entered_text(): void
    {
        ['first' => $first, 'second' => $second] = $this->pending();
        $before = $this->evidence();
        $page = $this->preview([$first, $second])->mountAction('backToBulkSource')->assertSet('mountedActions.0.name', 'replaceDraftSource')
            ->assertSet('mountedActions.0.context', ['table' => true, 'bulk' => true])
            ->assertActionDataSet(['authored_source' => 'NONBINDING replacement source'])->assertSet('bulkSourceReview', null);
        $page->setActionData(['authored_source' => 'NONBINDING changed after Back'])->callMountedAction()
            ->assertHasNoActionErrors()->assertActionMounted('reviewBulkSource');
        $page->unmountAction();
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_required_input_validation_consumes_mount_before_framework_validation_and_keeps_text(): void
    {
        ['first' => $first] = $this->pending();
        $before = $this->evidence();
        $page = $this->input([$first])->setActionData(['authored_source' => ''])->callMountedAction()
            ->assertHasActionErrors(['authored_source']);
        $this->assertRetained($page, '');
        $page->setActionData(['authored_source' => 'Corrected without reopening'])->callMountedAction()
            ->assertHasActionErrors(['authored_source'])->assertNotified('Review current drafts to continue');
        $this->assertRetained($page, 'Corrected without reopening');
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableBulkAction('replaceDraftSource', [$first])->callMountedAction()
            ->assertHasNoActionErrors()->assertActionMounted('reviewBulkSource');
    }

    public function test_domain_validation_keeps_replacement_text_and_never_silently_changes_retained_economic_terms(): void
    {
        ['actor' => $actor, 'first' => $first] = $this->pending();
        $economic = EconomicLicenseFixtures::draft($actor);
        $before = $this->evidence();
        $source = 'NONBINDING source missing required economic variables';
        $page = $this->input([$first, $economic])->setActionData(['authored_source' => $source])->callMountedAction()
            ->assertHasActionErrors(['authored_source'])->assertNotified('Review current drafts to continue');
        $this->assertRetained($page, $source);
        $this->assertSame($before, $this->evidence());
    }

    public function test_competing_real_editor_save_rejects_whole_batch_and_retains_losing_source_until_fresh_reopen(): void
    {
        ['first' => $first, 'second' => $second] = $this->pending();
        $page = $this->preview([$first, $second]);
        Livewire::test(ManageLicenseVersions::class)->mountTableAction('edit', $second)
            ->setTableActionData(array_replace($second->only(ReviewedLicenseDraft::FIELDS), ['authored_source' => 'Other editor won']))
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
        $before = $this->evidence();
        $page->callMountedAction()->assertHasActionErrors(['authored_source'])->assertNotified('Review current drafts to continue');
        $this->assertRetained($page, 'NONBINDING replacement source');
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertSame($before, $this->evidence());
        $page->mountAction('backToBulkSource')->assertSet('mountedActions.0.name', 'replaceDraftSource')
            ->assertActionDataSet(['authored_source' => 'NONBINDING replacement source'])->callMountedAction()
            ->assertHasNoActionErrors()->assertSee('2 drafts will change; 0 already match this source.');
        $this->assertSame($before, $this->evidence());
        $page->unmountAction()->mountTableBulkAction('replaceDraftSource', [$first, $second])->callMountedAction()
            ->assertHasNoActionErrors()->assertSee('Other editor won')->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('NONBINDING replacement source', $first->fresh()->authored_source);
        $this->assertSame('NONBINDING replacement source', $second->fresh()->authored_source);
    }

    public function test_same_second_aba_or_review_submission_after_comparison_cannot_apply_any_selected_draft(): void
    {
        ['actor' => $actor, 'first' => $first, 'second' => $second] = $this->pending();
        $this->freezeSecond();
        $page = $this->preview([$first, $second]);
        $old = $second->only(ReviewedLicenseDraft::FIELDS);
        app(UpdateLicenseDraft::class)->handle($second, array_replace($old, ['authored_source' => 'Intervening source']), $actor);
        app(UpdateLicenseDraft::class)->handle($second, $old, $actor);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertRetained($page, 'NONBINDING replacement source');
        $this->assertSame($before, $this->evidence());
        $page = $this->preview([$first, $second]);
        app(ReviewLicense::class)->submit($second->fresh(), $actor);
        $before = $this->evidence();
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertRetained($page, 'NONBINDING replacement source');
        $this->assertSame($before, $this->evidence());
    }

    public static function contextChanges(): array
    {
        return [
            'selection' => ['selectedTableRecords', ['1']], 'deselection' => ['deselectedTableRecords', ['1']],
            'select all pages' => ['isTrackingDeselectedTableRecords', true],
            'search' => ['tableSearch', 'different'], 'column search' => ['tableColumnSearches', ['version' => '2']],
            'filters' => ['tableFilters', ['synthetic' => ['value' => 'draft']]],
            'deferred filters' => ['tableDeferredFilters', ['synthetic' => ['value' => 'draft']]],
            'sort' => ['tableSort', 'version:asc'], 'page size' => ['tableRecordsPerPage', 5],
            'action arguments' => ['mountedActions.0.arguments.unreviewed', true],
            'action context' => ['mountedActions.0.context.unreviewed', true],
            'replacement source' => ['mountedActions.0.data.authored_source', 'Tampered after review'],
            'hidden field' => ['mountedActions.0.data.published_at', '2026-01-01'],
        ];
    }

    #[DataProvider('contextChanges')]
    public function test_each_selection_form_or_table_context_change_consumes_comparison_and_cannot_apply(string $property, mixed $value): void
    {
        ['first' => $first, 'second' => $second] = $this->pending();
        $before = $this->evidence();
        $page = $this->preview([$first, $second])->set($property, $value);
        $this->assertConsumed($page);
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertSame($before, $this->evidence());
    }

    public function test_pagination_actor_arguments_and_action_substitution_cannot_reuse_comparison(): void
    {
        ['first' => $first, 'second' => $second] = $this->pending();
        $before = $this->evidence();
        $page = $this->preview([$first, $second]);
        $page->call('gotoPage', 2, $page->instance()->getTablePaginationPageName());
        $this->assertConsumed($page);
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $page = $this->preview([$first, $second])->callMountedAction(['unexpected' => true])->assertHasActionErrors(['authored_source']);
        $this->assertConsumed($page);
        $page = $this->preview([$first, $second]);
        $other = LicenseFixtures::admin();
        $other->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($other);
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertConsumed($page);
        $page = $this->preview([$first, $second])->set('mountedActions.0.name', 'missingAction')->callMountedAction();
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_locked_capture_and_direct_input_review_apply_back_callbacks_cannot_bypass_actual_lifecycle(): void
    {
        ['first' => $first] = $this->pending();
        $before = $this->evidence();
        foreach (['bulkSourceReview.actor_id' => 999, 'bulkSourceReview.drafts.0.version_id' => 999,
            'bulkSourceReview.drafts.0.after.authored_source' => 'Forged', 'bulkSourceContext.table.page' => '2',
            'bulkSourceInputContext.actor_id' => 999, 'bulkAuthoredSource' => 'Forged'] as $property => $value) {
            try {
                $this->preview([$first])->set($property, $value);
                $this->fail('Client changed locked bulk evidence.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
        $component = $this->preview([$first])->instance();
        $action = $component->getMountedAction();
        foreach ([fn () => $component->captureBulkSourceInput(),
            fn () => $component->reviewBulkSource(['authored_source' => 'Unreviewed'], $action),
            fn () => $component->applyBulkSource($action), fn () => $component->backToBulkSource()] as $operation) {
            try {
                $operation();
                $this->fail('Direct callback bypassed bulk lifecycle.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        Livewire::test(ManageLicenseVersions::class)->call('mountAction', 'reviewBulkSource')->assertForbidden();
        $this->assertSame($before, $this->evidence());
    }

    public static function invalidSelections(): array
    {
        return ['empty' => [[]], 'duplicate' => [['1', '1']], 'leading zero' => [['01']], 'float' => [[1.5]],
            'negative' => [[-1]], 'zero' => [['0']], 'overflow' => [['999999999999999999999999']],
            'nested' => [[[1]]], 'associative' => [['chosen' => '1']], 'over cap' => [range(1, 26)]];
    }

    #[DataProvider('invalidSelections')]
    public function test_malformed_or_unbounded_selection_never_captures_or_changes_drafts(array $selection): void
    {
        $this->pending();
        $before = $this->evidence();
        $page = Livewire::test(ManageLicenseVersions::class)->set('selectedTableRecords', $selection)
            ->call('mountAction', 'replaceDraftSource', [], ['table' => true, 'bulk' => true])->assertHasErrors();
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public function test_off_page_filtered_non_draft_and_select_all_modes_never_capture_or_apply(): void
    {
        ['actor' => $actor, 'first' => $first, 'second' => $second] = $this->pending();
        $published = LicenseFixtures::published($actor);
        $before = $this->evidence();
        $page = Livewire::test(ManageLicenseVersions::class)->set('isTrackingDeselectedTableRecords', true)
            ->mountTableBulkAction('replaceDraftSource', [$first])->assertHasErrors();
        $this->assertConsumed($page);
        $page = Livewire::test(ManageLicenseVersions::class)->set('tableSearch', 'no matching license template')
            ->mountTableBulkAction('replaceDraftSource', [$first])->assertHasErrors();
        $this->assertConsumed($page);
        for ($i = 0; $i < 6; $i++) {
            LicenseFixtures::draft($actor);
        }
        $before = $this->evidence();
        $page = Livewire::test(ManageLicenseVersions::class)->set('tableRecordsPerPage', 5)
            ->mountTableBulkAction('replaceDraftSource', [$first])->assertHasErrors();
        $this->assertConsumed($page);
        $page = $this->input([$second, $published])->setActionData(['authored_source' => 'NONBINDING replacement'])
            ->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertConsumed($page);
        $this->assertSame($before, $this->evidence());
    }

    public static function revocations(): array
    {
        return ['role' => ['is_admin', false], 'email' => ['email_verified_at', null], 'MFA' => ['app_authentication_secret', null]];
    }

    #[DataProvider('revocations')]
    public function test_fresh_authority_is_required_for_both_preview_and_apply(string $field, mixed $value): void
    {
        ['actor' => $actor, 'first' => $first] = $this->pending();
        $panel = Filament::getPanel('admin');
        $required = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $input = $this->input([$first])->setActionData(['authored_source' => 'NONBINDING replacement']);
            $comparison = $this->preview([$first]);
            DB::table('users')->where('id', $actor->id)->update([$field => $value]);
            $before = $this->evidence();
            $input->callMountedAction()->assertForbidden();
            $comparison->callMountedAction()->assertForbidden();
            $this->assertSame($before, $this->evidence());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
        }
    }

    public function test_uncertain_failure_retains_copyable_source_and_logs_only_exception_class_without_false_success(): void
    {
        ['first' => $first, 'second' => $second] = $this->pending();
        $page = $this->preview([$first, $second]);
        $before = $this->evidence();
        Log::spy();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic private license failure details'));
        $page->callMountedAction()->assertHasActionErrors(['authored_source'])
            ->assertNotified('The bulk save result could not be confirmed.');
        $this->assertRetained($page, 'NONBINDING replacement source');
        $this->assertSame($before, $this->evidence());
        Log::shouldHaveReceived('warning')->once()->with('Bulk license draft UI could not confirm the result.', ['exception_class' => RuntimeException::class]);
        $sent = session('filament.claimed_notifications') ?? session('filament.notifications');
        $this->assertStringNotContainsString('Synthetic private license failure details', json_encode($sent, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Source saved for', json_encode($sent, JSON_THROW_ON_ERROR));
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertSame($before, $this->evidence());
        $page->mountAction('backToBulkSource')->assertSet('mountedActions.0.name', 'replaceDraftSource')
            ->assertActionDataSet(['authored_source' => 'NONBINDING replacement source'])->callMountedAction()
            ->assertHasNoActionErrors()->assertSee('2 drafts will change; 0 already match this source.');
        $this->assertSame($before, $this->evidence());
    }

    public function test_exception_after_real_commit_cannot_retry_consumed_review_and_reopening_inspects_durable_drafts(): void
    {
        ['first' => $first, 'second' => $second] = $this->pending();
        $real = app(BulkReplaceLicenseDraftSource::class);
        $this->app->instance(BulkReplaceLicenseDraftSource::class, new class($real)
        {
            public function __construct(private BulkReplaceLicenseDraftSource $real) {}

            public function review(array $versions, string $source, $actor): array
            {
                return $this->real->review($versions, $source, $actor);
            }

            public function applyReviewed(array $review, $actor): never
            {
                $this->real->applyReviewed($review, $actor);
                throw new RuntimeException('Synthetic failure after durable bulk commit');
            }
        });
        $page = $this->preview([$first, $second])->callMountedAction()->assertHasActionErrors(['authored_source'])
            ->assertNotified('The bulk save result could not be confirmed.');
        $this->assertRetained($page, 'NONBINDING replacement source');
        $this->assertSame('NONBINDING replacement source', $first->fresh()->authored_source);
        $this->assertSame('NONBINDING replacement source', $second->fresh()->authored_source);
        $this->assertSame(2, AuditEvent::where('action', 'rights.license.draft_source_bulk_updated')->count());
        $before = $this->evidence();
        $page->callMountedAction()->assertHasActionErrors(['authored_source']);
        $this->assertSame($before, $this->evidence());
        $page->mountAction('backToBulkSource')->assertSet('mountedActions.0.name', 'replaceDraftSource')
            ->assertActionDataSet(['authored_source' => 'NONBINDING replacement source'])->callMountedAction()
            ->assertHasNoActionErrors()->assertSee('0 drafts will change; 2 already match this source.');
        $this->assertSame($before, $this->evidence());
    }

    public function test_rendered_comparison_escapes_source_and_template_and_preserves_copy_focus_and_native_submit_controls(): void
    {
        ['first' => $first] = $this->pending();
        $name = '<script>synthetic-template</script>';
        DB::table('license_templates')->where('id', $first->license_template_id)->update(['name' => $name]);
        $source = "NONBINDING literal </textarea><script>synthetic-source</script>\n".str_repeat('longword', 80);
        $page = $this->preview([$first], $source);
        // Preview replacement forces a complete render; error recovery emits a modal partial.
        $html = $page->effects['partials']['action-modals.0'] ?? $page->effects['partials']['action-modals'] ?? $page->html();
        $this->assertStringNotContainsString('<script>synthetic-template</script>', $html);
        $this->assertStringNotContainsString('<script>synthetic-source</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;synthetic-template&lt;/script&gt;', $html);
        $this->assertStringContainsString('repeat(auto-fit, minmax(min(100%, 16rem), 1fr))', $html);
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[@*[name()="wire:submit.prevent"]="callMountedAction"]');
        $this->assertCount(1, $forms);
        $textarea = $xpath->query('.//textarea[@readonly]', $forms->item(0));
        $this->assertCount(1, $textarea);
        $this->assertFalse($textarea->item(0)->hasAttribute('disabled'));
        // Filament binds the native textarea through Alpine rather than initial text nodes.
        $this->assertSame('state', $textarea->item(0)->getAttribute('x-model'));
        $page->assertActionDataSet(['authored_source' => $source]);
        $submit = $xpath->query('.//button[@type="submit"]', $forms->item(0));
        $this->assertCount(1, $submit);
        $this->assertFalse($submit->item(0)->hasAttribute('wire:loading.attr'));
        $this->assertFalse($submit->item(0)->hasAttribute('disabled'));
        $this->assertStringContainsString('isProcessing', $submit->item(0)->getAttribute('x-bind:disabled'));
        $modal = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-modal-window ")][@*[name()="x-on:form-validation-error.window"]]');
        $this->assertCount(1, $modal);
        $this->assertSame(LicenseTemplateResource::authoringModalAttributes()['x-on:form-validation-error.window'], $modal->item(0)->getAttribute('x-on:form-validation-error.window'));
    }
}
