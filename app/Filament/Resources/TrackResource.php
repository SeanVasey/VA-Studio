<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
            TextInput::make('artist')->required()->default('VASEY.AUDIO')->maxLength(255),
            TextInput::make('bpm')->integer()->minValue(20)->maxValue(400),
            TextInput::make('musical_key')->maxLength(24),
            TextInput::make('genre')->maxLength(255),
            TextInput::make('mood')->maxLength(255),
            TextInput::make('duration_seconds')->disabled()->dehydrated(false)->helperText('Measured from the verified preview after processing.'),
            TagsInput::make('tags')->rules(['nullable', 'array', 'list', 'max:20'])->nestedRecursiveRules(['required', 'string', 'max:80', 'distinct']),
            Textarea::make('description')->columnSpanFull()->maxLength(10000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('bpm'), TextColumn::make('musical_key'), TextColumn::make('genre'),
            TextColumn::make('status')->badge(),
        ])->recordActions([
            EditAction::make()->extraModalWindowAttributes(static::metadataModalAttributes())
                ->using(fn (Track $record, array $data, ManageTracks $livewire) => static::saveMetadata($record, $data, $livewire)),
            Action::make('readiness')->label('Check readiness')->action(function (Track $record) {
                $blockers = app(PublicationReadiness::class)->blockers($record);
                Notification::make()->title($blockers === [] ? 'Ready to publish' : 'Publication blocked')->body(implode("\n", $blockers))->persistent()->send();
            }),
            Action::make('publish')->visible(fn (Track $record) => $record->status !== 'published')->requiresConfirmation()->action(function (Track $record, Action $action) {
                try {
                    app(PublishTrack::class)->handle($record, auth()->user());
                } catch (ValidationException $exception) {
                    // A confirmation has no metadata form fields to display domain errors.
                    Notification::make()->danger()->title('Publication blocked')
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
                    $action->cancel();
                }
            }),
            Action::make('share')->visible(fn (Track $record) => $record->status === 'published')->url(fn (Track $record) => route('tracks.show', $record->slug))->openUrlInNewTab(),
            Action::make('unpublish')->visible(fn (Track $record) => $record->status === 'published')->requiresConfirmation()->action(fn (Track $record) => app(PublishTrack::class)->unpublish($record, auth()->user())),
        ]);
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
        return ['index' => TrackResource\Pages\ManageTracks::route('/')];
    }
}
