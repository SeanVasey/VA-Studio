<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\Models\Track;
use App\Domain\Collections\Models\TrackCollection;
use App\Domain\Collections\TrackCollections;
use App\Filament\Resources\TrackCollectionResource\Pages\ManageTrackCollections;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Staff editor for public collections (BL-54, FP-006). Every write goes through TrackCollections; nothing is decided here. A
 * collection groups tracks for browsing and has no price: each track stays licensed individually. Collections are retained:
 * there is no delete, only unpublish.
 */
class TrackCollectionResource extends OperatorResource
{
    protected static ?string $model = TrackCollection::class;

    protected static ?string $slug = 'track-collections';

    protected static ?string $navigationLabel = 'Collections';

    protected static string|UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Current staff authority and MFA enrollment, re-read on every request. */
    public static function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User && AdminMultiFactor::satisfiedBy($actor), 403);
        Gate::forUser($actor)->authorize('administer-catalog');

        return $actor;
    }

    public static function getEloquentQuery(): Builder
    {
        static::actor();

        return parent::getEloquentQuery();
    }

    /** Run a domain command and show its refusals on the mounted form's fields. */
    public static function guarded(ManageRecords $livewire, callable $command): mixed
    {
        try {
            return $command(static::actor());
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())?->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[($path === null ? '' : $path.'.').$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'),
            TextInput::make('title')->required()->maxLength(160),
            TextInput::make('slug')->label('Web address')->required()->maxLength(80)->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                ->helperText('Lowercase letters, numbers and hyphens. The page will be /collections/ followed by this.'),
            Textarea::make('description')->rows(4)->maxLength(2000)->helperText('Optional. Plain text.'),
            Repeater::make('tracks')->label('Tracks')->addActionLabel('Add track')->maxItems(TrackCollections::MAX_TRACKS)->default([])->reorderable()
                ->schema([Select::make('track_id')->label('Track')->required()->searchable()->native(false)->options(fn (): array => static::trackOptions())])
                ->helperText('In display order; drag to reorder. A track that is not published is hidden from the public page without being removed here. Each track is still licensed on its own page: a collection has no price.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->description('Browse groupings of tracks for the public collections pages. A collection has no price, offer or bundle: every track is licensed individually. The pages stay off until a reviewed code change enables them.')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->label('Web address'),
                TextColumn::make('is_published')->label('Status')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Published' : 'Draft')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('items_count')->counts('items')->label('Tracks'),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('id', 'desc')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->modalHeading('Edit collection')->modalWidth('4xl')->databaseTransaction(false)
                    ->fillForm(fn (TrackCollection $record): array => static::formState($record))
                    ->using(fn (TrackCollection $record, array $data, ManageTrackCollections $livewire): TrackCollection => static::save($record, $data, $livewire, isset($data['revision']) ? (int) $data['revision'] : -1)),
                Action::make('publish')->label('Publish')->color('success')->requiresConfirmation()
                    ->fillForm(fn (TrackCollection $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription(fn (TrackCollection $record): string => ($blockers = app(TrackCollections::class)->blockers($record)) === []
                        ? 'The collection becomes visible once a reviewed code change enables the collections pages. Only its published tracks are ever shown.'
                        : 'Not ready: '.implode(' ', $blockers))
                    ->visible(fn (TrackCollection $record): bool => ! $record->is_published)
                    ->action(fn (TrackCollection $record, array $data, ManageTrackCollections $livewire) => static::transition($record, 'publish', $livewire, (int) ($data['revision'] ?? -1))),
                Action::make('unpublish')->label('Unpublish')->color('danger')->requiresConfirmation()
                    ->fillForm(fn (TrackCollection $record): array => ['revision' => $record->revision])->schema([Hidden::make('revision')])
                    ->modalDescription('The collection disappears from the collections pages and the sitemap. Its tracks are not changed.')
                    ->visible(fn (TrackCollection $record): bool => $record->is_published)
                    ->action(fn (TrackCollection $record, array $data, ManageTrackCollections $livewire) => static::transition($record, 'unpublish', $livewire, (int) ($data['revision'] ?? -1))),
            ])->emptyStateHeading('No collections')
            ->emptyStateDescription('Add a collection, choose and order its tracks, then publish it. Nothing is pre-filled.');
    }

    /** Create or edit: hands the exact fields, in the order the staff member arranged the tracks, to the domain. */
    public static function save(?TrackCollection $record, array $data, ManageRecords $livewire, ?int $expectedRevision = null): TrackCollection
    {
        return static::guarded($livewire, fn (User $actor): TrackCollection => app(TrackCollections::class)->save($record, [
            'title' => $data['title'] ?? null, 'slug' => $data['slug'] ?? null, 'description' => $data['description'] ?? null,
            'track_ids' => array_values(array_map(fn ($row) => $row['track_id'] ?? null, $data['tracks'] ?? [])),
        ], $actor, $record === null ? null : $expectedRevision));
    }

    private static function transition(TrackCollection $record, string $operation, ManageRecords $livewire, int $expectedRevision): void
    {
        static::guarded($livewire, fn (User $actor) => app(TrackCollections::class)->{$operation}($record, $expectedRevision, $actor));
        Notification::make()->success()->title($operation === 'publish' ? 'Collection published' : 'Collection unpublished')->send();
    }

    /** @return array<string, mixed> */
    private static function formState(TrackCollection $record): array
    {
        return [
            'revision' => $record->revision, 'title' => $record->title, 'slug' => $record->slug, 'description' => $record->description,
            'tracks' => $record->items()->pluck('track_id')->map(fn ($id): array => ['track_id' => (int) $id])->all(),
        ];
    }

    /** @return array<int, string> every track, A to Z; the label shows the track's state so staff know what the public will see */
    private static function trackOptions(): array
    {
        return Track::query()->orderBy('title')->orderBy('id')->get(['id', 'title', 'status'])
            ->mapWithKeys(fn (Track $track): array => [$track->id => $track->title.' (#'.$track->id.', '.$track->status.')'])->all();
    }

    public static function getPages(): array
    {
        return ['index' => ManageTrackCollections::route('/')];
    }
}
