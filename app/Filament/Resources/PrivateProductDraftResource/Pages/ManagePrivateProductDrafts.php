<?php

namespace App\Filament\Resources\PrivateProductDraftResource\Pages;

use App\Domain\ProductAuthoring\PrivateDraft;
use App\Domain\ProductAuthoring\ReviewedPrivateDrafts;
use App\Filament\Resources\TrackResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

abstract class ManagePrivateProductDrafts extends ManageRecords
{
    #[Locked]
    public ?array $draftReview = null;

    #[Locked]
    public ?array $inputContext = null;

    #[Locked]
    public ?int $openedDraftId = null;

    #[Locked]
    public ?string $openedStateHash = null;

    #[Locked]
    public string $enteredSummary = '';

    private bool $transitioning = false;

    private bool $mounting = false;

    private ?array $editingFieldAction = null;

    private ?string $submitting = null;

    private ?array $submittedReview = null;

    public function boot(): void
    {
        $this->resource()::actor();
    }

    private function resource(): string
    {
        return static::getResource();
    }

    public function openDraft(PrivateDraft $record): array
    {
        abort_unless($this->mounting && $this->getMountedAction()?->getName() === 'editDraft', 403);
        $resource = $this->resource();
        $snapshot = $resource::command()->snapshot($record->id, $resource::actor());
        $this->openedDraftId = $snapshot['id'];
        $this->openedStateHash = $snapshot['state_hash'];
        $this->inputContext = $this->context();

        return array_diff_key($snapshot['manifest'], array_flip(['schema', 'canonicalization', 'kind']));
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        if ($this->isFieldAction($name, $arguments, $context)) {
            $before = $this->inputContext;
            $this->editingFieldAction = compact('name', 'arguments', 'context');
            try {
                return parent::mountAction($name, $arguments, $context);
            } finally {
                $this->editingFieldAction = null;
                if ($before !== $this->context()) {
                    $this->clearReview();
                }
            }
        }
        if ($name === 'confirmDraft') {
            abort_unless($this->transitioning && $arguments === [] && $context === [], 403);
        } else {
            $this->clearReview();
            $this->openedDraftId = null;
            $this->openedStateHash = null;
        }
        $this->mounting = true;
        try {
            $result = parent::mountAction($name, $arguments, $context);
            if ($name === 'createDraft') {
                $this->inputContext = $this->context();
            }
        } finally {
            $this->mounting = false;
        }

        return $result;
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        if ($this->editingFieldAction !== null && in_array($cancelParentActions, [null, false], true)) {
            parent::unmountAction(cancelParentActions: false);

            return;
        }
        if (! $this->transitioning) {
            $this->clearReview();
            $this->openedDraftId = null;
            $this->openedStateHash = null;
        }
        parent::unmountAction($cancelParentActions);
    }

