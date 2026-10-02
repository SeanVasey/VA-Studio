<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\Models\TrackMetadataPreset;
use App\Domain\Catalog\TrackMetadataPresets;
use App\Filament\Resources\TrackMetadataPresetResource\Pages\ManageTrackMetadataPresets;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TrackMetadataPresetResource extends OperatorResource
{
    protected static ?string $model = TrackMetadataPreset::class;

    protected static ?string $slug = 'track-metadata-presets';

    protected static ?string $navigationLabel = 'Track metadata presets';

    protected static ?string $pluralModelLabel = 'Track metadata presets';

    protected static ?string $recordTitleAttribute = 'name';

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
            Hidden::make('version')->dehydrated(fn (?TrackMetadataPreset $record): bool => $record !== null),
            TextInput::make('name')->label('Preset name')->required()->maxLength(255)
                ->helperText('A reusable starting point for new tracks. Editing this preset does not change existing tracks or already copied forms.'),
            ...TrackResource::metadataFields(),
        ]);
    }

    public static function savePreset(?TrackMetadataPreset $record, array $data, ManageTrackMetadataPresets $livewire): TrackMetadataPreset
    {
        try {
            return app(TrackMetadataPresets::class)->handle($record, $data, static::actor());
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $visible = in_array($field, ['version', 'preset_id', 'archived_at', 'preset'], true) ? 'name' : $field;
                $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function table(Table $table): Table
    {
        return $table->description('Saved metadata for new private drafts. Only artist, BPM, musical key, genre, mood, tags and description are copied.')
            ->columns([
                TextColumn::make('id')->label('Preset ID')->sortable(),
                TextColumn::make('name')->label('Preset name')->searchable()->sortable(),
                TextColumn::make('metadata.artist')->label('Artist'),
                TextColumn::make('metadata.bpm')->label('BPM'),
                TextColumn::make('version')->sortable(),
                TextColumn::make('status')->badge()->state(fn (TrackMetadataPreset $record): string => $record->archived_at === null ? 'Active' : 'Archived'),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('name')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->modalHeading('Edit metadata preset')->modalSubmitActionLabel('Save changes')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->visible(fn (TrackMetadataPreset $record): bool => $record->archived_at === null)
                    ->fillForm(function (TrackMetadataPreset $record): array {
                        $snapshot = app(TrackMetadataPresets::class)->snapshot($record->id, static::actor());

                        return ['name' => $snapshot['name'], 'version' => $snapshot['version'], ...$snapshot['metadata']];
                    })
                    ->using(fn (TrackMetadataPreset $record, array $data, ManageTrackMetadataPresets $livewire): TrackMetadataPreset => static::savePreset($record, $data, $livewire)),
                Action::make('archive')->label('Archive')->requiresConfirmation()
                    ->visible(fn (TrackMetadataPreset $record): bool => $record->archived_at === null)
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->modalHeading('Archive metadata preset')->modalSubmitActionLabel('Archive preset')
                    ->modalDescription('Stop offering this preset for new copies. Existing tracks and already copied draft forms keep their metadata.')
                    ->mountUsing(function (TrackMetadataPreset $record, ManageTrackMetadataPresets $livewire): void {
                        $snapshot = app(TrackMetadataPresets::class)->snapshot($record->id, static::actor());
                        $livewire->expectedPresetId = $snapshot['id'];
                        $livewire->expectedPresetVersion = $snapshot['version'];
                    })
                    ->action(function (TrackMetadataPreset $record, ManageTrackMetadataPresets $livewire, Action $action): void {
                        $actor = static::actor();
                        abort_unless($livewire->expectedPresetId === $record->id && $livewire->expectedPresetVersion !== null, 409);
                        try {
                            app(TrackMetadataPresets::class)->archive($record, $livewire->expectedPresetVersion, $actor);
                            Notification::make()->success()->title('Metadata preset archived')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Preset could not be archived')
                                ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                            $action->cancel();
                        } finally {
                            $livewire->expectedPresetId = null;
                            $livewire->expectedPresetVersion = null;
                        }
                    }),
            ])->emptyStateHeading('No metadata presets')
            ->emptyStateDescription('Create a named preset with the metadata you reuse, then choose Create from preset in Tracks.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTrackMetadataPresets::route('/')];
    }
}
