<?php

namespace App\Filament\Resources\TrackResource\Pages;

use App\Domain\Catalog\BulkAddTrackTags;
use App\Domain\Catalog\BulkUpdateTrackMetadata;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\TrackMetadataPresets;
use App\Filament\Resources\TrackResource;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class ManageTracks extends ManageRecords
{
    protected static string $resource = TrackResource::class;

    #[Locked]
    public ?array $bulkTagReview = null;

    #[Locked]
    public array $lastTagAdditions = [];

    #[Locked]
    public ?array $bulkMetadataReview = null;

    #[Locked]
    public array $lastMetadataChoices = [];

    #[Locked]
    public ?array $bulkMetadataTableContext = null;

    #[Locked]
    public ?array $publicationReview = null;

    #[Locked]
    public ?array $publicationTableContext = null;

    /** This is the copy shown in the draft form, never a live link to the preset. */
    #[Locked]
    public ?array $presetMetadataSnapshot = null;

    /** Request-local options only; a later request always checks current staff authority. */
    protected ?array $activeMetadataPresets = null;

    public function boot(): void
    {
        request()->attributes->set('_track_private_review', true);
        $this->activeMetadataPresets = null;
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

    public function reviewPublication(Track $record, string $intent): void
    {
        $this->publicationReview = null;
        $this->publicationTableContext = null;
        $action = $this->getMountedAction();
        if (! in_array($intent, ['publish', 'unpublish'], true) || $action?->getName() !== $intent
            || ! ($action->getRecord() instanceof Track) || (int) $action->getRecord()->id !== (int) $record->id) {
            throw ValidationException::withMessages(['publication' => 'Open the current track confirmation again.']);
        }
        // Filament invokes this mount callback before opening the confirmation. Never recreate it during submit.
        $this->publicationReview = app(PublishTrack::class)->review($record, $this->actor(), $intent);
        $this->publicationTableContext = $this->metadataTableContext();
    }

    public function applyReviewedPublication(Track $record, Action $action, string $intent): void
    {
        $review = $this->publicationReview;
        $context = $this->publicationTableContext;
        $this->publicationReview = null; // Consume before authority checks, validation or a possible uncertain result.
        $this->publicationTableContext = null;
        try {
            $actor = $this->actor();
            if ($review === null || $context !== $this->metadataTableContext()
                || ! in_array($intent, ['publish', 'unpublish'], true) || $this->getMountedAction() !== $action
                || $action->getName() !== $intent || ! ($action->getRecord() instanceof Track)
                || (int) $action->getRecord()->id !== (int) $record->id
                || ($review['actor_id'] ?? null) !== (int) $actor->id
                || ($review['track_id'] ?? null) !== (int) $record->id || ($review['intent'] ?? null) !== $intent) {
                throw ValidationException::withMessages(['publication' => 'The confirmation changed. Open the current track confirmation again.']);
            }
            if ($intent === 'publish') {
                app(PublishTrack::class)->publishReviewed($review, $actor);
            } else {
                app(PublishTrack::class)->unpublishReviewed($review, $actor);
            }
        } catch (ValidationException $exception) {
            // Confirmations have no metadata form fields in which to display domain errors.
            Notification::make()->danger()->title('Publication blocked')
                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
            $action->cancel();
        }
    }

    public function reviewTagAdditions(array $data): void
    {
        $this->bulkTagReview = null;
        try {
            if (array_diff(array_keys($data), ['additions']) || ! is_array($data['additions'] ?? null)) {
                throw ValidationException::withMessages(['additions' => 'Enter the tags to add.']);
            }
            $review = app(BulkAddTrackTags::class)->review($this->selectedTagTrackIds(), $data['additions'], $this->actor());
        } catch (ValidationException $exception) {
            $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
            throw ValidationException::withMessages([$path.'.additions' => array_merge(...array_values($exception->errors()))]);
        }
        $this->lastTagAdditions = $review['additions'];
        $this->bulkTagReview = $review;
        $this->replaceMountedAction('reviewTagAdditions');
        $this->forceRender();
    }

    public function reviewTagAdditionsAction(): Action
    {
        return Action::make('reviewTagAdditions')->modalHeading('Review tag additions')
            ->disabled(fn () => $this->bulkTagReview === null)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->modalContent(fn () => view('filament.catalog.review-tag-additions', ['review' => $this->bulkTagReview]))
            ->modalSubmitActionLabel('Add reviewed tags')->modalCancelAction(false)
            ->extraModalFooterActions([Action::make('backToAdditions')->label('Back to additions')->color('gray')
                ->action(fn () => $this->backToTagAdditions())])
            ->action(fn () => $this->applyReviewedTagAdditions());
    }

    public function backToTagAdditions(): void
    {
        $this->bulkTagReview = null;
        $this->replaceMountedAction('addTags', context: ['table' => true, 'bulk' => true]);
        $this->forceRender();
    }

    public function applyReviewedTagAdditions(): void
    {
        $review = $this->bulkTagReview;
        $this->bulkTagReview = null; // Consume this review once, including when the result cannot be confirmed.
        try {
            if ($review === null || $this->selectedTagTrackIds() !== array_column($review['tracks'], 'id')) {
                throw ValidationException::withMessages(['additions' => 'The selection changed. Select and review the current tracks again.']);
            }
            $result = app(BulkAddTrackTags::class)->apply($review, $this->actor());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('No changes were saved by this attempt.')
                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
            $this->backToTagAdditions();

            return;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('The save result could not be confirmed.')
                ->body('Reload tracks and review current tags before trying again.')->persistent()->send();
            // Do not offer a retry based on an old review after a database or transport uncertainty.
            $this->lastTagAdditions = [];

            return;
        }
        $changed = count($result['changed_ids']);
        $unchanged = count($result['unchanged_ids']);
        Notification::make()->success()->title($changed === 0
            ? "No tags were added. All {$unchanged} reviewed tracks already contained these tags."
            : "Tags added to {$changed} tracks. {$unchanged} tracks already contained these tags.")->send();
        $this->lastTagAdditions = [];
        $this->deselectAllTableRecords();
    }

    private function selectedTagTrackIds(): array
    {
        if ($this->isTrackingDeselectedTableRecords || ! array_is_list($this->selectedTableRecords) || count($this->selectedTableRecords) > BulkAddTrackTags::MAX_TRACKS) {
            throw ValidationException::withMessages(['additions' => 'Select up to 25 explicit tracks on the current page.']);
        }
        $ids = [];
        foreach ($this->selectedTableRecords as $id) {
            if ((! is_int($id) && (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/D', $id))) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
                throw ValidationException::withMessages(['additions' => 'Select valid track IDs.']);
            }
            $ids[] = (int) $id;
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function reviewMetadataChanges(array $data): void
    {
        $this->bulkMetadataReview = null;
        $this->bulkMetadataTableContext = null;
        try {
            if (array_diff(array_keys($data), ['changes']) || ! is_array($data['changes'] ?? null)) {
                throw ValidationException::withMessages(['changes' => 'Choose the metadata changes to review.']);
            }
            // Pass the exact proposal. The domain rejects unexpected keys; hidden Keep/Clear values are not dehydrated by the form.
            $review = app(BulkUpdateTrackMetadata::class)->review($this->selectedMetadataTrackIds(), $data['changes'], $this->actor());
        } catch (ValidationException $exception) {
            if ($this->getMountedAction() === null) {
                throw $exception;
            }
            $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $visible = preg_match('/\Achanges\.(artist|bpm|musical_key|genre|mood)\.(mode|value)\z/D', $field) ? $field : 'changes.artist.mode';
                if (str_ends_with($visible, '.value') && data_get($data, str_replace('.value', '.mode', $visible)) !== 'set') {
                    $visible = str_replace('.value', '.mode', $visible);
                }
                $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
            }
            throw ValidationException::withMessages($errors);
        }
        $this->lastMetadataChoices = ['changes' => $review['changes']];
        $this->bulkMetadataReview = $review;
        $this->bulkMetadataTableContext = $this->metadataTableContext();
        $this->replaceMountedAction('reviewMetadataChanges');
        $this->forceRender();
    }

    public function reviewMetadataChangesAction(): Action
    {
        return Action::make('reviewMetadataChanges')->modalHeading('Review metadata changes')
            ->disabled(fn (): bool => $this->bulkMetadataReview === null)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->modalContent(fn () => view('filament.catalog.review-track-metadata', ['review' => $this->bulkMetadataReview]))
            ->modalSubmitActionLabel('Save reviewed metadata')->modalCancelActionLabel('Cancel')
            ->extraModalFooterActions([Action::make('backToMetadataChanges')->label('Back to metadata choices')->color('gray')
                ->action(fn () => $this->backToMetadataChanges())])
            ->action(fn () => $this->applyReviewedMetadataChanges());
    }

    public function backToMetadataChanges(): void
    {
        $this->bulkMetadataReview = null;
        $this->bulkMetadataTableContext = null;
        $this->replaceMountedAction('editMetadata', context: ['table' => true, 'bulk' => true]);
        $this->forceRender();
    }

    public function applyReviewedMetadataChanges(): void
    {
        $review = $this->bulkMetadataReview;
        $context = $this->bulkMetadataTableContext;
        $this->bulkMetadataReview = null; // Consume once, even if the database outcome is unknown.
        $this->bulkMetadataTableContext = null;
        try {
            if ($review === null || $context !== $this->metadataTableContext()
                || $this->selectedMetadataTrackIds() !== array_column($review['tracks'], 'id')) {
                throw ValidationException::withMessages(['changes' => 'The selection or table view changed. Select and review the current tracks again.']);
            }
            $result = app(BulkUpdateTrackMetadata::class)->apply($review, $this->actor());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('No changes were saved by this attempt.')
                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
            $this->backToMetadataChanges();

            return;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('The save result could not be confirmed.')
                ->body('Reload tracks and review current metadata before trying again.')->persistent()->send();
            $this->lastMetadataChoices = [];

            return;
        }
        $changed = count($result['changed_ids']);
        $unchanged = count($result['unchanged_ids']);
        Notification::make()->success()->title($changed === 0
            ? "No metadata changed. All {$unchanged} reviewed tracks already matched these choices."
            : "Metadata saved for {$changed} tracks. {$unchanged} tracks already matched these choices.")->send();
        $this->lastMetadataChoices = [];
        $this->deselectAllTableRecords();
    }

    private function selectedMetadataTrackIds(): array
    {
        if ($this->isTrackingDeselectedTableRecords || ! array_is_list($this->selectedTableRecords)
            || count($this->selectedTableRecords) < 1 || count($this->selectedTableRecords) > 25) {
            throw ValidationException::withMessages(['changes' => 'Select between 1 and 25 explicit tracks on the current page.']);
        }
        $ids = [];
        foreach ($this->selectedTableRecords as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $id)
                || (string) (int) $id !== (string) $id) {
                throw ValidationException::withMessages(['changes' => 'Select valid track IDs on the current page.']);
            }
            $ids[] = (int) $id;
        }
        if (count(array_unique($ids, SORT_NUMERIC)) !== count($ids)) {
            throw ValidationException::withMessages(['changes' => 'Select distinct tracks on the current page.']);
        }
        if (! in_array((string) $this->getTableRecordsPerPage(), ['5', '10', '25', '50'], true)) {
            throw ValidationException::withMessages(['changes' => 'Choose a supported table page size, then select the current tracks again.']);
        }
        $this->flushCachedTableRecords();
        $visibleIds = [];
        foreach ($this->getTableRecords() as $track) {
            $visibleIds[] = (int) $track->id;
        }
        if (array_diff($ids, $visibleIds)) {
            throw ValidationException::withMessages(['changes' => 'Select only tracks visible on the current filtered table page.']);
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function metadataTableContext(): array
    {
        return ['page' => (string) $this->getTablePage(), 'per_page' => (string) $this->getTableRecordsPerPage(),
            'search' => $this->tableSearch, 'column_searches' => $this->tableColumnSearches,
            'filters' => $this->tableFilters, 'deferred_filters' => $this->tableDeferredFilters, 'sort' => $this->tableSort];
    }

    public function updating(string $property, mixed $value): void
    {
        $root = explode('.', $property)[0];
        if (in_array($root, ['selectedTableRecords', 'deselectedTableRecords', 'isTrackingDeselectedTableRecords', 'mountedActions',
            'tableSearch', 'tableColumnSearches', 'tableFilters', 'tableDeferredFilters', 'tableSort', 'tableRecordsPerPage', 'paginators'], true)) {
            $this->invalidatePublicationReview();
            $this->invalidateMetadataReview();
        }
    }

    public function updatingPaginators(mixed $page, string $pageName): void
    {
        if ($pageName === $this->getTablePaginationPageName()) {
            $this->invalidatePublicationReview();
            $this->invalidateMetadataReview();
        }
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        $this->publicationReview = null;
        $this->publicationTableContext = null;
        if (! in_array($name, ['reviewMetadataChanges', 'backToMetadataChanges'], true)) {
            $this->invalidateMetadataReview();
        }

        return parent::mountAction($name, $arguments, $context);
    }

    private function invalidatePublicationReview(): void
    {
        $hadReview = $this->publicationReview !== null;
        $this->publicationReview = null;
        $this->publicationTableContext = null;
        if ($hadReview) {
            $this->unmountAction();
        }
    }

    private function invalidateMetadataReview(): void
    {
        $hadReview = $this->bulkMetadataReview !== null;
        $this->bulkMetadataReview = null;
        $this->bulkMetadataTableContext = null;
        if ($hadReview) {
            $this->unmountAction();
        }
    }

    public function updatedSelectedTableRecords(): void
    {
        $this->invalidateTagReview();
    }

    public function updatedDeselectedTableRecords(): void
    {
        $this->invalidateTagReview();
    }

    public function updatedIsTrackingDeselectedTableRecords(): void
    {
        $this->invalidateTagReview();
    }

    public function updatedMountedActions(): void
    {
        $this->invalidatePublicationReview();
        $this->invalidateTagReview();
        if (($this->mountedActions[array_key_last($this->mountedActions)]['name'] ?? null) !== 'createPresetDraft') {
            $this->presetMetadataSnapshot = null;
        }
    }

    private function invalidateTagReview(): void
    {
        if ($this->bulkTagReview !== null) {
            $this->bulkTagReview = null;
            $this->unmountAction();
        }
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->bulkTagReview = null;
        $this->bulkMetadataReview = null;
        $this->bulkMetadataTableContext = null;
        $this->publicationReview = null;
        $this->publicationTableContext = null;
        $this->presetMetadataSnapshot = null;
        parent::unmountAction($cancelParentActions);
    }

    public function activePresets(): array
    {
        return $this->activeMetadataPresets ??= app(TrackMetadataPresets::class)->active($this->actor());
    }

    public function createFromPresetAction(): Action
    {
        return Action::make('createFromPreset')->label('Create from preset')->modalHeading('Create from preset')
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->modalDescription('Choose saved metadata, then review and edit the new private draft before creating it.')
            ->modalSubmitActionLabel('Copy metadata')
            ->schema([Select::make('preset_id')->label('Metadata preset')->native()->required()
                ->options(fn (): array => array_column(array_map(fn (array $preset): array => [
                    'id' => $preset['id'], 'label' => $preset['name'].' (#'.$preset['id'].')',
                ], $this->activePresets()), 'label', 'id'))
                ->placeholder(fn (): string => $this->activePresets() === [] ? 'No active presets' : 'Choose an active preset')
                ->helperText(fn (): string => $this->activePresets() === []
                    ? 'No active metadata presets are available. Create a preset in Track metadata presets, or use New track.'
                    : 'Copies artist, BPM, musical key, genre, mood, tags and description. Title and URL are entered on the next step.')])
            ->fillForm(function (): array {
                $this->presetMetadataSnapshot = null;

                return [];
            })
            ->action(fn (array $data) => $this->copyPresetMetadata($data));
    }

    public function copyPresetMetadata(array $data): void
    {
        $this->presetMetadataSnapshot = null;
        try {
            $id = $data['preset_id'] ?? null;
            if (array_diff(array_keys($data), ['preset_id']) || (! is_int($id) && ! is_string($id))
                || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $id) || (string) (int) $id !== (string) $id) {
                throw ValidationException::withMessages(['preset_id' => 'Choose an active metadata preset.']);
            }
            // Read the current snapshot now. Subsequent changes or archival cannot replace this visible copy.
            $snapshot = app(TrackMetadataPresets::class)->snapshot((int) $id, $this->actor());
        } catch (ValidationException $exception) {
            $this->throwPresetFormErrors($exception, 'preset_id');
        }
        $this->presetMetadataSnapshot = $snapshot;
        $this->replaceMountedAction('createPresetDraft');
        $this->forceRender();
    }

    public function createPresetDraftAction(): CreateAction
    {
        return CreateAction::make('createPresetDraft')->label('Create private draft')->createAnother(false)
            ->disabled(fn (): bool => $this->presetMetadataSnapshot === null)
            ->modalHeading('Create private draft from preset')->modalSubmitActionLabel('Create private draft')
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->modalDescription(fn (): string => 'Metadata copied from '.($this->presetMetadataSnapshot['name'] ?? 'a preset').'. Review these independent fields and enter a title and URL. Creating this draft does not publish it.')
            ->schema(fn (Schema $schema): Schema => TrackResource::form($schema))
            ->fillForm(fn (): array => ['title' => '', 'slug' => '', ...($this->presetMetadataSnapshot['metadata'] ?? [])])
            ->using(fn (array $data) => $this->createPresetDraft($data));
    }

    public function createPresetDraft(array $data): Track
    {
        abort_unless($this->presetMetadataSnapshot !== null, 403);
        try {
            // Only the reviewed form is passed to the creator; it never re-reads or merges the source preset.
            return app(TrackMetadataPresets::class)->createDraft($data, $this->actor());
        } catch (ValidationException $exception) {
            $this->throwPresetFormErrors($exception);
        }
    }

    private function throwPresetFormErrors(ValidationException $exception, ?string $field = null): never
    {
        if ($this->getMountedAction() === null) {
            throw $exception;
        }
        $path = $this->getSchema($this->getMountedActionSchemaName())->getStatePath();
        $errors = [];
        foreach ($exception->errors() as $key => $messages) {
            $visible = $field ?? ($key === 'metadata_version' ? 'title' : $key);
            $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
        }
        throw ValidationException::withMessages($errors);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->using(fn (array $data) => TrackResource::saveMetadata(null, $data, $this)), $this->createFromPresetAction()];
    }
}