    public function updating(string $property, mixed $value): void
    {
        if (explode('.', $property)[0] === 'mountedActions'
            && ! preg_match('/\AmountedActions\.[0-9]+\.data(?:\.|\z)/D', $property)) {
            $this->clearReview();
        }
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $mounted = $this->mountedActions[array_key_last($this->mountedActions)] ?? [];
        if ($this->editingFieldAction !== null && $arguments === []
            && array_intersect_key($mounted, array_flip(['name', 'arguments', 'context'])) === $this->editingFieldAction
            && count($this->mountedActions) === 2) {
            return parent::callMountedAction();
        }
        $review = $this->draftReview;
        $context = $this->inputContext;
        $this->draftReview = null;
        $this->submittedReview = null;
        $action = $this->getMountedAction();
        $name = $action?->getName();
        if (! in_array($name, ['createDraft', 'editDraft', 'confirmDraft'], true)) {
            return parent::callMountedAction($arguments);
        }
        $this->submitting = $name;
        try {
            $resource = $this->resource();
            $resource::actor();
            $this->submittedReview = $name === 'confirmDraft' ? $review : null;
            if ($arguments !== [] || $action->getArguments() !== [] || count($this->mountedActions) !== 1
                || $context === null || $context !== $this->context() || ! $action->isAuthorized() || $action->isHidden() || $action->isDisabled()
                || ($name === 'confirmDraft' && ($review === null || ($review['draft_id'] ?? null) !== $this->openedDraftId))) {
                $this->formError(ReviewedPrivateDrafts::REOPEN);
            }
            if ($name === 'editDraft' && (! $action->getRecord() instanceof PrivateDraft || $action->getRecord()->id !== $this->openedDraftId)) {
                $this->formError(ReviewedPrivateDrafts::REOPEN);
            }
            if ($name === 'confirmDraft') {
                $raw = $this->mountedActions[0]['data'] ?? null;
                if (! is_array($raw) || array_keys($raw) !== ['entered_summary'] || $raw['entered_summary'] !== $this->enteredSummary) {
                    $this->formError(ReviewedPrivateDrafts::REOPEN);
                }
            }

            return parent::callMountedAction($arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            if ($name === 'confirmDraft') {
                Notification::make()->danger()->title('Review current draft to continue')
                    ->body('Keep a copy of the entered contents, then close and reopen to inspect the saved state.')->persistent()->send();
            }
            $this->formError(implode(' ', array_merge(...array_values($exception->errors()))));
        } catch (Throwable) {
            Notification::make()->danger()->title('The save result could not be confirmed.')
                ->body('Keep a copy of the entered contents, then close and reopen to inspect the saved draft before trying again.')->persistent()->send();
            $this->formError('The result could not be confirmed. Keep a copy, close and reopen to inspect the saved draft.');
        } finally {
            $this->submittedReview = null;
            $this->submitting = null;
            // A form-only partial leaves the prior enabled modal footer in the client.
            // Render the consumed capture and copy field together, including uncertainty.
            $this->forceRender();
        }
    }

    public function previewDraft(?PrivateDraft $record, array $data, Action $action): void
    {
        abort_unless($this->submitting === ($record === null ? 'createDraft' : 'editDraft') && $this->getMountedAction() === $action, 403);
        $resource = $this->resource();
        $review = $resource::command()->review($record, $resource::authoredInput($data), $resource::actor(), $this->openedStateHash);
        $this->draftReview = $review;
        $this->enteredSummary = $resource::describe($review['manifest']);
        $this->transitioning = true;
        try {
            $this->replaceMountedAction('confirmDraft');
            $this->inputContext = $this->context();
        } finally {
            $this->transitioning = false;
        }
        $this->forceRender();
    }

    public function confirmDraftAction(): Action
    {
        return Action::make('confirmDraft')->databaseTransaction(false)
            ->disabled(fn (): bool => $this->draftReview === null && $this->submittedReview === null)
            ->modalHeading('Review private draft contents')->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->modalDescription('Save the exact private contents shown. Authored text remains unapproved for sales or fulfillment. Keep a copy before closing if the save result is uncertain.')
            ->schema([Textarea::make('entered_summary')->label('Entered contents to keep')->readOnly()->rows(12)])
            ->fillForm(fn (): array => ['entered_summary' => $this->enteredSummary])
            ->modalContent(fn () => view('filament.product-authoring.review-private-draft', ['review' => $this->draftReview, 'resource' => $this->resource()]))
            ->modalSubmitAction(fn (Action $action) => $action->disabled(fn (): bool => $this->draftReview === null)
                ->extraAttributes(['wire:loading.attr' => null]))
            ->modalSubmitActionLabel('Save reviewed private version')
            ->action(function (Action $action): void {
                $review = $this->submittedReview;
                $this->submittedReview = null;
                abort_unless($this->submitting === 'confirmDraft' && $this->getMountedAction() === $action && $review !== null, 403);
                $resource = $this->resource();
                $saved = $resource::command()->applyReviewed($review, $resource::actor());
                Notification::make()->success()->title($saved->version === $review['version'] ? 'Private contents already match' : 'Private draft version saved')->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('createDraft')->label('Create private draft')->databaseTransaction(false)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())->modalSubmitActionLabel('Review contents')
            ->schema($this->resource()::fields())
            ->action(fn (array $data, Action $action) => $this->previewDraft(null, $data, $action))];
    }

    private function context(): ?array
    {
        return $this->mountedActions === [] ? null : array_map(fn (array $action): array => array_intersect_key($action, array_flip(['name', 'arguments', 'context'])), $this->mountedActions);
    }

    private function isFieldAction(string $name, array $arguments, array $context): bool
    {
        if ($this->editingFieldAction !== null || ! in_array($name, ['add', 'delete', 'moveUp', 'moveDown', 'reorder'], true)
            || ! in_array($this->getMountedAction()?->getName(), ['createDraft', 'editDraft'], true)
            || $this->inputContext === null || $this->inputContext !== $this->context()) {
            return false;
        }
        $schema = $this->getSchema($this->getMountedActionSchemaName());
        foreach (['brief_questions', 'variants'] as $path) {
            $component = $schema->getComponentByStatePath($path);
            if (! $component instanceof Repeater || $component->getStatePath() !== $schema->getStatePath().'.'.$path) {
                continue;
            }
            $action = $component->getAction($name);
            if ($action === null || $action->getContext() !== $context || $action->isHidden() || $action->isDisabled() || ! $action->isAuthorized()) {
                continue;
            }
            $keys = array_keys($component->getRawState() ?? []);
            if ($name === 'add') {
                return $arguments === [];
            }
            if ($name !== 'reorder') {
                return array_keys($arguments) === ['item'] && is_string($arguments['item']) && in_array($arguments['item'], $keys, true);
            }

            return array_keys($arguments) === ['items'] && is_array($arguments['items']) && array_is_list($arguments['items'])
                && array_all($arguments['items'], fn ($key): bool => is_string($key))
                && count($arguments['items']) === count($keys) && count(array_unique($arguments['items'])) === count($keys)
                && array_diff($arguments['items'], $keys) === [] && array_diff($keys, $arguments['items']) === [];
        }

        return false;
    }

    private function clearReview(): void
    {
        $this->draftReview = null;
        $this->inputContext = null;
        $this->submittedReview = null;
    }

    private function formError(string $message): never
    {
        $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        $field = $this->getMountedAction()?->getName() === 'confirmDraft' ? 'entered_summary' : 'title';
        throw ValidationException::withMessages([$path.'.'.$field => $message]);
    }
}
