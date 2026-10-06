<?php

namespace App\Filament\Resources\OfferResource\Pages;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\ReviewedOfferDraft;
use App\Domain\Catalog\SaveOfferDraft;
use App\Filament\Resources\OfferResource;
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

class ManageOffers extends ManageRecords
{
    protected static string $resource = OfferResource::class;

    #[Locked]
    public ?array $offerReview = null;

    #[Locked]
    public ?array $offerReviewContext = null;

    private ?array $submittedOfferReview = null;

    private bool $mountingOffer = false;

    private bool $submittingOffer = false;

    private function offerActor(): User
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
        $this->clearOfferReview();
        if ($name !== 'edit') {
            return parent::mountAction($name, $arguments, $context);
        }
        $this->mountingOffer = true;
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException) {
            $this->unmountAction();
            Notification::make()->danger()->title('Offer editor could not be opened')
                ->body('Reload offers and inspect the current draft before opening the editor again.')->persistent()->send();

            return null;
        } catch (Throwable $exception) {
            $this->unmountAction();
            $this->logUncertain($exception);
            Notification::make()->danger()->title('Offer editor could not be opened')
                ->body('Reload offers before opening the editor again.')->persistent()->send();

            return null;
        } finally {
            $this->mountingOffer = false;
        }
    }

    public function captureOfferReview(Offer $record): array
    {
        $action = $this->getMountedAction();
        abort_unless($this->mountingOffer && $action?->getName() === 'edit'
            && $action->getRecord() instanceof Offer && $action->getRecord()->getKey() === $record->getKey(), 403);
        $this->offerReview = app(ReviewedOfferDraft::class)->review($record, $this->offerActor());
        $this->offerReviewContext = $this->reviewContext();

        return $this->offerReview;
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        $review = $this->offerReview;
        $context = $this->offerReviewContext;
        $this->clearOfferReview();
        $action = $this->getMountedAction();
        if ($action?->getName() !== 'edit') {
            return parent::callMountedAction($arguments);
        }
        $this->submittingOffer = true;
        try {
            $actor = $this->offerActor();
            if (! $action->isAuthorized()) {
                throw new AuthorizationException;
            }
            $record = $action->getRecord();
            if ($arguments !== [] || $context === null || $context !== $this->reviewContext() || $action->isDisabled()
                || ! $record instanceof Offer || $review === null
                || ($review['offer_id'] ?? null) !== (int) $record->getKey()
                || ($review['track_id'] ?? null) !== (int) $record->track_id
                || ($review['actor_id'] ?? null) !== (int) $actor->getKey()) {
                $this->reviewRequired();
            }
            $this->submittedOfferReview = $review;

            return parent::callMountedAction($arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->reopenNotification();
            throw $exception;
        } catch (Throwable $exception) {
            $this->logUncertain($exception);
            Notification::make()->danger()->title('The offer save could not be confirmed')
                ->body('Keep a copy of your entered changes, then close and reopen to inspect the saved draft before trying again.')->persistent()->send();
            $this->formErrors(ValidationException::withMessages(['offer' => 'The save result could not be confirmed. Close and reopen to inspect the saved draft before trying again.']));
        } finally {
            $this->submittedOfferReview = null;
            $this->submittingOffer = false;
        }
    }

    public function updateOfferDraft(Offer $record, array $data, Action $action): Offer
    {
        $review = $this->submittedOfferReview;
        $this->submittedOfferReview = null;
        abort_unless($this->submittingOffer && $this->getMountedAction() === $action && $action->getName() === 'edit', 403);
        if ($review === null || ($review['offer_id'] ?? null) !== (int) $record->getKey()
            || ! $action->getRecord() instanceof Offer || $action->getRecord()->getKey() !== $record->getKey()) {
            $this->reviewRequired();
        }
        try {
            $saved = app(ReviewedOfferDraft::class)->updateReviewed($review, $data, $this->offerActor());
            $action->record($saved);

            return $saved;
        } catch (ValidationException $exception) {
            $this->formErrors($exception);
        }
    }

    private function reviewRequired(): never
    {
        $this->formErrors(ValidationException::withMessages(['offer' => 'Close and reopen the current offer draft before saving.']));
    }

    private function formErrors(ValidationException $exception): never
    {
        $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $visible = in_array($field, ['offer', 'review', 'track_id'], true) ? 'price_minor' : $field;
            if (str_starts_with($visible, 'deliverable_asset_ids.')) {
                $visible = 'deliverable_asset_ids';
            }
            $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
        }
        $this->dispatch('form-validation-error', livewireId: $this->getId());
        throw ValidationException::withMessages($errors);
    }

    private function reviewContext(): array
    {
        return ['actor_id' => (int) $this->offerActor()->id, 'actions' => array_map(fn (array $action): array => [
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
        if ($root === 'mountedActions' && preg_match('/\AmountedActions\.([0-9]+)\.data(?:\.(license_version_id|price_minor|currency|deliverable_asset_ids|track_id)(?:\..*)?)?\z/D', $property, $matches)
            && (int) $matches[1] === array_key_last($this->mountedActions)) {
            if (isset($matches[2]) ? ($matches[2] !== 'track_id' || (string) $value === (string) ($this->offerReview['track_id'] ?? null))
                : (is_array($value) && array_diff(array_keys($value), [...ReviewedOfferDraft::FIELDS, 'track_id']) === []
                    && (! array_key_exists('track_id', $value) || (string) $value['track_id'] === (string) ($this->offerReview['track_id'] ?? null)))) {
                return;
            }
        }
        if (in_array($root, ['mountedActions', 'tableSearch', 'tableColumnSearches', 'tableFilters', 'tableDeferredFilters',
            'tableSort', 'tableRecordsPerPage', 'paginators'], true)) {
            $this->clearOfferReview();
        }
    }

    public function updatingPaginators(mixed $page, string $pageName): void
    {
        if ($pageName === $this->getTablePaginationPageName()) {
            $this->clearOfferReview();
        }
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->clearOfferReview();
        parent::unmountAction($cancelParentActions);
    }

    private function clearOfferReview(): void
    {
        $this->offerReview = null;
        $this->offerReviewContext = null;
        $this->submittedOfferReview = null;
    }

    private function reopenNotification(): void
    {
        Notification::make()->danger()->title('Reopen offer draft to continue')
            ->body('Your entered changes are still shown. Keep a copy, then close and reopen the current offer draft before saving again.')->persistent()->send();
    }

    private function logUncertain(Throwable $exception): void
    {
        try {
            Log::warning('Offer draft UI could not confirm the result.', ['exception_class' => $exception::class]);
        } catch (Throwable) {
            // Logging cannot disclose draft values or imply a known save outcome.
        }
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => app(SaveOfferDraft::class)->handle(null, $data, auth()->user()))];
    }
}
