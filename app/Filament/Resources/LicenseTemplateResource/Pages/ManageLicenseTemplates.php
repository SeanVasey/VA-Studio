<?php

namespace App\Filament\Resources\LicenseTemplateResource\Pages;

use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Filament\Resources\LicenseTemplateResource;
use App\Filament\Resources\TrackResource;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class ManageLicenseTemplates extends ManageRecords
{
    protected static string $resource = LicenseTemplateResource::class;

    #[Locked]
    public ?array $templateReview = null;

    #[Locked]
    public ?array $templateReviewContext = null;

    private ?array $submittedReview = null;

    private bool $mounting = false;

    private bool $submitting = false;

    public function boot(): void
    {
        abort_unless(AdminMultiFactor::satisfiedBy($this->actor()), 403);
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
        $this->clearReview();
        $this->mounting = true;
        try {
            $result = parent::mountAction($name, $arguments, $context);
            if ($name === 'create' && $this->getMountedAction()?->getName() === 'create') {
                $this->templateReviewContext = $this->reviewContext();
            }

            return $result;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException) {
            $this->unmountAction();
            Notification::make()->danger()->title('Template edit blocked')->body(SaveLicenseTemplate::FROZEN_MESSAGE)->persistent()->send();

            return null;
        } catch (Throwable $exception) {
            $this->uncertain($exception, 'Template editor could not be opened');

            return null;
        } finally {
            $this->mounting = false;
        }
    }

    public function captureTemplateReview(LicenseTemplate $record): array
    {
        $action = $this->getMountedAction();
        abort_unless($this->mounting && $action?->getName() === 'edit'
            && $action->getRecord() instanceof LicenseTemplate && $action->getRecord()->getKey() === $record->getKey(), 403);
        $this->templateReview = app(SaveLicenseTemplate::class)->review($record, $this->actor());
        $this->templateReviewContext = $this->reviewContext();

        return $this->templateReview;
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $review = $this->templateReview;
        $context = $this->templateReviewContext;
        // Consume before Filament validates the form or checks action visibility.
        $this->clearReview();
        $this->submitting = true;
        $action = null;
        try {
            $action = $this->getMountedAction();
            if (in_array($action?->getName(), ['create', 'edit'], true)) {
                $actor = $this->actor();
                if (! $action->isAuthorized()) {
                    throw new AuthorizationException;
                }
                if ($arguments !== [] || $context === null || $context !== $this->reviewContext() || $action->isDisabled()) {
                    $this->reviewRequired();
                }
                if ($action->getName() === 'edit') {
                    $record = $action->getRecord();
                    if (! $record instanceof LicenseTemplate || $review === null
                        || ($review['template_id'] ?? null) !== (int) $record->getKey()
                        || ($review['actor_id'] ?? null) !== (int) $actor->getKey()) {
                        $this->reviewRequired();
                    }
                    if (LicenseTemplateResource::frozen($record)) {
                        $this->throwFormErrors(ValidationException::withMessages(['name' => SaveLicenseTemplate::FROZEN_MESSAGE]));
                    }
                    $this->submittedReview = $review;
                }
            }

            return parent::callMountedAction($arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            if ($action?->getName() === 'create') {
                // New creation can correct invalid input; it has no mutable-record baseline.
                $this->templateReviewContext = $context;
            } else {
                Notification::make()->danger()->title('Reopen template to continue')
                    ->body('Close and reopen the current template before saving again. '.SaveLicenseTemplate::FROZEN_MESSAGE)->persistent()->send();
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->uncertain($exception, 'The save result could not be confirmed.');

            return null;
        } finally {
            $this->submittedReview = null;
            $this->submitting = false;
        }
    }

    public function createTemplate(array $data, Action $action): LicenseTemplate
    {
        abort_unless($this->submitting && $this->getMountedAction() === $action && $action->getName() === 'create', 403);
        try {
            return app(SaveLicenseTemplate::class)->create($data, $this->actor());
        } catch (ValidationException $exception) {
            $this->throwFormErrors($exception);
        }
    }

    public function updateTemplate(LicenseTemplate $record, array $data, Action $action): LicenseTemplate
    {
        $review = $this->submittedReview;
        $this->submittedReview = null;
        abort_unless($this->submitting && $this->getMountedAction() === $action && $action->getName() === 'edit', 403);
        if ($review === null || ($review['template_id'] ?? null) !== (int) $record->getKey()
            || ! $action->getRecord() instanceof LicenseTemplate || $action->getRecord()->getKey() !== $record->getKey()) {
            $this->reviewRequired();
        }
        try {
            $saved = app(SaveLicenseTemplate::class)->updateReviewed($review, $data, $this->actor());
            $action->record($saved);

            return $saved;
        } catch (ValidationException $exception) {
            $this->throwFormErrors($exception);
        }
    }

    private function reviewRequired(): never
    {
        $this->throwFormErrors(ValidationException::withMessages(['name' => 'Close and reopen the current template before saving.']));
    }

    private function throwFormErrors(ValidationException $exception): never
    {
        $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $visible = in_array($field, SaveLicenseTemplate::FIELDS, true) ? $field : 'name';
            $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
        }
        throw ValidationException::withMessages($errors);
    }

    private function reviewContext(): array
    {
        return ['actor_id' => (int) $this->actor()->id, 'actions' => array_map(fn (array $action): array => [
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
        if ($root === 'mountedActions' && preg_match('/\AmountedActions\.([0-9]+)\.data(?:\.(name|slug|type))?\z/D', $property, $matches)
            && (int) $matches[1] === array_key_last($this->mountedActions)
            && (isset($matches[2]) || (is_array($value) && array_diff(array_keys($value), SaveLicenseTemplate::FIELDS) === []))) {
            return;
        }
        if (in_array($root, ['mountedActions', 'tableSearch', 'tableColumnSearches', 'tableFilters', 'tableDeferredFilters',
            'tableSort', 'tableRecordsPerPage', 'paginators'], true)) {
            $this->clearReview();
        }
    }

    public function updatingPaginators(mixed $page, string $pageName): void
    {
        if ($pageName === $this->getTablePaginationPageName()) {
            $this->clearReview();
        }
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->clearReview();
        parent::unmountAction($cancelParentActions);
    }

    private function clearReview(): void
    {
        $this->templateReview = null;
        $this->templateReviewContext = null;
        $this->submittedReview = null;
    }

    private function uncertain(Throwable $exception, string $title): void
    {
        $this->unmountAction();
        try {
            Log::warning('License template UI could not confirm the result.', ['exception_class' => $exception::class]);
        } catch (Throwable) {
            // Logging failure must not expose content or imply a known mutation outcome.
        }
        Notification::make()->danger()->title($title)->body('Reload templates and inspect the current saved identity before trying again.')->persistent()->send();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->databaseTransaction(false)->createAnother(false)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->modalDescription('Create a template identity. Author and review its license versions separately; creating a template does not publish terms or offers.')
            ->using(fn (array $data, Action $action) => $this->createTemplate($data, $action))];
    }
}
