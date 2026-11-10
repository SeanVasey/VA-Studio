<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\ChangeTrackVisibility;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\ReadPrivateTrackReview;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Catalog\TrackPublicationSchedules;
use App\Domain\Catalog\TrackVisibility;
use App\Domain\Rights\CollaboratorSplitReport;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class TrackResource extends OperatorResource
{
    protected static ?string $model = Track::class;

    public static function metadataModalAttributes(): array
    {
        return [
            'tabindex' => '-1', 'autofocus' => true,
            // WebKit can reject the focus trap's initial attempt during the opening transition.
            // Retry only after that transition, without stealing focus from a field already in use.
            'x-on:transitionend.self' => 'if (isOpen && isWindowVisible && !$el.contains(document.activeElement)) $el.focus({ preventScroll: true })',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('metadata_version')->dehydrated(fn (?Track $record) => $record !== null),
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true)->maxLength(255)
                ->disabled(fn (?Track $record) => $record?->published_slug !== null)
                ->helperText(fn (?Track $record) => $record?->published_slug !== null ? 'This URL stays reserved, including after unpublishing.' : 'You can change this URL until the track is first published.'),
            ...static::metadataFields(includeDuration: true),
        ]);
    }

    /** Reusable metadata inputs; publication, media, rights and offers have their own actions. */
    public static function metadataFields(bool $includeDuration = false): array
    {
        return [
            TextInput::make('artist')->required()->default('VASEY.AUDIO')->maxLength(255),
            TextInput::make('bpm')->integer()->minValue(20)->maxValue(400),
            TextInput::make('musical_key')->maxLength(24),
            TextInput::make('genre')->maxLength(255),
            TextInput::make('mood')->maxLength(255),
            ...($includeDuration ? [TextInput::make('duration_seconds')->disabled()->dehydrated(false)->helperText('Measured from the verified preview after processing.')] : []),
            TagsInput::make('tags')->rules(['nullable', 'array', 'list', 'max:20'])->nestedRecursiveRules(['required', 'string', 'max:80', 'distinct']),
            Textarea::make('description')->columnSpanFull()->maxLength(10000),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('bpm'), TextColumn::make('musical_key'), TextColumn::make('genre'),
            TextColumn::make('status')->badge()
                ->color(fn (string $state): string => match ($state) {
                    TrackVisibility::PUBLISHED => 'success', TrackVisibility::UNLISTED => 'info', TrackVisibility::SCHEDULED => 'warning',
                    default => 'gray',
                }),
            // Scheduled tracks show their Central time, and an overdue one shows why it has not gone live: that is the staff alert.
            TextColumn::make('schedule')->label('Schedule')->state(fn (Track $record): ?string => static::scheduleSummary($record))
                ->wrap()->color(fn (Track $record): ?string => $record->pendingSchedule?->last_failure_reason !== null ? 'danger' : null),
        ])->selectCurrentPageOnly()->maxSelectableRecords(25)->toolbarActions([
            // Keep Filament's Alpine selection transport; the domain loads and locks the exact bounded IDs itself.
            BulkAction::make('addTags')->label('Add tags')->fetchSelectedRecords(false)
                ->modalHeading('Add tags to selected tracks')->modalSubmitActionLabel('Review additions')
                ->extraModalWindowAttributes(static::metadataModalAttributes())
                ->schema([TagsInput::make('additions')->label('Tags to add')->required()
                    ->rules(['array', 'list', 'min:1', 'max:20'])->nestedRecursiveRules(['required', 'string', 'max:80', 'distinct'])
                    ->helperText('Add exact tags to up to 25 selected tracks. Existing tags stay in order; each track can contain at most 20 tags.')])
                ->fillForm(fn (ManageTracks $livewire) => ['additions' => $livewire->lastTagAdditions])
                ->action(fn (array $data, ManageTracks $livewire) => $livewire->reviewTagAdditions($data)),
            BulkAction::make('editMetadata')->label('Edit metadata')->fetchSelectedRecords(false)
                ->modalHeading('Edit metadata for selected tracks')->modalSubmitActionLabel('Review metadata changes')
                ->extraModalWindowAttributes(static::metadataModalAttributes())
                ->modalDescription('Choose what changes for up to 25 selected tracks on this page. Review every current and proposed value before saving.')
                ->schema(static::bulkMetadataFields())
                ->fillForm(fn (ManageTracks $livewire): array => $livewire->lastMetadataChoices === []
                    ? ['changes' => array_fill_keys(array_keys(static::bulkMetadataLabels()), ['mode' => 'keep'])]
                    : $livewire->lastMetadataChoices)
                ->action(fn (array $data, ManageTracks $livewire) => $livewire->reviewMetadataChanges($data)),
        ])->recordActions([
            Action::make('reviewTrack')->label('Review track')->modalHeading('Private track review')
                ->extraModalWindowAttributes(static::metadataModalAttributes())
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(function (Track $record) {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    // Read and authorize now, before returning the view. No projection is retained in Livewire state.
                    $review = app(ReadPrivateTrackReview::class)->handle((int) $record->id, $actor);

                    return view('filament.catalog.private-track-review', ['review' => $review]);
                }),
            EditAction::make()->extraModalWindowAttributes(static::metadataModalAttributes())
                ->using(fn (Track $record, array $data, ManageTracks $livewire) => static::saveMetadata($record, $data, $livewire)),
            Action::make('readiness')->label('Check readiness')->action(function (Track $record) {
                $blockers = app(PublicationReadiness::class)->blockers($record);
                // Incomplete collaborator splits are reported here and never change the verdict.
                $advisories = app(CollaboratorSplitReport::class)->advisories((int) $record->id);
                Notification::make()->title($blockers === [] ? 'Ready to publish' : 'Publication blocked')->body(implode("\n", [...$blockers, ...$advisories]))->persistent()->send();
            }),
            Action::make('publish')->label('Publish')->visible(fn (Track $record) => in_array($record->status, [TrackVisibility::DRAFT, TrackVisibility::UNLISTED], true))->requiresConfirmation()->databaseTransaction(false)
                ->modalDescription('Publish the reviewed track, files and offers to the catalog. If anything changes after this review, reopen the confirmation to review it again.')
                ->mountUsing(function (Track $record, ManageTracks $livewire, ?Schema $schema = null): void {
                    $livewire->reviewPublication($record, 'publish');
                    $schema?->fill();
                })
                ->action(fn (Track $record, Action $action, ManageTracks $livewire) => $livewire->applyReviewedPublication($record, $action, 'publish')),
            Action::make('publish_unlisted')->label('Publish unlisted')->visible(fn (Track $record) => $record->status === TrackVisibility::DRAFT)->requiresConfirmation()->databaseTransaction(false)
                ->modalDescription('Serve the reviewed track at its own link without listing it in the catalog, search or sitemaps. It can be bought by anyone with the link, under the same checks as a published track.')
                ->mountUsing(function (Track $record, ManageTracks $livewire, ?Schema $schema = null): void {
                    $livewire->reviewPublication($record, 'publish_unlisted');
                    $schema?->fill();
                })
                ->action(fn (Track $record, Action $action, ManageTracks $livewire) => $livewire->applyReviewedPublication($record, $action, 'publish_unlisted')),
            Action::make('schedule')->label(fn (Track $record) => $record->status === TrackVisibility::SCHEDULED ? 'Reschedule' : 'Schedule')
                ->visible(fn (Track $record) => in_array($record->status, [TrackVisibility::DRAFT, TrackVisibility::SCHEDULED], true))
                ->modalHeading('Schedule publication')
                ->modalDescription('Enter Central time (America/Chicago). Every publication check runs again at that moment; if one fails the track stays scheduled and an alert is raised.')
                ->extraModalWindowAttributes(static::metadataModalAttributes())
                ->fillForm(fn (Track $record): array => [
                    'metadata_version' => $record->metadata_version, 'publication_version' => $record->publication_version,
                    'target' => $record->pendingSchedule?->target_status ?? TrackVisibility::PUBLISHED,
                    'local_time' => $record->pendingSchedule?->entry_local ? str_replace(' ', 'T', $record->pendingSchedule->entry_local) : null,
                ])
                ->schema([
                    Hidden::make('metadata_version'), Hidden::make('publication_version'),
                    Select::make('target')->label('Publish as')->native()->required()->selectablePlaceholder(false)
                        ->options([TrackVisibility::PUBLISHED => 'Published (listed in the catalog)', TrackVisibility::UNLISTED => 'Unlisted (link only)']),
                    TextInput::make('local_time')->label('Central time')->type('datetime-local')->required()
                        ->helperText('The instant is stored in UTC. Times that do not exist or happen twice on a clock-change night are refused.'),
                ])
                ->action(fn (Track $record, array $data, ManageTracks $livewire) => $livewire->scheduleTrack($record, $data)),
            Action::make('cancel_schedule')->label('Cancel schedule')->color('gray')->visible(fn (Track $record) => $record->status === TrackVisibility::SCHEDULED)
                ->requiresConfirmation()->databaseTransaction(false)
                ->modalDescription('The track returns to draft and will not go live at the scheduled time.')
                ->fillForm(fn (Track $record): array => ['metadata_version' => $record->metadata_version, 'publication_version' => $record->publication_version])
                ->schema([Hidden::make('metadata_version'), Hidden::make('publication_version')])
                ->action(fn (Track $record, array $data, Action $action, ManageTracks $livewire) => $livewire->cancelTrackSchedule($record, $data, $action)),
            Action::make('visibility')->label('Change visibility')->color('gray')
                ->visible(fn (Track $record) => ChangeTrackVisibility::targets((string) $record->status) !== [])
                ->modalHeading('Change visibility')
                ->modalDescription('Unlisted keeps the track at its link but out of the catalog, search and sitemaps. Private is staff only: never served and never purchasable.')
                ->fillForm(fn (Track $record): array => ['metadata_version' => $record->metadata_version, 'publication_version' => $record->publication_version])
                ->schema(fn (Track $record): array => [
                    Hidden::make('metadata_version'), Hidden::make('publication_version'),
                    Select::make('target')->label('Make this track')->native()->required()
                        ->options(array_combine(ChangeTrackVisibility::targets((string) $record->status), array_map('ucfirst', ChangeTrackVisibility::targets((string) $record->status)))),
                ])
                ->action(fn (Track $record, array $data, Action $action, ManageTracks $livewire) => $livewire->changeTrackVisibility($record, $data, $action)),
            Action::make('share')->visible(fn (Track $record) => TrackVisibility::isLive($record->status))->url(fn (Track $record) => route('tracks.show', $record->slug))->openUrlInNewTab(),
            Action::make('unpublish')->label('Return to draft')->visible(fn (Track $record) => TrackVisibility::isLive($record->status))->requiresConfirmation()->databaseTransaction(false)
                ->mountUsing(function (Track $record, ManageTracks $livewire, ?Schema $schema = null): void {
                    $livewire->reviewPublication($record, 'unpublish');
                    $schema?->fill();
                })
                ->action(fn (Track $record, Action $action, ManageTracks $livewire) => $livewire->applyReviewedPublication($record, $action, 'unpublish')),
        ]);
    }

    /** Central wall time of the pending schedule, with the stored failure reason when a due schedule could not publish. */
    public static function scheduleSummary(Track $record): ?string
    {
        $schedule = $record->status === TrackVisibility::SCHEDULED ? $record->pendingSchedule : null;
        if ($schedule === null) {
            return null;
        }
        $line = ucfirst($schedule->target_status).' at '.TrackPublicationSchedules::describe($schedule->publish_at);
        if ($schedule->last_failure_reason !== null) {
            $line .= ' — NOT PUBLISHED: '.$schedule->last_failure_reason.' (checked '.$schedule->attempts.'x)';
        }

        return $line;
    }

    public static function bulkMetadataLabels(): array
    {
        return ['artist' => 'Artist', 'bpm' => 'BPM', 'musical_key' => 'Musical key', 'genre' => 'Genre', 'mood' => 'Mood'];
    }

    public static function bulkMetadataFields(): array
    {
        $fields = [];
        foreach (static::bulkMetadataLabels() as $field => $label) {
            $isSet = fn (Get $get): bool => $get('changes.'.$field.'.mode') === 'set';
            $input = TextInput::make('changes.'.$field.'.value')->label($label.' value')
                ->visible($isSet)->required($isSet)->dehydrated($isSet);
            if ($field === 'bpm') {
                $input->integer()->minValue(20)->maxValue(400);
            } else {
                $input->maxLength($field === 'musical_key' ? 24 : 255);
            }
            $fields[] = Section::make($label)->columns(2)->schema([
                Select::make('changes.'.$field.'.mode')->label($label.' change')->native()->required()->live()
                    ->options(['keep' => 'Keep', 'set' => 'Set', ...($field === 'artist' ? [] : ['clear' => 'Clear'])])
                    ->default('keep')->selectablePlaceholder(false)
                    ->helperText($field === 'artist' ? 'Keep the current artist or set a required artist value.' : 'Keep the current value, set a value, or clear it.'),
                $input,
            ]);
        }

        return $fields;
    }

    public static function saveMetadata(?Track $record, array $data, ManageTracks $livewire): Track
    {
        try {
            return app(SaveTrackMetadata::class)->handle($record, $data, auth()->user());
        } catch (ValidationException $exception) {
            // Domain keys are relative. Filament needs the actual mounted schema path,
            // including nesting, to render messages beside fields instead of hiding them.
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $visibleField = $field === 'metadata_version' ? 'title' : $field;
                $errors[$path.'.'.$visibleField] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageTracks::route('/')];
    }
}
