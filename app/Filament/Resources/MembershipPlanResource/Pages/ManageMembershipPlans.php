<?php

namespace App\Filament\Resources\MembershipPlanResource\Pages;

use App\Domain\Memberships\MembershipAdministration;
use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipPlan;
use App\Filament\Resources\LicenseTemplateResource;
use App\Filament\Resources\MembershipPlanResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

final class ManageMembershipPlans extends ManageRecords
{
    protected static string $resource = MembershipPlanResource::class;

    #[Locked]
    public ?array $planReview = null;

    #[Locked]
    public ?array $planReviewContext = null;

    #[Locked]
    public ?array $planInputContext = null;

    #[Locked]
    public ?array $enteredPlan = null;

    #[Locked]
    public string $enteredPlanText = '';

    #[Locked]
    public ?array $reviewBefore = null;

    #[Locked]
    public ?int $editingPlanId = null;

    private bool $mountingInput = false;

    private bool $transitioning = false;

    private ?string $submitting = null;

    private ?array $submittedReview = null;

    public function boot(): void
    {
        $this->actor();
    }

    private function actor(): User
    {
        return app(MembershipAdministration::class)->authorize(auth()->user());
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        if (! ($this->transitioning && $name === 'reviewRevision' && $arguments === [] && $context === [])) {
            $this->clearReview();
        }
        if ($name === 'reviewRevision') {
            abort_unless($this->transitioning && $arguments === [] && $context === [], 403);
        }
        if (! in_array($name, ['editPlan', 'createPlan'], true)) {
            return parent::mountAction($name, $arguments, $context);
        }
        $this->mountingInput = true;
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->unmountAction();
            $this->logUncertain($exception);
            Notification::make()->danger()->title('Private plan authoring could not be opened')
                ->body('Reload private plans before opening an editor again.')->persistent()->send();

            return null;
        } finally {
            $this->mountingInput = false;
        }
    }

    public function capturePlanInput(?MembershipPlan $record = null): array
    {
        $action = $this->getMountedAction();
        $name = $record === null ? 'createPlan' : 'editPlan';
        abort_unless($this->mountingInput && $action?->getName() === $name && count($this->mountedActions) === 1
            && $action->getArguments() === []
            && ($record === null ? $action->getContext() === [] : ($action->getRecord() instanceof MembershipPlan
                && $action->getRecord()->getKey() === $record->getKey())), 403);
        $id = $record === null ? null : (int) $record->id;
        if ($this->editingPlanId !== $id) {
            $this->enteredPlan = null;
        }
        $this->editingPlanId = $id;
        if ($this->enteredPlan === null) {
            $history = $record === null ? null : app(MembershipAdministration::class)->planHistory($id, $this->actor());
            $this->enteredPlan = $history === null ? array_fill_keys(MembershipPlanResource::INPUT_FIELDS, null)
                : MembershipPlanResource::inputDisplay($history['versions'][array_key_last($history['versions'])]);
        }
        $this->planInputContext = $this->actionContext();

        return $this->enteredPlan;
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $review = $this->planReview;
        $context = $this->planReviewContext;
        $inputContext = $this->planInputContext;
        // Exhaust before framework visibility/validation can return early.
        $this->clearReview();
        $action = $this->getMountedAction();
        $name = $action?->getName();
        if (! in_array($name, ['createPlan', 'editPlan', 'reviewRevision', 'backToPlan'], true)) {
            return parent::callMountedAction($arguments);
        }
        $this->submitting = $name;
        try {
            $actor = $this->actor();
            if (! $action->isAuthorized()) {
                throw new AuthorizationException;
            }
            $raw = $this->mountedActions[array_key_last($this->mountedActions)]['data'] ?? [];
            if ($arguments !== [] || $action->getArguments() !== [] || $action->isHidden() || $action->isDisabled() || ! is_array($raw)) {
                $this->formErrors('Close and reopen private plan authoring before continuing.');
            }
            if (in_array($name, ['createPlan', 'editPlan'], true)) {
                if (array_diff(array_keys($raw), MembershipPlanResource::INPUT_FIELDS) !== []
                    || array_diff(MembershipPlanResource::INPUT_FIELDS, array_keys($raw)) !== []) {
                    $this->formErrors('Enter every explicit private policy field.');
                }
                $this->enteredPlan = $raw;
                if ($name === 'editPlan' && (! $action->getRecord() instanceof MembershipPlan
                    || $this->editingPlanId !== (int) $action->getRecord()->getKey())) {
                    $this->formErrors('Close and reopen the current private plan before continuing.');
                }
                if ($name === 'createPlan' && $this->editingPlanId !== null) {
                    $this->formErrors('Close and reopen new plan authoring before continuing.');
                }
            }
            if ($name === 'backToPlan') {
                if (count($this->mountedActions) !== 2 || ($this->mountedActions[0]['name'] ?? null) !== 'reviewRevision'
                    || $action->getContext() !== [] || $raw !== []) {
                    $this->formErrors('Close and reopen private plan authoring before continuing.');
                }
            } elseif (count($this->mountedActions) !== 1 || ($name === 'reviewRevision' ? $context : $inputContext) !== $this->actionContext()) {
                // Retain the current entered fields even after an exhausted form is corrected.
                // A changed/consumed context still cannot review or apply any policy.
                $this->formErrors('Close and reopen private plan authoring before continuing.');
            }
            if ($name === 'reviewRevision') {
                if ($review === null || $review['actor_id'] !== (int) $actor->id || $review['plan_id'] !== $this->editingPlanId
                    || array_keys($raw) !== ['entered_plan'] || $raw['entered_plan'] !== $this->enteredPlanText) {
                    $this->formErrors('This comparison is exhausted. Use Back to policy and explicitly review again.');
                }
                $this->submittedReview = $review;
            }

            return parent::callMountedAction($arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->clearReview();
            $this->submitting = null;
            Notification::make()->danger()->title('Review the current private plan to continue')
                ->body('Your entered policy is retained. Keep a copy, then use Back to policy or close and reopen to inspect saved versions before trying again.')->persistent()->send();
            $this->formErrors(implode(' ', array_merge(...array_values($exception->errors()))));
        } catch (Throwable $exception) {
            $this->clearReview();
            $this->submitting = null;
            $this->logUncertain($exception);
            Notification::make()->danger()->title('The private plan save could not be confirmed')
                ->body('Keep a copy of your entered policy, then use Back to policy or reopen to inspect saved versions before reviewing again.')->persistent()->send();
            $this->formErrors('The result could not be confirmed. Keep a copy and inspect saved versions before reviewing again.');
        } finally {
            $this->submitting = null;
            $this->submittedReview = null;
            // The modal fragment may leave the existing footer in the returned page.
            // Render it only after the submit flags clear, so an exhausted review's
            // Apply control and the enabled copy field agree with retained state.
            $this->forceRender();
        }
    }

    public function reviewPlan(array $data, Action $action): void
    {
        abort_unless($this->submitting === 'editPlan' && $this->getMountedAction() === $action && $this->editingPlanId !== null, 403);
        $replacement = MembershipPlanResource::inputData($data);
        $plan = MembershipPlan::findOrFail($this->editingPlanId);
        $review = app(MembershipPlans::class)->reviewRevision($plan, $replacement, $this->actor());
        $history = app(MembershipAdministration::class)->planHistory($this->editingPlanId, $this->actor());
        foreach (['plan_hash', 'version_hash', 'history_hash', 'audit_id'] as $key) {
            if ($history[$key] !== $review[$key]) {
                $this->formErrors('The current version changed during review. Explicitly review again.');
            }
        }
        $before = $history['versions'][array_key_last($history['versions'])];
        if ($before['version_id'] !== $review['version_id']) {
            $this->formErrors('The current version changed during review. Explicitly review again.');
        }
        $this->planReview = $review;
        $this->reviewBefore = $before;
        $this->enteredPlan = MembershipPlanResource::inputDisplay($replacement);
        $this->enteredPlanText = json_encode($replacement, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->transitioning = true;
        try {
            $this->replaceMountedAction('reviewRevision');
            $this->planReviewContext = $this->actionContext();
        } finally {
            $this->transitioning = false;
        }
        $this->forceRender();
    }

    public function reviewRevisionAction(): Action
    {
        return Action::make('reviewRevision')->databaseTransaction(false)->authorize(fn () => MembershipPlanResource::canAccess())
            ->disabled(fn () => $this->planReview === null && $this->submitting !== 'reviewRevision')
            ->modalHeading('Review private plan revision')->modalSubmitActionLabel('Apply reviewed revision')->modalCancelActionLabel('Cancel')
            ->extraModalWindowAttributes(LicenseTemplateResource::authoringModalAttributes())
            ->modalSubmitAction(fn (Action $action) => $action->disabled(fn () => $this->planReview === null)->extraAttributes(['wire:loading.attr' => null]))
            ->modalDescription('Apply only this captured synthetic policy. Prior immutable versions and existing credit buckets retain their original policy.')
            ->schema([Textarea::make('entered_plan')->label('Entered policy to keep')->readOnly()->required()->rows(10)
                ->helperText('Copy the complete policy before closing. Back to policy lets you change it and obtain a fresh review.')])
            ->fillForm(fn () => ['entered_plan' => $this->enteredPlanText])
            ->modalContent(fn () => view('admin.membership-plan-review', ['review' => $this->planReview, 'before' => $this->reviewBefore]))
            ->extraModalFooterActions([Action::make('backToPlan')->label('Back to policy')->color('gray')->action(fn () => $this->backToPlan())])
            ->action(fn (Action $action) => $this->applyPlan($action));
    }

    public function backToPlan(): void
    {
        abort_unless($this->submitting === 'backToPlan' && $this->getMountedAction()?->getName() === 'backToPlan' && $this->editingPlanId !== null, 403);
        $this->replaceMountedAction('editPlan', context: ['table' => true, 'recordKey' => (string) $this->editingPlanId]);
        $this->forceRender();
    }

    public function applyPlan(Action $action): void
    {
        $review = $this->submittedReview;
        $this->submittedReview = null;
        abort_unless($this->submitting === 'reviewRevision' && $this->getMountedAction() === $action && $review !== null, 403);
        $result = app(MembershipPlans::class)->applyReviewedRevision($review, $this->actor());
        Notification::make()->success()->title($result['version_id'] === $review['version_id']
            ? 'Private plan already matches; no version added' : 'Private plan revision saved')->send();
        $this->enteredPlan = null;
        $this->enteredPlanText = '';
        $this->editingPlanId = null;
    }

    public function createPlan(array $data, Action $action): void
    {
        abort_unless($this->submitting === 'createPlan' && $this->getMountedAction() === $action && $this->editingPlanId === null, 403);
        app(MembershipPlans::class)->createDraft(MembershipPlanResource::inputData($data), $this->actor());
        Notification::make()->success()->title('Private test plan created')->body('No account was enrolled or credited.')->send();
        $this->enteredPlan = null;
    }

    private function actionContext(): array
    {
        return ['actor_id' => (int) $this->actor()->id, 'plan_id' => $this->editingPlanId,
            'actions' => array_map(fn ($action) => ['name' => $action['name'] ?? null, 'arguments' => $action['arguments'] ?? [],
                'context' => $action['context'] ?? []], $this->mountedActions),
            'table' => ['page' => (string) $this->getTablePage(), 'per_page' => (string) $this->getTableRecordsPerPage(),
                'search' => $this->tableSearch, 'column_searches' => $this->tableColumnSearches,
                'filters' => $this->tableFilters, 'deferred_filters' => $this->tableDeferredFilters, 'sort' => $this->tableSort]];
    }

    public function updating(string $property, mixed $value): void
    {
        $root = explode('.', $property)[0];
        if ($root === 'mountedActions' && preg_match('/\AmountedActions\.([0-9]+)\.data(?:\.(title|unit|allowance|validity_seconds|rollover|reversal_allowed))?\z/D', $property, $matches)
            && (int) $matches[1] === array_key_last($this->mountedActions)
            && in_array($this->mountedActions[(int) $matches[1]]['name'] ?? null, ['createPlan', 'editPlan'], true)) {
            if (isset($matches[2]) || (is_array($value) && array_diff(array_keys($value), MembershipPlanResource::INPUT_FIELDS) === [])) {
                return;
            }
        }
        if (in_array($root, ['mountedActions', 'tableSearch', 'tableColumnSearches', 'tableFilters', 'tableDeferredFilters',
            'tableSort', 'tableRecordsPerPage', 'paginators'], true)) {
            $this->clearReview();
        }
    }

    public function updatingPaginators(mixed $page, string $pageName): void
    {
        $this->clearReview();
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->clearReview();
        parent::unmountAction($cancelParentActions);
    }

    private function clearReview(): void
    {
        $this->planReview = null;
        $this->planReviewContext = null;
        $this->planInputContext = null;
        $this->reviewBefore = null;
        $this->submittedReview = null;
    }

    private function formErrors(string $message): never
    {
        $this->dispatch('form-validation-error', livewireId: $this->getId());
        $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        $field = ($this->mountedActions[0]['name'] ?? null) === 'reviewRevision' ? 'entered_plan' : 'title';
        throw ValidationException::withMessages([$path.'.'.$field => $message]);
    }

    private function logUncertain(Throwable $exception): void
    {
        try {
            Log::warning('Private membership authoring could not confirm the result.', ['exception_class' => $exception::class]);
        } catch (Throwable) {
            // Policy inputs and exception text must never enter logs or success claims.
        }
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('createPlan')->label('Create private test plan')->databaseTransaction(false)
            ->authorize(fn () => MembershipPlanResource::canAccess())->modalHeading('Create a private test membership plan')
            ->modalDescription('Define an explicit synthetic benefit policy. This creates one private immutable version and does not enroll, bill or award credits.')
            ->extraModalWindowAttributes(LicenseTemplateResource::authoringModalAttributes())
            ->schema(MembershipPlanResource::inputFields())->fillForm(fn () => $this->capturePlanInput())
            ->action(fn (array $data, Action $action) => $this->createPlan($data, $action))];
    }
}
