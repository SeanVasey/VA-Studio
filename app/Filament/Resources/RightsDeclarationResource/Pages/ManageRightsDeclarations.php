<?php

namespace App\Filament\Resources\RightsDeclarationResource\Pages;

use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Filament\Resources\RightsDeclarationResource;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class ManageRightsDeclarations extends ManageRecords
{
    protected static string $resource = RightsDeclarationResource::class;

    #[Locked]
    public ?array $rightsReview = null;

    #[Locked]
    public ?array $rightsReviewContext = null;

    /** Submit owns a request-local copy after the visible review has been consumed. */
    private ?array $submittedRightsReview = null;

    private bool $mountingRightsAction = false;

    private bool $submittingRightsAction = false;

    public function boot(): void
    {
        $actor = $this->actor();
        $panel = Filament::getCurrentOrDefaultPanel();
        abort_unless($panel !== null && AdminMultiFactor::satisfiedBy($actor, $panel), 403);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('administer-catalog');

        return $actor;
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        $this->clearRightsReview();
        $this->mountingRightsAction = true;
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Rights review blocked')
                ->body('Open a current pending declaration. Record a new declaration to correct verified evidence.')->persistent()->send();

            return null;
        } catch (Throwable $exception) {
            $this->recordUncertainResult($exception);
            $this->unmountAction();
            Notification::make()->danger()->title('Rights review could not be opened')
                ->body('Reload declarations and open the current pending declaration again.')->persistent()->send();

            return null;
        } finally {
            $this->mountingRightsAction = false;
        }
    }

    public function captureRightsReview(RightsDeclaration $record, string $intent): array
    {
        $action = $this->getMountedAction();
        abort_unless($this->mountingRightsAction && in_array($intent, ['edit', 'verify'], true)
            && $action?->getName() === $intent && $action->getRecord() instanceof RightsDeclaration
            && (int) $action->getRecord()->getKey() === (int) $record->getKey(), 403);
        $review = $intent === 'verify'
            ? app(VerifyRightsDeclaration::class)->review($record, $this->actor())
            : app(SaveRightsDeclaration::class)->review($record, $this->actor());
        $this->rightsReview = $review;
        $this->rightsReviewContext = $this->reviewContext();

        return $review;
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $review = $this->rightsReview;
        $context = $this->rightsReviewContext;
        // Filament validates the schema before invoking .using(). Consume even an invalid-form attempt.
        $this->clearRightsReview();
        $this->submittingRightsAction = true;
        $action = null;
        try {
            $action = $this->getMountedAction();
            if (in_array($action?->getName(), ['edit', 'verify'], true)) {
                $record = $action->getRecord();
                $actor = $this->actor();
                if (! $action->isAuthorized()) {
                    throw new AuthorizationException;
                }
                // A competing verification hides the action; Filament would silently return before .using().
                if ($arguments !== [] || $review === null || $context !== $this->reviewContext()
                    || ! $record instanceof RightsDeclaration || $record->status !== 'pending' || $action->isDisabled()
                    || ($review['intent'] ?? null) !== $action->getName()
                    || ($review['declaration_id'] ?? null) !== (int) $record->getKey()
                    || ($review['actor_id'] ?? null) !== (int) $actor->getKey()) {
                    throw $this->reviewRequiredException($action);
                }
                $this->submittedRightsReview = $review;
            }

            return parent::callMountedAction($arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            if ($action?->getName() === 'verify') {
                Notification::make()->danger()->title('Verification blocked')
                    ->body('Close and reopen the current pending declaration before verifying again. Record a new declaration to correct verified evidence.')->persistent()->send();
                $this->unmountAction();

                return null;
            }
            if ($action?->getName() === 'edit') {
                Notification::make()->danger()->title('Review required')
                    ->body('This review has been used. Close and reopen the declaration, then review current evidence before saving again.')->persistent()->send();
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordUncertainResult($exception);
            $this->unmountAction();
            Notification::make()->danger()->title('The save result could not be confirmed.')
                ->body('Reload declarations and review current evidence before trying again.')->persistent()->send();

            return null;
        } finally {
            $this->submittedRightsReview = null;
            $this->submittingRightsAction = false;
        }
    }

    public function createRightsDeclaration(array $data, Action $action): RightsDeclaration
    {
        abort_unless($this->submittingRightsAction && $this->getMountedAction() === $action && $action->getName() === 'create', 403);
        try {
            return app(SaveRightsDeclaration::class)->create($data, $this->actor());
        } catch (ValidationException $exception) {
            $this->throwFormErrors($exception);
        }
    }

    public function updateRightsDeclaration(RightsDeclaration $record, array $data, Action $action): RightsDeclaration
    {
        $review = $this->takeSubmittedReview($record, $action, 'edit');
        try {
            $saved = app(SaveRightsDeclaration::class)->updateReviewed($review, $data, $this->actor());
            $action->record($saved);

            return $saved;
        } catch (ValidationException $exception) {
            $this->throwFormErrors($exception);
        }
    }

    public function verifyRightsDeclaration(RightsDeclaration $record, Action $action): void
    {
        $review = $this->takeSubmittedReview($record, $action, 'verify');
        $action->record(app(VerifyRightsDeclaration::class)->verifyReviewed($review, $this->actor()));
        $action->success();
    }

    private function takeSubmittedReview(RightsDeclaration $record, Action $action, string $intent): array
    {
        $review = $this->submittedRightsReview;
        $this->submittedRightsReview = null;
        abort_unless($this->submittingRightsAction && $this->getMountedAction() === $action && $action->getName() === $intent, 403);
        if ($review === null || ($review['intent'] ?? null) !== $intent
            || ($review['declaration_id'] ?? null) !== (int) $record->getKey()
            || ! $action->getRecord() instanceof RightsDeclaration
            || (int) $action->getRecord()->getKey() !== (int) $record->getKey()) {
            throw $this->reviewRequiredException($action);
        }

        return $review;
    }

    private function reviewRequiredException(Action $action): ValidationException
    {
        $field = $action->getName() === 'edit'
            ? $this->getSchema($this->getMountedActionSchemaName())->getStatePath().'.provenance_reference'
            : 'rights';

        return ValidationException::withMessages([$field => 'Close and reopen the current declaration to review its evidence before trying again.']);
    }

    private function throwFormErrors(ValidationException $exception): never
    {
        $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $visible = in_array($field, ['track_id', 'provenance_reference', 'sample_disclosure'], true) ? $field : 'provenance_reference';
            $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
        }
        throw ValidationException::withMessages($errors);
    }

    private function reviewContext(): array
    {
        return ['actions' => array_map(fn (array $action): array => [
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
        if ($root === 'mountedActions' && preg_match('/\AmountedActions\.([0-9]+)\.data(?:\.(track_id|provenance_reference|sample_disclosure))?\z/D', $property, $matches)
            && (int) $matches[1] === array_key_last($this->mountedActions)
            && (isset($matches[2]) || (is_array($value) && array_diff(array_keys($value), ['track_id', 'provenance_reference', 'sample_disclosure']) === []))) {
            return; // A pending edit may change evidence and select another track against the captured baseline.
        }
        if (in_array($root, ['mountedActions', 'tableSearch', 'tableColumnSearches', 'tableFilters', 'tableDeferredFilters',
            'tableSort', 'tableRecordsPerPage', 'paginators', 'selectedTableRecords', 'deselectedTableRecords', 'isTrackingDeselectedTableRecords'], true)) {
            $this->clearRightsReview();
        }
    }

    public function updatingPaginators(mixed $page, string $pageName): void
    {
        if ($pageName === $this->getTablePaginationPageName()) {
            $this->clearRightsReview();
        }
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->clearRightsReview();
        parent::unmountAction($cancelParentActions);
    }

    private function clearRightsReview(): void
    {
        $this->rightsReview = null;
        $this->rightsReviewContext = null;
        $this->submittedRightsReview = null;
    }

    private function recordUncertainResult(Throwable $exception): void
    {
        // SQL errors can contain bindings with private evidence; retain only the exception class.
        try {
            Log::warning('Rights declaration UI could not confirm the result.', ['exception_class' => $exception::class]);
        } catch (Throwable) {
            // A logging outage must not expose evidence or turn an uncertain result into a retry.
        }
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->databaseTransaction(false)
            ->using(fn (array $data, Action $action) => $this->createRightsDeclaration($data, $action))];
    }
}
