<?php

namespace App\Filament\Resources\TrackResource\Pages;

use App\Domain\Catalog\BulkAddTrackTags;
use App\Domain\Catalog\Models\Track;
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

    /** This is the copy shown in the draft form, never a live link to the preset. */
    #[Locked]
    public ?array $presetMetadataSnapshot = null;

    /** Request-local options only; a later request always checks current staff authority. */
    protected ?array $activeMetadataPresets = null;

    public function boot(): void
    {
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
