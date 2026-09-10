<?php

namespace App\Filament\Resources;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\StemsRecording;
use App\Domain\Media\QueueMediaProcessing;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class MediaAssetResource extends OperatorResource
{
    protected static ?string $model = MediaAsset::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('track_id')->relationship('track', 'title')->searchable()->required(),
            Select::make('role')->options(IngestMediaUpload::ROLES)->required(),
            FileUpload::make('upload')->label('Private upload')->disk('local')->visibility('private')->storeFiles(false)->maxSize(204800)->acceptedFileTypes(['image/jpeg', 'image/png', 'audio/wav', 'audio/x-wav', 'application/zip', 'application/x-zip'])->required()->downloadable(false)->openable(false)->previewable(false)->helperText('Upload a WAV master, PNG/JPEG artwork or WAV-only stems ZIP (up to 200 MiB). Select Process after uploading. Masters and stems stay private. Stems recording association is required before licensing.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('track.title')->searchable(),
            TextColumn::make('role'), TextColumn::make('original_name')->searchable(),
            TextColumn::make('status')->badge(), TextColumn::make('size_bytes')->numeric(),
            TextColumn::make('recording')->label('Recording')->state(function (MediaAsset $record) {
                if ($record->role !== 'stems_zip' || $record->status !== 'ready') {
                    return '—';
                }
                $binding = StemsRecording::where('stems_asset_id', $record->id)->first();

                return $binding ? 'Master #'.$binding->master_asset_id : 'Association required';
            }),
            TextColumn::make('latest_run')->label('Processing')->state(fn (MediaAsset $record) => $record->runs()->latest('id')->first()?->status ?? 'Not requested')->badge(),
        ])->poll('5s')->recordActions([
            Action::make('associate_recording')->label('Associate recording')
                ->visible(fn (MediaAsset $record) => $record->role === 'stems_zip' && $record->status === 'ready' && ! StemsRecording::where('stems_asset_id', $record->id)->exists())
                ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                ->modalDescription('Confirm these stems belong to the selected master. The saved association is permanent; corrections require a new stems revision.')
                ->fillForm(fn (MediaAsset $record) => ['preview_asset_id' => $record->track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->value('id')])
                ->schema([
                    Hidden::make('preview_asset_id')->required(),
                    Select::make('master_asset_id')->label('Verified master')->required()->options(function (MediaAsset $record) {
                        $preview = $record->track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->first();

                        return $preview ? MediaAsset::where('track_id', $record->track_id)->where('role', 'master_wav')->where('status', 'ready')
                            ->where('processing_run_id', $preview->processing_run_id)->get()->mapWithKeys(fn (MediaAsset $master) => [$master->id => '#'.$master->id.' · '.$master->original_name.' · SHA-256 '.substr($master->sha256, 0, 12)])->all() : [];
                    }),
                    TextInput::make('verification_reference')->label('Verification note')->required()->maxLength(240)->helperText('Identify the source export or session you checked. Keep credentials and customer details out of this note.'),
                    Checkbox::make('same_recording_confirmed')->label('I checked that these stems belong to this master recording.')->accepted(),
                ])
                ->action(function (MediaAsset $record, array $data, $livewire) {
                    try {
                        $binding = app(BindStemsToRecording::class)->handle($record, $data, auth()->user());
                    } catch (ValidationException $exception) {
                        $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                        $errors = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $errors[$path.'.'.$field] = $messages;
                        }
                        throw ValidationException::withMessages($errors);
                    }
                    Notification::make()->title('Recording associated')->body('Master #'.$binding->master_asset_id.' is recorded. Review and publish the required license offer separately.')->success()->send();
                }),
            Action::make('preview')->label('Review preview')->visible(fn (MediaAsset $record) => $record->status === 'ready' && $record->isPublicDerivative())->url(fn (MediaAsset $record) => route('filament.admin.media.preview', $record))->openUrlInNewTab(),
            Action::make('process')->label('Process / retry')->visible(fn (MediaAsset $record) => $record->parent_asset_id === null && array_key_exists($record->role, IngestMediaUpload::ROLES))->action(function (MediaAsset $record) {
                $run = app(QueueMediaProcessing::class)->handle($record, auth()->user());
                Notification::make()->title($run->status === 'completed' ? 'Already processed' : 'Processing requested')->body('Open Processing details to follow the result. Uploading and processing do not publish the track.')->success()->send();
            }),
            Action::make('processing_details')->label('Processing details')->action(function (MediaAsset $record) {
                $run = $record->runs()->latest('id')->first() ?? $record->processingRun;
                $message = $run ? 'Status: '.$run->status.'. Attempts: '.$run->attempts.'. '.($run->failure_message ?? '') : 'No processing has been requested for this upload.';
                Notification::make()->title('Media processing')->body($message)->persistent()->send();
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => MediaAssetResource\Pages\ManageMediaAssets::route('/')];
    }
}
