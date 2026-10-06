<?php

namespace App\Filament\Resources\LicenseVersionResource\Pages;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\ReviewedLicenseDraft;
use App\Filament\Resources\LicenseVersionResource;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
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
        $this->clearDraftReview();
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
        $review = $this->draftReview;
        $context = $this->draftReviewContext;
        // Consume before framework validation or action visibility can return early.
        $this->clearDraftReview();
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
        }
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        if ($this->editingPolicyAction !== null && $this->isMountedPolicyFieldAction() && in_array($cancelParentActions, [null, false], true)) {
            parent::unmountAction(cancelParentActions: false);

            return;
        }
        $this->clearDraftReview();
        parent::unmountAction($cancelParentActions);
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
