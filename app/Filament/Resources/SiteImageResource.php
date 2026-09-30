<?php

namespace App\Filament\Resources;

use App\Application\SiteBuilder\IngestSiteImage;
use App\Application\SiteBuilder\RetrySiteImage;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\SiteImageProblem;
use App\Domain\SiteBuilder\SiteImageSlot;
use App\Filament\Resources\SiteImageResource\Pages\ListSiteImages;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** Uploaded site images are immutable evidence: no edit or delete, only upload and retry (D-25). */
class SiteImageResource extends OperatorResource
{
    protected static ?string $model = SiteImage::class;

    protected static ?string $navigationLabel = 'Site images';

    protected static string|UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $recordTitleAttribute = 'original_name';

    public const STATUS_LABELS = ['quarantined' => 'Waiting', 'processing' => 'Processing', 'interrupted' => 'Interrupted', 'ready' => 'Ready', 'failed' => 'Failed'];

    /** A processing claim that expired without an outcome: its worker stopped, and a retry may take the image over. */
    private static function interrupted(SiteImage $record): bool
    {
        return $record->status === 'processing' && RetrySiteImage::retryable($record);
    }

    public static function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('administer-catalog');

        return $actor;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $actor = auth()->user()?->fresh();

        return $action === 'viewAny' && $actor instanceof User && Gate::forUser($actor)->allows('administer-catalog')
            ? Response::allow() : Response::deny();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('thumbnail')->label('Preview')->height(48)
                ->state(fn (SiteImage $record): ?string => ($thumbnail = $record->thumbnail()) === null ? null : route('filament.admin.site-images.preview', $thumbnail->id))
                ->extraImgAttributes(fn (SiteImage $record): array => ['alt' => SiteImageSlot::label($record->slot).' #'.$record->id, 'loading' => 'lazy']),
            TextColumn::make('id')->label('Image')->sortable(),
            TextColumn::make('slot')->label('Used for')->formatStateUsing(fn (string $state): string => SiteImageSlot::label($state)),
            TextColumn::make('status')->badge()->state(fn (SiteImage $record): string => self::interrupted($record) ? 'interrupted' : $record->status)
                ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) { 'ready' => 'success', 'failed' => 'danger', default => 'warning' }),
            TextColumn::make('attempts')->label('Attempts')->numeric(),
            TextColumn::make('size')->label('Size')->state(fn (SiteImage $record): string => $record->width.' × '.$record->height),
            TextColumn::make('original_name')->label('File')->searchable()->wrap(),
            TextColumn::make('credit')->label('Source or credit')->wrap(),
            TextColumn::make('failure_code')->label('Problem')->wrap()
                ->state(fn (SiteImage $record): ?string => self::interrupted($record) ? 'processing_interrupted' : $record->failure_code)
                ->formatStateUsing(fn (?string $state): string => SiteImageProblem::describe($state)),
            TextColumn::make('uploader.name')->label('Uploaded by'),
            TextColumn::make('created_at')->label('Uploaded (UTC)')->dateTime('Y-m-d H:i', 'UTC')->sortable(),
        ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)->recordUrl(null)->poll('5s')
            ->recordActions([
                Action::make('retry')->label('Retry processing')->requiresConfirmation()
                    ->modalDescription('Queues another attempt to scan and prepare this image. Nothing is published.')
                    ->visible(fn (SiteImage $record): bool => RetrySiteImage::retryable($record))
                    ->action(function (SiteImage $record, Action $action): void {
                        try {
                            app(RetrySiteImage::class)->handle($record, static::actor());
                            Notification::make()->success()->title('Processing queued')->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Retry not needed')->body(implode(' ', array_merge(...array_values($exception->errors()))))->send();
                            $action->cancel();
                        }
                    }),
            ])->toolbarActions([]);
    }

    public static function uploadAction(): Action
    {
        return Action::make('uploadSiteImage')->label('Upload site image')
            ->modalHeading('Upload a site image')->modalSubmitActionLabel('Upload privately')
            ->modalDescription('The image is scanned, stripped of camera and location data, and prepared in the sizes its slot needs. It stays private until a published site release uses it.')
            ->schema([
                Select::make('slot')->label('Used for')->options(SiteImageSlot::options())->required()->live()
                    ->helperText(fn (Get $get): ?string => SiteImageSlot::exists((string) $get('slot')) ? SiteImageSlot::requirement((string) $get('slot')) : null),
                // Only files uploaded through this form: a stored path placed in the form's state is neither described nor accepted.
                // The form only creates, so it describes no stored file at all; Filament's guard checks only values that are strings.
                FileUpload::make('upload')->label('Image (JPEG or PNG)')->disk('local')->visibility('private')->storeFiles(false)->preventFilePathTampering()
                    ->getUploadedFileUsing(fn (): ?array => null)
                    ->maxSize(20480)->acceptedFileTypes(['image/jpeg', 'image/png'])->required()
                    ->downloadable(false)->openable(false)->previewable(false)
                    ->helperText('Up to 20 MiB. No transparency, no rotation tag, no text or prices in the image.'),
                TextInput::make('credit')->label('Source or credit')->required()->maxLength(200)
                    ->helperText('Who made the image or where it came from, kept with the upload.'),
                Checkbox::make('rights_confirmed')->label('We have the rights to use this image on the site')->accepted(),
            ])
            ->action(function (array $data, ListSiteImages $livewire): void {
                try {
                    app(IngestSiteImage::class)->handle((string) ($data['slot'] ?? ''), $data['upload'] ?? null, (string) ($data['credit'] ?? ''),
                        ($data['rights_confirmed'] ?? false) === true, static::actor());
                    Notification::make()->success()->title('Image uploaded')->body('It will be scanned and prepared in a moment.')->send();
                } catch (ValidationException $exception) {
                    // Domain keys are relative; the form shows errors beside its own fields.
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$path.'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListSiteImages::route('/')];
    }
}
