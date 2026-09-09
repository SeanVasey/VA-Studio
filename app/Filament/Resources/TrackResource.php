<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
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

class TrackResource extends OperatorResource
{
    protected static ?string $model = Track::class;

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
            EditAction::make()->using(fn (Track $record, array $data) => app(SaveTrackMetadata::class)->handle($record, $data, auth()->user())),
            Action::make('readiness')->label('Check readiness')->action(function (Track $record) {
                $blockers = app(PublicationReadiness::class)->blockers($record);
                Notification::make()->title($blockers === [] ? 'Ready to publish' : 'Publication blocked')->body(implode("\n", $blockers))->persistent()->send();
            }),
            Action::make('publish')->visible(fn (Track $record) => $record->status !== 'published')->requiresConfirmation()->action(fn (Track $record) => app(PublishTrack::class)->handle($record, auth()->user())),
            Action::make('share')->visible(fn (Track $record) => $record->status === 'published')->url(fn (Track $record) => route('tracks.show', $record->slug))->openUrlInNewTab(),
            Action::make('unpublish')->visible(fn (Track $record) => $record->status === 'published')->requiresConfirmation()->action(fn (Track $record) => app(PublishTrack::class)->unpublish($record, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => TrackResource\Pages\ManageTracks::route('/')];
    }
}
