<?php

namespace App\Filament\Resources;

use App\Domain\Albums\AlbumReleases;
use App\Domain\Albums\ListeningPlatforms;
use App\Domain\Albums\Models\AlbumRelease;
use App\Domain\Albums\ReleaseType;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Filament\Resources\AlbumReleaseResource\Pages\ManageAlbumReleases;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
 * Staff editor for the albums page (BL-67). Every write goes through AlbumReleases; nothing is decided here. Releases are
 * retained: there is no delete, only unpublish.
 */
class AlbumReleaseResource extends OperatorResource
{
    protected static ?string $model = AlbumRelease::class;

    protected static ?string $slug = 'album-releases';

    protected static ?string $navigationLabel = 'Album releases';

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
            TextInput::make('title')->required()->maxLength(160),
            TextInput::make('artist_name')->label('Artist name')->required()->maxLength(120)->helperText('Shown as the artist and in search-engine data. Enter it exactly as it should appear.'),
            TextInput::make('slug')->label('Web address')->required()->maxLength(80)->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                ->helperText('Lowercase letters, numbers and hyphens. The page will be /albums/ followed by this.'),
            Select::make('release_type')->label('Release type')->options(ReleaseType::options())->required()->native(false),
            DatePicker::make('release_date')->label('Release date')->required()->native(false)->format('Y-m-d'),
            Textarea::make('description')->rows(4)->maxLength(2000)->helperText('Optional. Plain text.'),
            Select::make('artwork_image_id')->label('Cover art')->placeholder('Choose a prepared cover')->native(false)
                ->options(fn (): array => static::coverOptions())
                ->helperText('Upload square cover art (at least 1200 × 1200 px) under Site images, with the use "Release cover art". Required to publish.'),
            TextInput::make('artwork_alt')->label('Cover art description')->maxLength(200)
                ->helperText('Describes the cover for people who cannot see it. Required to publish.'),
            Repeater::make('listening_links')->label('Listening links')->addActionLabel('Add listening link')->maxItems(count(ListeningPlatforms::options()))
                ->schema([
                    Select::make('platform')->options(ListeningPlatforms::options())->required()->native(false),
                    TextInput::make('url')->label('Address (https://)')->required()->maxLength(ListeningPlatforms::MAX_URL_LENGTH),
                ])->columns(2)->default([])->helperText('One link per platform, on the platform\'s own address. At least one is required to publish.'),
            Repeater::make('tracks')->label('Tracklist')->addActionLabel('Add track')->maxItems(99)->default([])
                ->schema([
                    TextInput::make('title')->required()->maxLength(160),
                    TextInput::make('duration')->label('Length (m:ss)')->required()->maxLength(8)->placeholder('3:45'),
                ])->columns(2)->helperText('In playing order. At least one track is required to publish.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->description('Sean\'s own releases for the public albums page. Metadata only: nothing here prices, sells or delivers music. The page stays off until ALBUMS_PAGE_ENABLED is true.')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('release_type')->label('Type')->badge()->formatStateUsing(fn (ReleaseType $state): string => $state->label()),
                TextColumn::make('release_date')->label('Release date')->date('Y-m-d')->sortable(),
                TextColumn::make('is_published')->label('Status')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Published' : 'Draft')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('tracks_count')->counts('tracks')->label('Tracks'),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('release_date', 'desc')->recordUrl(null)->toolbarActions([])
            ->recordActions([
                EditAction::make()->modalHeading('Edit release')->modalWidth('4xl')->databaseTransaction(false)
                    ->fillForm(fn (AlbumRelease $record): array => static::formState($record))
                    ->using(fn (AlbumRelease $record, array $data, ManageAlbumReleases $livewire): AlbumRelease => static::save($record, $data, $livewire)),
                Action::make('publish')->label('Publish')->color('success')->requiresConfirmation()
                    ->modalDescription(fn (AlbumRelease $record): string => ($blockers = app(AlbumReleases::class)->blockers($record)) === []
                        ? 'The release becomes visible on the albums page once ALBUMS_PAGE_ENABLED is true.'
                        : 'Not ready: '.implode(' ', $blockers))
                    ->visible(fn (AlbumRelease $record): bool => ! $record->is_published)
                    ->action(fn (AlbumRelease $record, ManageAlbumReleases $livewire) => static::transition($record, 'publish', $livewire)),
                Action::make('unpublish')->label('Unpublish')->color('danger')->requiresConfirmation()
                    ->modalDescription('The release disappears from the albums page, its sitemap entry and its cover art address.')
                    ->visible(fn (AlbumRelease $record): bool => $record->is_published)
                    ->action(fn (AlbumRelease $record, ManageAlbumReleases $livewire) => static::transition($record, 'unpublish', $livewire)),
            ])->emptyStateHeading('No releases')
            ->emptyStateDescription('Add a release, upload its cover under Site images, then publish it. Nothing is pre-filled: enter every detail yourself.');
    }

    /** Create or edit: converts the form's m:ss lengths and hands the exact fields to the domain. */
    public static function save(?AlbumRelease $record, array $data, ManageRecords $livewire): AlbumRelease
    {
        return static::guarded($livewire, function (User $actor) use ($record, $data): AlbumRelease {
            $tracks = [];
            foreach ($data['tracks'] ?? [] as $track) {
                $seconds = static::seconds((string) ($track['duration'] ?? ''));
                if ($seconds === null) {
                    throw ValidationException::withMessages(['tracks' => 'Enter each track length as minutes and seconds, for example 3:45.']);
                }
                $tracks[] = ['title' => $track['title'] ?? null, 'duration_seconds' => $seconds];
            }

            return app(AlbumReleases::class)->save($record, [
                'title' => $data['title'] ?? null, 'artist_name' => $data['artist_name'] ?? null, 'slug' => $data['slug'] ?? null,
                'release_type' => $data['release_type'] ?? null, 'release_date' => $data['release_date'] ?? null,
                'description' => $data['description'] ?? null, 'artwork_image_id' => $data['artwork_image_id'] ?? null,
                'artwork_alt' => $data['artwork_alt'] ?? null, 'listening_links' => array_values($data['listening_links'] ?? []), 'tracks' => $tracks,
            ], $actor, $record?->revision);
        });
    }

    private static function transition(AlbumRelease $record, string $operation, ManageRecords $livewire): void
    {
        static::guarded($livewire, fn (User $actor) => app(AlbumReleases::class)->{$operation}($record, $record->revision, $actor));
        Notification::make()->success()->title($operation === 'publish' ? 'Release published' : 'Release unpublished')->send();
    }

    /** @return array<string, mixed> */
    private static function formState(AlbumRelease $record): array
    {
        return [
            'title' => $record->title, 'artist_name' => $record->artist_name, 'slug' => $record->slug, 'release_type' => $record->release_type->value,
            'release_date' => $record->release_date->format('Y-m-d'), 'description' => $record->description,
            'artwork_image_id' => $record->artwork_image_id, 'artwork_alt' => $record->artwork_alt,
            'listening_links' => array_values($record->listening_links ?? []),
            'tracks' => $record->tracks()->get()->map(fn ($track): array => ['title' => $track->title, 'duration' => static::clock($track->duration_seconds)])->all(),
        ];
    }

    /** @return array<int, string> ready cover images, newest first */
    private static function coverOptions(): array
    {
        return SiteImage::query()->where('slot', AlbumReleases::SLOT)->where('status', 'ready')->orderByDesc('id')->get()
            ->mapWithKeys(fn (SiteImage $image): array => [$image->id => '#'.$image->id.' · '.$image->original_name.' ('.$image->width.' × '.$image->height.')'])->all();
    }

    private static function seconds(string $value): ?int
    {
        if (preg_match('/\A(?:(\d{1,2}):)?(\d{1,3}):([0-5]\d)\z/D', trim($value), $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }

    private static function clock(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAlbumReleases::route('/')];
    }
}
