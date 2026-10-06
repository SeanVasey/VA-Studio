<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\Models\ProductDraft;
use App\Domain\Catalog\ProductDrafts;
use App\Filament\Forms\PreserveTrackIdState;
use App\Filament\Resources\ProductDraftResource\Pages\ManageProductDrafts;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductDraftResource extends OperatorResource
{
    protected static ?string $model = ProductDraft::class;

    protected static ?string $slug = 'collection-album-drafts';

    protected static ?string $navigationLabel = 'Collections and albums';

    protected static ?string $pluralModelLabel = 'Collection and album drafts';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('version')->dehydrated(fn (?ProductDraft $record): bool => $record !== null),
            Select::make('kind')->label('Draft type')->native()->options(['collection' => 'Collection', 'album' => 'Album'])
                ->default('collection')->required()->disabled(fn (?ProductDraft $record): bool => $record !== null)->dehydrated()
                ->helperText('Choose the type when creating the draft. It stays the same across its versions.'),
            TextInput::make('title')->required()->maxLength(180),
            Textarea::make('description')->rows(4)->maxLength(4000)->helperText('Plain text. This description belongs to the saved draft version.'),
            Repeater::make('track_ids')->label('Track order')
                ->simple(Select::make('track')->label('Track')->searchable()->required()->stateCast(new PreserveTrackIdState)
                    ->options(fn (): array => app(ProductDrafts::class)->tracks(static::actor()))
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems())
                ->required()->minItems(1)->maxItems(100)->defaultItems(1)->reorderableWithButtons()->addActionLabel('Add track')
                ->helperText('Choose each track once and arrange its order. A saved version retains the track titles and order shown when it was saved.'),
        ]);
    }

    public static function saveDraft(?ProductDraft $record, array $data, ManageProductDrafts $livewire): ProductDraft
    {
        try {
            return app(ProductDrafts::class)->save($record, $data, static::actor());
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $visible = str_starts_with($field, 'track_ids') ? 'track_ids'
                    : (in_array($field, ['kind', 'title', 'description'], true) ? $field : 'title');
                $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function table(Table $table): Table
    {
        return $table->description('Private collection and album drafts. Saving records track order and descriptive snapshots; it does not set a price or license, or enable checkout or delivery.')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('kind')->label('Type')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('version')->label('Current draft version')->sortable(),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('updated_at', 'desc')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->label('Edit draft')->modalHeading('Edit collection or album draft')
                    ->modalSubmitActionLabel('Save draft version')->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->fillForm(function (ProductDraft $record): array {
                        $snapshot = app(ProductDrafts::class)->snapshot($record->id, static::actor());

                        return array_intersect_key($snapshot, array_flip(['kind', 'title', 'description', 'track_ids', 'version']));
                    })
                    ->using(fn (ProductDraft $record, array $data, ManageProductDrafts $livewire): ProductDraft => static::saveDraft($record, $data, $livewire)),
                Action::make('refreshMembers')->label('Refresh track snapshots')->modalHeading('Review descriptive track snapshots')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->modalDescription('Refresh descriptive track snapshots only. The draft title, description and track order stay as saved. This does not assess product readiness, set a price or license, or publish a product. Earlier versions remain unchanged.')
                    ->modalSubmitActionLabel('Confirm reviewed snapshots')
                    ->schema([
                        Textarea::make('changes')->label('Changes in this review')->rows(5)->readOnly()->dehydrated(false),
                        Textarea::make('saved_members')->label('Saved track snapshots')->rows(12)->readOnly()->dehydrated(false),
                        Textarea::make('current_members')->label('Current track snapshots')->rows(12)->readOnly()->dehydrated(false)
                            ->helperText('Revision numbers identify descriptive snapshots; they do not indicate publication readiness. If a track or draft changes, close and reopen this review.'),
                    ])
                    ->mountUsing(function (ProductDraft $record, ManageProductDrafts $livewire, ?Schema $schema = null): void {
                        $review = app(ProductDrafts::class)->reviewMembers($record->id, static::actor());
                        $schema?->fill([
                            'changes' => static::memberChangesText($review),
                            'saved_members' => static::memberSnapshotsText($review, 'saved'),
                            'current_members' => static::memberSnapshotsText($review, 'current'),
                        ]);
                        $livewire->memberRefreshProductId = $review['id'];
                        $livewire->memberRefreshVersion = $review['version'];
                        $livewire->memberRefreshHash = $review['review_hash'];
                    })
                    ->action(function (ProductDraft $record, ManageProductDrafts $livewire, Action $action): void {
                        try {
                            $actor = static::actor();
                            abort_unless($livewire->memberRefreshProductId === $record->id
                                && $livewire->memberRefreshVersion !== null && $livewire->memberRefreshVersion > 0
                                && is_string($livewire->memberRefreshHash)
                                && preg_match('/\A[a-f0-9]{64}\z/D', $livewire->memberRefreshHash), 409);
                            $refreshed = app(ProductDrafts::class)->refreshMembers($record, $livewire->memberRefreshVersion, $livewire->memberRefreshHash, $actor);
                            Notification::make()->success()->title($refreshed->version === $livewire->memberRefreshVersion
                                ? 'Track snapshots are already current'
                                : 'Track snapshots saved as a new draft version')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Track snapshots could not be refreshed')
                                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                            $action->cancel();
                        } finally {
                            $livewire->clearMemberRefresh();
                        }
                    }),
                Action::make('history')->label('Version history')->modalHeading('Draft version history')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->schema([
                        Textarea::make('current')->label('Current draft contents')->rows(10)->readOnly()->dehydrated(false),
                        Textarea::make('history')->label('Retained draft versions')->rows(18)->readOnly()->dehydrated(false),
                    ])
                    ->fillForm(function (ProductDraft $record): array {
                        $snapshot = app(ProductDrafts::class)->snapshot($record->id, static::actor());

                        return ['current' => static::versionText(['number' => $snapshot['version']] + $snapshot),
                            'history' => implode("\n\n", array_map(static::versionText(...), $snapshot['history']))];
                    })->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('selectVersion')->label('Use as new draft version')->modalHeading('Use a retained draft version')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->modalDescription('Copy a retained version into a new current draft. Its historical titles, description and track order stay exactly as saved. Earlier versions remain unchanged.')
                    ->modalSubmitActionLabel('Use as new draft version')
                    ->schema([
                        Select::make('retained_version_id')->label('Retained draft version')->native()->required()->live()
                            ->stateCast(new PreserveTrackIdState)
                            ->options(fn (ManageProductDrafts $livewire): array => array_map(
                                fn (array $version): string => 'Version '.$version['number'].' — '.$version['title'].' ('.$version['member_count'].' tracks)',
                                $livewire->retainedVersions))
                            ->afterStateUpdated(function (mixed $state, Set $set, ManageProductDrafts $livewire): void {
                                $version = (is_int($state) || is_string($state)) ? ($livewire->retainedVersions[$state] ?? null) : null;
                                $set('retained_contents', $version === null ? '' : static::versionText($version));
                            }),
                        Textarea::make('retained_contents')->label('Selected version contents')->rows(14)->readOnly()->dehydrated(false)
                            ->helperText('Review the retained contents before confirming. This does not publish a product or alter a track.'),
                    ])
                    ->mountUsing(function (ProductDraft $record, ManageProductDrafts $livewire, ?Schema $schema = null): void {
                        $snapshot = app(ProductDrafts::class)->snapshot($record->id, static::actor());
                        $livewire->expectedProductId = $snapshot['id'];
                        $livewire->expectedProductVersion = $snapshot['version'];
                        $livewire->retainedVersions = array_column($snapshot['history'], null, 'id');
                        $schema?->fill(['retained_version_id' => null, 'retained_contents' => '']);
                    })
                    ->action(function (ProductDraft $record, array $data, ManageProductDrafts $livewire, Action $action): void {
                        $actor = static::actor();
                        abort_unless($livewire->expectedProductId === $record->id && $livewire->expectedProductVersion !== null, 409);
                        try {
                            $id = $data['retained_version_id'] ?? null;
                            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $id)
                                || (string) (int) $id !== (string) $id || ! array_key_exists((int) $id, $livewire->retainedVersions)) {
                                throw ValidationException::withMessages(['title' => 'Choose a retained version from this draft history.']);
                            }
                            app(ProductDrafts::class)->select($record, (int) $id, $livewire->expectedProductVersion, $actor);
                            Notification::make()->success()->title('Retained version copied to the current draft')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Draft version could not be selected')
                                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                            $action->cancel();
                        } finally {
                            $livewire->clearVersionSelection();
                        }
                    }),
            ])->emptyStateHeading('No collection or album drafts')
            ->emptyStateDescription('Create a draft, choose its tracks and save the order. Every saved change is retained as a draft version.');
    }

    public static function versionText(array $version): string
    {
        $lines = ['Version '.$version['number'].' — '.$version['title'], $version['description'] ?? '', 'Track order:'];
        foreach ($version['members'] as $position => $member) {
            $lines[] = ($position + 1).'. '.$member['title'].' (track #'.$member['track_id'].')';
        }
        if (isset($version['created_at'])) {
            $lines[] = 'Saved: '.$version['created_at'];
        }

        return implode("\n", $lines);
    }

    public static function memberSnapshotsText(array $review, string $side): string
    {
        $lines = ['Draft version '.$review['version'].' — '.$review['title'], $review['description'], 'Track order:'];
        foreach ($review['members'] as $member) {
            $snapshot = $member[$side];
            $lines[] = $member['position'].'. '.$snapshot['title'].' (track #'.$member['track_id'].')';
            $lines[] = 'Metadata revision '.$snapshot['metadata_version'].'; publication revision '.$snapshot['publication_version'];
        }

        return implode("\n", $lines);
    }

    public static function memberChangesText(array $review): string
    {
        if ($review['changed_count'] === 0) {
            return 'All track snapshots are current. Confirming this review will not add a draft version.';
        }
        $labels = ['title' => 'title', 'metadata_version' => 'metadata revision', 'publication_version' => 'publication revision'];
        $lines = [$review['changed_count'].' of '.count($review['members']).' track snapshots changed.'];
        foreach ($review['members'] as $member) {
            if ($member['changed_fields'] !== []) {
                $lines[] = $member['position'].'. Track #'.$member['track_id'].': '
                    .implode(', ', array_map(fn (string $field): string => $labels[$field], $member['changed_fields']));
            }
        }

        return implode("\n", $lines);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProductDrafts::route('/')];
    }
}
