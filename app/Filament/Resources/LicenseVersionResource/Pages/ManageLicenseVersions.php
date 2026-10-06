<?php

namespace App\Filament\Resources\LicenseVersionResource\Pages;

use App\Domain\Rights\BulkReplaceLicenseDraftSource;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\ReviewedLicenseDraft;
use App\Filament\Resources\LicenseTemplateResource;
use App\Filament\Resources\LicenseVersionResource;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class ManageLicenseVersions extends ManageRecords
{
    protected static string $resource = LicenseVersionResource::class;

    #[Locked]
    public ?array $draftReview = null;

    #[Locked]
    public ?array $draftReviewContext = null;

    #[Locked]
    public ?array $bulkSourceReview = null;

    #[Locked]
    public ?array $bulkSourceContext = null;

    #[Locked]
    public ?array $bulkSourceInputContext = null;

    #[Locked]
    public string $bulkAuthoredSource = '';

    private bool $mountingBulkSource = false;

    private bool $transitioningBulkSource = false;

    private ?string $submittingBulkSource = null;

    private ?array $submittedBulkSourceReview = null;

    private ?array $submittedReview = null;

    private bool $mountingDraft = false;

    private bool $submittingDraft = false;

    private ?array $editingPolicyAction = null;

    private function draftActor(): User
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! AdminMultiFactor::satisfiedBy($actor)) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize('administer-catalog');

        return $actor;
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        if ($this->isPolicyFieldAction($name, $arguments, $context)) {
            // These modal-less framework actions only edit this captured form's policy rows.
            // Preserve the original review through this synchronous child lifecycle, never recapture it.
            $parentContext = $this->draftReviewContext;
            $this->editingPolicyAction = compact('name', 'arguments', 'context');
            try {
                return parent::mountAction($name, $arguments, $context);
            } catch (Throwable $exception) {
                $this->clearDraftReview();
                throw $exception;
            } finally {
                $this->editingPolicyAction = null;
                if ($parentContext !== $this->reviewContext()) {
                    $this->clearDraftReview();
                }
            }
        }
        if (! ($this->transitioningBulkSource && $name === 'reviewBulkSource' && $arguments === [] && $context === [])) {
            $this->clearBulkSourceReview();
        }
        $this->clearDraftReview();
        if ($name === 'reviewBulkSource') {
            abort_unless($this->transitioningBulkSource && $arguments === [] && $context === [], 403);
        }
        if ($name === 'replaceDraftSource') {
            $this->mountingBulkSource = true;
            try {
                return parent::mountAction($name, $arguments, $context);
            } finally {
                $this->mountingBulkSource = false;
            }
        }
        if ($name !== 'edit') {
            return parent::mountAction($name, $arguments, $context);
        }
        $this->mountingDraft = true;
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException) {
            $this->unmountAction();
            Notification::make()->danger()->title('Draft editor could not be opened')
                ->body('Reload license versions and inspect the current draft before opening the editor again.')->persistent()->send();

            return null;
        } catch (Throwable $exception) {
            $this->unmountAction();
            $this->logUncertain($exception);
            Notification::make()->danger()->title('Draft editor could not be opened')
                ->body('Reload license versions before opening the editor again.')->persistent()->send();

            return null;
        } finally {
            $this->mountingDraft = false;
        }
    }

    public function captureDraftReview(LicenseVersion $record): array
    {
        $action = $this->getMountedAction();
        abort_unless($this->mountingDraft && $action?->getName() === 'edit'
            && $action->getRecord() instanceof LicenseVersion && $action->getRecord()->getKey() === $record->getKey(), 403);
        $this->draftReview = app(ReviewedLicenseDraft::class)->review($record, $this->draftActor());
        $this->draftReviewContext = $this->reviewContext();

        return $this->draftReview;
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        if ($this->editingPolicyAction !== null && $arguments === [] && $this->isMountedPolicyFieldAction()) {
            return parent::callMountedAction();
        }
        $bulkReview = $this->bulkSourceReview;
        $bulkContext = $this->bulkSourceContext;
        $bulkInputContext = $this->bulkSourceInputContext;
        $review = $this->draftReview;
        $context = $this->draftReviewContext;
        // Bulk confirmation is single-use, including framework validation/visibility returns.
        $this->clearBulkSourceReview();
        // Preserve the single-editor consumption boundary before resolving the action.
        $this->clearDraftReview();
        $bulkAction = $this->getMountedAction();
        if (in_array($bulkAction?->getName(), ['replaceDraftSource', 'reviewBulkSource', 'backToBulkSource'], true)) {
            return $this->callBulkSourceAction($bulkAction, $arguments, $bulkReview, $bulkContext, $bulkInputContext);
        }
        $action = $this->getMountedAction();
        if ($action?->getName() !== 'edit') {
            return parent::callMountedAction($arguments);
        }
        $this->submittingDraft = true;
        try {
            $actor = $this->draftActor();
            if (! $action->isAuthorized()) {
                throw new AuthorizationException;
            }
            $record = $action->getRecord();
            if ($arguments !== [] || $context === null || $context !== $this->reviewContext() || $action->isDisabled()
                || ! $record instanceof LicenseVersion || $review === null
                || ($review['version_id'] ?? null) !== (int) $record->getKey()
                || ($review['template_id'] ?? null) !== (int) $record->license_template_id
                || ($review['actor_id'] ?? null) !== (int) $actor->getKey()) {
                $this->reviewRequired();
            }
            if ($record->status !== 'draft' || $record->published_at !== null) {
                $this->formErrors(ValidationException::withMessages(['license' => 'This version is no longer an editable draft. Close and reopen to inspect its current state.']));
            }
            $this->submittedReview = $review;

            return parent::callMountedAction($arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->reopenNotification();
            throw $exception;
        } catch (Throwable $exception) {
            $this->uncertainNotification($exception);
            $this->formErrors(ValidationException::withMessages(['license' => 'The save result could not be confirmed. Keep a copy of your changes, then close and reopen to inspect the saved draft before trying again.']));
        } finally {
            $this->submittedReview = null;
            $this->submittingDraft = false;
        }
    }

    public function updateDraft(LicenseVersion $record, array $data, Action $action): LicenseVersion
    {
        $review = $this->submittedReview;
        $this->submittedReview = null;
        abort_unless($this->submittingDraft && $this->getMountedAction() === $action && $action->getName() === 'edit', 403);
        if ($review === null || ($review['version_id'] ?? null) !== (int) $record->getKey()
            || ! $action->getRecord() instanceof LicenseVersion || $action->getRecord()->getKey() !== $record->getKey()) {
            $this->reviewRequired();
        }
        try {
            $saved = app(ReviewedLicenseDraft::class)->updateReviewed($review, $data, $this->draftActor());
            $action->record($saved);

            return $saved;
        } catch (ValidationException $exception) {
            $this->formErrors($exception);
        }
    }

    private function reviewRequired(): never
    {
        $this->formErrors(ValidationException::withMessages(['license' => 'Close and reopen the current draft before saving.']));
    }

    private function formErrors(ValidationException $exception): never
    {
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $visible = $field === 'license' ? 'authored_source' : $field;
            $errors[$visible] = [...($errors[$visible] ?? []), ...$messages];
        }
        $this->dispatch('form-validation-error', livewireId: $this->getId());
        // Retain the existing policy-repeater UUID mapping and schema state path.
        LicenseVersionResource::withFormErrors(fn () => throw ValidationException::withMessages($errors), $this);
        throw $exception;
    }

    private function isPolicyFieldAction(string $name, array $arguments, array $context): bool
    {
        if ($this->editingPolicyAction !== null || ! in_array($name, ['add', 'delete', 'reorder', 'moveUp', 'moveDown'], true)
            || $this->draftReview === null || $this->draftReviewContext === null || $this->getMountedAction()?->getName() !== 'edit'
            || $this->draftReviewContext !== $this->reviewContext()) {
            return false;
        }
        $schema = $this->getSchema($this->getMountedActionSchemaName());
        $component = $schema->getComponentByStatePath('structured_terms.policies');
        if (! $component instanceof Repeater || $component->getStatePath() !== $schema->getStatePath().'.structured_terms.policies') {
            return false;
        }
        $action = $component->getAction($name);
        if ($action === null || $action->isHidden() || $action->isDisabled() || ! $action->isAuthorized()
            || $action->getContext() !== $context) {
            return false;
        }
        $keys = array_keys($component->getRawState() ?? []);
        if ($name === 'add') {
            return $arguments === [];
        }
        if ($name !== 'reorder') {
            return array_keys($arguments) === ['item'] && is_string($arguments['item']) && in_array($arguments['item'], $keys, true);
        }

        return array_keys($arguments) === ['items'] && is_array($arguments['items']) && array_is_list($arguments['items'])
            && array_all($arguments['items'], fn (mixed $key): bool => is_string($key))
            && count($arguments['items']) === count($keys) && count(array_unique($arguments['items'], SORT_REGULAR)) === count($keys)
            && array_diff($arguments['items'], $keys) === [] && array_diff($keys, $arguments['items']) === [];
    }

    private function isMountedPolicyFieldAction(): bool
    {
        $mounted = $this->mountedActions[array_key_last($this->mountedActions)] ?? [];

        return Arr::only($mounted, ['name', 'arguments', 'context']) === $this->editingPolicyAction
            && count($this->mountedActions) === count($this->draftReviewContext['actions'] ?? []) + 1;
    }

    private function reviewContext(): array
    {
        return ['actor_id' => (int) $this->draftActor()->id, 'actions' => array_map(fn (array $action): array => [
            'name' => $action['name'] ?? null, 'arguments' => $action['arguments'] ?? [], 'context' => $action['context'] ?? [],
        ], $this->mountedActions), 'table' => [
            'page' => (string) $this->getTablePage(), 'per_page' => (string) $this->getTableRecordsPerPage(),
            'search' => $this->tableSearch, 'column_searches' => $this->tableColumnSearches,
            'filters' => $this->tableFilters, 'deferred_filters' => $this->tableDeferredFilters, 'sort' => $this->tableSort,
        ]];
    }

    public function updating(string $property, mixed $value): void
    {
        $root = explode('.', $property)[0];
        if (in_array($root, ['mountedActions', 'selectedTableRecords', 'deselectedTableRecords', 'isTrackingDeselectedTableRecords',
            'tableSearch', 'tableColumnSearches', 'tableFilters', 'tableDeferredFilters', 'tableSort', 'tableRecordsPerPage', 'paginators'], true)) {
            $allowedSourceInput = $this->getMountedAction()?->getName() === 'replaceDraftSource'
                && preg_match('/\AmountedActions\.([0-9]+)\.data(?:\.(authored_source))?\z/D', $property, $bulkMatches)
                && (int) $bulkMatches[1] === array_key_last($this->mountedActions)
                && (isset($bulkMatches[2]) ? is_string($value)
                    : (is_array($value) && array_keys($value) === ['authored_source'] && is_string($value['authored_source'])));
            if (! $allowedSourceInput) {
                $this->clearBulkSourceReview();
            }
        }
        if ($root === 'mountedActions' && preg_match('/\AmountedActions\.([0-9]+)\.data(?:\.(authored_source|structured_terms|effective_from|effective_until|license_template_id)(?:\..*)?)?\z/D', $property, $matches)
            && (int) $matches[1] === array_key_last($this->mountedActions)) {
            if (isset($matches[2]) ? ($matches[2] !== 'license_template_id' || (string) $value === (string) ($this->draftReview['template_id'] ?? null))
                : (is_array($value) && array_diff(array_keys($value), [...ReviewedLicenseDraft::FIELDS, 'license_template_id']) === []
                    && (! array_key_exists('license_template_id', $value) || (string) $value['license_template_id'] === (string) ($this->draftReview['template_id'] ?? null)))) {
                return;
            }
        }
        if (in_array($root, ['mountedActions', 'tableSearch', 'tableColumnSearches', 'tableFilters', 'tableDeferredFilters',
            'tableSort', 'tableRecordsPerPage', 'paginators'], true)) {
            $this->clearDraftReview();
        }
    }

    public function updatingPaginators(mixed $page, string $pageName): void
    {
        if ($pageName === $this->getTablePaginationPageName()) {
            $this->clearDraftReview();
            $this->clearBulkSourceReview();
        }
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        if ($this->editingPolicyAction !== null && $this->isMountedPolicyFieldAction() && in_array($cancelParentActions, [null, false], true)) {
            parent::unmountAction(cancelParentActions: false);

            return;
        }
        $this->clearDraftReview();
        $this->clearBulkSourceReview();
        parent::unmountAction($cancelParentActions);
    }

    public function captureBulkSourceInput(): array
    {
        $action = $this->getMountedAction();
        abort_unless($this->mountingBulkSource && $action?->getName() === 'replaceDraftSource'
            && count($this->mountedActions) === 1 && $action->getArguments() === []
            && $action->getContext() === ['table' => true, 'bulk' => true], 403);
        $this->bulkSourceInputContext = $this->bulkSourceActionContext();

        return ['authored_source' => $this->bulkAuthoredSource];
    }

    public function reviewBulkSource(array $data, Action $action): void
    {
        abort_unless($this->submittingBulkSource === 'replaceDraftSource' && $this->getMountedAction() === $action
            && $action->getName() === 'replaceDraftSource', 403);
        if (array_keys($data) !== ['authored_source'] || ! is_string($data['authored_source'])) {
            $this->bulkSourceErrors('Enter the source to replace, then review the current selected drafts.');
        }
        $this->bulkAuthoredSource = $data['authored_source'];
        $ids = $this->selectedBulkDraftIds();
        $versions = LicenseVersion::query()->whereKey($ids)->orderBy('id')->get()->all();
        if (array_map(fn (LicenseVersion $version): int => (int) $version->id, $versions) !== $ids) {
            $this->bulkSourceErrors(BulkReplaceLicenseDraftSource::REOPEN_MESSAGE);
        }
        $this->bulkSourceReview = app(BulkReplaceLicenseDraftSource::class)->review($versions, $data['authored_source'], $this->draftActor());
        $this->transitioningBulkSource = true;
        try {
            $this->replaceMountedAction('reviewBulkSource');
            $this->bulkSourceContext = $this->bulkSourceActionContext();
        } finally {
            $this->transitioningBulkSource = false;
        }
        $this->forceRender();
    }

    public function reviewBulkSourceAction(): Action
    {
        return Action::make('reviewBulkSource')->databaseTransaction(false)
            // The private submit lifecycle permits only its already-captured review.
            // After that request ends, an exhausted comparison cannot offer another save.
            ->disabled(fn (): bool => $this->bulkSourceReview === null && $this->submittingBulkSource !== 'reviewBulkSource')
            ->modalHeading('Review license draft source replacement')
            ->extraModalWindowAttributes(LicenseTemplateResource::authoringModalAttributes())
            ->modalSubmitAction(fn (Action $action) => $action->disabled(fn (): bool => $this->bulkSourceReview === null)
                ->extraAttributes(['wire:loading.attr' => null]))
            ->modalDescription('Save only the source shown for these exact drafts. If the result cannot be confirmed, keep a copy, then close and reopen to inspect the saved drafts before trying again.')
            ->schema([Textarea::make('authored_source')->label('Replacement source to keep')->readOnly()->required()->rows(8)
                ->helperText('Copy this entered text before closing. Use Back to source to change it and obtain a fresh comparison.')])
            ->fillForm(fn (): array => ['authored_source' => $this->bulkAuthoredSource])
            ->modalContent(fn () => view('filament.rights.review-bulk-license-source', ['review' => $this->bulkSourceReview]))
            ->modalSubmitActionLabel('Save reviewed source')->modalCancelActionLabel('Cancel')
            ->extraModalFooterActions([Action::make('backToBulkSource')->label('Back to source')->color('gray')
                ->action(fn () => $this->backToBulkSource())])
            ->action(fn (Action $action) => $this->applyBulkSource($action));
    }

    public function backToBulkSource(): void
    {
        abort_unless($this->submittingBulkSource === 'backToBulkSource' && $this->getMountedAction()?->getName() === 'backToBulkSource', 403);
        $this->replaceMountedAction('replaceDraftSource', context: ['table' => true, 'bulk' => true]);
        $this->forceRender();
    }

    public function applyBulkSource(Action $action): void
    {
        $review = $this->submittedBulkSourceReview;
        $this->submittedBulkSourceReview = null;
        abort_unless($this->submittingBulkSource === 'reviewBulkSource' && $this->getMountedAction() === $action
            && $action->getName() === 'reviewBulkSource' && $review !== null, 403);
        $result = app(BulkReplaceLicenseDraftSource::class)->applyReviewed($review, $this->draftActor());
        $changed = count($result['changed_ids']);
        $unchanged = count($result['unchanged_ids']);
        Notification::make()->success()->title($changed === 0
            ? "No draft source changed. {$unchanged} reviewed drafts already matched."
            : "Source saved for {$changed} drafts. {$unchanged} drafts already matched.")->send();
        $this->bulkAuthoredSource = '';
        $this->deselectAllTableRecords();
    }

    private function callBulkSourceAction(Action $action, array $arguments, ?array $review, ?array $context, ?array $inputContext): mixed
    {
        $name = $action->getName();
        $retainedSource = $this->bulkAuthoredSource;
        $this->submittingBulkSource = $name;
        try {
            $actor = $this->draftActor();
            if (! $action->isAuthorized()) {
                throw new AuthorizationException;
            }
            $raw = $this->mountedActions[array_key_last($this->mountedActions)]['data'] ?? [];
            if (! is_array($raw)) {
                $this->bulkSourceErrors(BulkReplaceLicenseDraftSource::REOPEN_MESSAGE);
            }
            if ($name === 'replaceDraftSource' && is_string($raw['authored_source'] ?? null)) {
                $this->bulkAuthoredSource = $raw['authored_source'];
                $retainedSource = $raw['authored_source'];
            }
            if ($arguments !== [] || $action->getArguments() !== [] || $action->isDisabled() || $action->isHidden()) {
                $this->bulkSourceErrors(BulkReplaceLicenseDraftSource::REOPEN_MESSAGE);
            }
            if ($name === 'backToBulkSource') {
                if (count($this->mountedActions) !== 2 || ($this->mountedActions[0]['name'] ?? null) !== 'reviewBulkSource'
                    || $action->getContext() !== [] || $raw !== []) {
                    $this->bulkSourceErrors(BulkReplaceLicenseDraftSource::REOPEN_MESSAGE);
                }
            } else {
                if (count($this->mountedActions) !== 1 || array_keys($raw) !== ['authored_source'] || ! is_string($raw['authored_source'])
                    || ($name === 'replaceDraftSource' ? $inputContext : $context) !== $this->bulkSourceActionContext()) {
                    $this->bulkSourceErrors(BulkReplaceLicenseDraftSource::REOPEN_MESSAGE);
                }
                $ids = $this->selectedBulkDraftIds();
                if ($name === 'reviewBulkSource') {
                    if ($review === null || ($review['actor_id'] ?? null) !== (int) $actor->id
                        || $ids !== array_column($review['drafts'] ?? [], 'version_id')
                        || $raw['authored_source'] !== ($review['authored_source'] ?? null)) {
                        $this->bulkSourceErrors(BulkReplaceLicenseDraftSource::REOPEN_MESSAGE);
                    }
                    $this->submittedBulkSourceReview = $review;
                }
            }

            return parent::callMountedAction($arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->clearBulkSourceReview();
            $this->submittingBulkSource = null;
            Notification::make()->danger()->title('Review current drafts to continue')
                ->body('Keep a copy of your entered source, then close and reopen the selected drafts to inspect their saved state and review again.')->persistent()->send();
            $this->bulkSourceErrors(implode(' ', array_merge(...array_values($exception->errors()))));
        } catch (Throwable $exception) {
            $this->clearBulkSourceReview();
            $this->submittingBulkSource = null;
            $this->bulkAuthoredSource = $retainedSource;
            try {
                Log::warning('Bulk license draft UI could not confirm the result.', ['exception_class' => $exception::class]);
            } catch (Throwable) {
                // Logging must not expose source text or imply a known outcome.
            }
            Notification::make()->danger()->title('The bulk save result could not be confirmed.')
                ->body('Keep a copy of your entered source, then close and reopen to inspect the saved drafts before trying again.')->persistent()->send();
            $this->bulkSourceErrors('The result could not be confirmed. Keep a copy of your source, then close and reopen to inspect the saved drafts before trying again.');
        } finally {
            $this->submittingBulkSource = null;
            $this->submittedBulkSourceReview = null;
        }
    }

    private function selectedBulkDraftIds(): array
    {
        if ($this->isTrackingDeselectedTableRecords || $this->deselectedTableRecords !== []
            || ! array_is_list($this->selectedTableRecords) || count($this->selectedTableRecords) < 1
            || count($this->selectedTableRecords) > BulkReplaceLicenseDraftSource::MAX_DRAFTS) {
            $this->bulkSourceErrors('Select 1 to 25 explicit editable drafts on the current page.');
        }
        $ids = [];
        foreach ($this->selectedTableRecords as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $id)
                || (string) (int) $id !== (string) $id) {
                $this->bulkSourceErrors('Select valid draft IDs on the current page.');
            }
            $ids[] = (int) $id;
        }
        if (count(array_unique($ids, SORT_NUMERIC)) !== count($ids)
            || ! in_array((string) $this->getTableRecordsPerPage(), ['5', '10', '25', '50'], true)) {
            $this->bulkSourceErrors('Select distinct drafts using a supported current page size.');
        }
        $this->flushCachedTableRecords();
        $visible = array_map(fn (LicenseVersion $version): int => (int) $version->id, iterator_to_array($this->getTableRecords()));
        if (array_diff($ids, $visible)) {
            $this->bulkSourceErrors('Select only drafts visible on the current filtered page.');
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function bulkSourceActionContext(): array
    {
        return [...$this->reviewContext(), 'selection' => $this->selectedBulkDraftIds()];
    }

    private function clearBulkSourceReview(): void
    {
        $this->bulkSourceReview = null;
        $this->bulkSourceContext = null;
        $this->bulkSourceInputContext = null;
        $this->submittedBulkSourceReview = null;
    }

    private function bulkSourceErrors(string $message): never
    {
        $this->dispatch('form-validation-error', livewireId: $this->getId());
        $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        throw ValidationException::withMessages([$path.'.authored_source' => $message]);
    }

    private function clearDraftReview(): void
    {
        $this->draftReview = null;
        $this->draftReviewContext = null;
        $this->submittedReview = null;
    }

    private function reopenNotification(): void
    {
        Notification::make()->danger()->title('Reopen draft to continue')
            ->body('Your entered values are still shown. Keep a copy of your changes, then close and reopen the current draft before saving again.')->persistent()->send();
    }

    private function logUncertain(Throwable $exception): void
    {
        try {
            Log::warning('License draft UI could not confirm the result.', ['exception_class' => $exception::class]);
        } catch (Throwable) {
            // Logging cannot disclose draft content or imply a known save outcome.
        }
    }

    private function uncertainNotification(Throwable $exception): void
    {
        $this->logUncertain($exception);
        Notification::make()->danger()->title('The save result could not be confirmed.')
            ->body('Keep a copy of your changes, then close and reopen to inspect the saved draft before trying again.')->persistent()->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data, $livewire) => LicenseVersionResource::withFormErrors(fn () => app(CreateLicenseDraft::class)->handle(LicenseTemplate::findOrFail($data['license_template_id']), Arr::only($data, ['authored_source', 'structured_terms', 'effective_from', 'effective_until']), auth()->user()), $livewire))];
    }
}
