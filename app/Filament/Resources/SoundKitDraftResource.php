<?php

namespace App\Filament\Resources;

use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Filament\Resources\SoundKitDraftResource\Pages\ManageSoundKitDrafts;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SoundKitDraftResource extends OperatorResource
{
    protected static ?string $model = SoundKitDraft::class;

    protected static ?string $slug = 'sound-kit-drafts';

    protected static ?string $navigationLabel = 'Sound kit drafts';

    protected static ?string $pluralModelLabel = 'Sound kit drafts';

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
            TextInput::make('title')->required()->maxLength(180),
            Textarea::make('description')->rows(4)->maxLength(4000)->helperText('Plain text describing this private kit.'),
            Textarea::make('provenance')->label('Source and provenance reference')->required()->maxLength(500)->rows(3)
                ->helperText('Describe where the samples came from. This reference does not approve usage or redistribution rights.'),
        ]);
    }

    public static function saveDraft(?SoundKitDraft $record, array $data, ManageSoundKitDrafts $livewire): SoundKitDraft
    {
        if ($record !== null) {
            static::requireMountedDraft($record, $livewire);
            $data['version'] = $livewire->expectedKitVersion;
        }
        $data['description'] ??= '';
        try {
            return app(SoundKitDrafts::class)->save($record, $data, static::actor());
        } catch (ValidationException $error) {
            static::formError($error, $livewire);
        }
    }

    public static function table(Table $table): Table
    {
        return $table->description('Private WAV sample kits. ZIP verification checks files and records their manifest; it does not approve rights, publish a kit, set a price or enable checkout or delivery.')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('version')->label('Draft version')->sortable(),
                TextColumn::make('updated_at')->label('Updated (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable(),
            ])->defaultSort('updated_at', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->recordUrl(null)->toolbarActions([])->recordActions([
                EditAction::make()->label('Edit draft')->modalHeading('Edit sound kit draft')->modalSubmitActionLabel('Save draft')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->fillForm(function (SoundKitDraft $record, ManageSoundKitDrafts $livewire): array {
                        $snapshot = static::capture($record, $livewire);

                        return array_intersect_key($snapshot, array_flip(['title', 'description', 'provenance']));
                    })->using(fn (SoundKitDraft $record, array $data, ManageSoundKitDrafts $livewire): SoundKitDraft => static::saveDraft($record, $data, $livewire)),
                Action::make('upload')->label('Upload kit ZIP')->modalHeading('Upload a private kit ZIP')->modalSubmitActionLabel('Upload privately')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->modalDescription('Upload a ZIP containing WAV samples. Each upload retains the current description and provenance as a new archive revision. Samples do not need to share a duration or recording alignment. Nothing is published.')
                    ->schema([FileUpload::make('upload')->label('Kit ZIP')->disk('local')->visibility('private')->storeFiles(false)
                        ->preventFilePathTampering()->getUploadedFileUsing(fn (): ?array => null)->maxSize(204800)
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])->required()
                        ->downloadable(false)->openable(false)->previewable(false)
                        ->helperText('Up to 200 MiB, subject to the configured intake limit. WAV files only; MIDI and plugin presets are not accepted by this profile.')])
                    ->mountUsing(function (SoundKitDraft $record, ManageSoundKitDrafts $livewire, ?Schema $schema = null): void {
                        static::capture($record, $livewire);
                        $schema?->fill(['upload' => null]);
                    })->action(function (SoundKitDraft $record, array $data, ManageSoundKitDrafts $livewire): void {
                        static::requireMountedDraft($record, $livewire);
                        try {
                            if (! ($data['upload'] ?? null) instanceof UploadedFile) {
                                throw ValidationException::withMessages(['upload' => 'Choose a ZIP uploaded through this form.']);
                            }
                            app(SoundKitIntake::class)->handle($record->id, $data['upload'], $livewire->expectedKitVersion, static::actor());
                        } catch (ValidationException $error) {
                            static::formError($error, $livewire, 'upload');
                        } catch (MediaFailure $error) {
                            static::formError(ValidationException::withMessages(['upload' => 'This ZIP could not be accepted ('.$error->failureCode.'). Check its contents before trying again.']), $livewire, 'upload');
                        }
                        Notification::make()->success()->title('Kit archive retained privately')->body('Open Inspect revision to check verification status. No rights or publication are approved.')->send();
                    }),
                Action::make('inspect')->label('Inspect revision')->modalHeading('Private kit archive revisions')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->schema([
                        Select::make('revision_id')->label('Archive revision')->native()->live()
                            ->options(fn (ManageSoundKitDrafts $livewire): array => static::revisionOptions($livewire))
                            ->afterStateUpdated(function (mixed $state, Set $set, ManageSoundKitDrafts $livewire): void {
                                static::actor();
                                $revision = static::selectedRevision($state, $livewire);
                                $set('details', $revision === null ? 'Choose a retained archive revision.' : static::revisionText($revision));
                            }),
                        Textarea::make('details')->label('Retained archive and member manifest')->rows(20)->readOnly()->dehydrated(false),
                    ])->mountUsing(function (SoundKitDraft $record, ManageSoundKitDrafts $livewire, ?Schema $schema = null): void {
                        static::capture($record, $livewire);
                        $first = reset($livewire->kitRevisions);
                        $schema?->fill(['revision_id' => $first === false ? null : (string) $first['id'],
                            'details' => $first === false ? 'No archive revisions yet. Upload a kit ZIP to begin technical verification.' : static::revisionText($first)]);
                    })->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('retry')->label('Retry verification')->modalHeading('Retry kit verification')->modalSubmitActionLabel('Queue verification')
                    ->modalDescription('Retry a waiting revision or an expired processing attempt. Completed revisions remain unchanged; a failed archive needs a new upload.')
                    ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                    ->schema([Select::make('revision_id')->label('Waiting archive revision')->native()->required()
                        ->options(fn (ManageSoundKitDrafts $livewire): array => static::revisionOptions($livewire, true))])
                    ->mountUsing(function (SoundKitDraft $record, ManageSoundKitDrafts $livewire, ?Schema $schema = null): void {
                        static::capture($record, $livewire);
                        $options = static::revisionOptions($livewire, true);
                        $schema?->fill(['revision_id' => count($options) === 1 ? (string) array_key_first($options) : null]);
                    })->action(function (SoundKitDraft $record, array $data, ManageSoundKitDrafts $livewire, Action $action): void {
                        static::requireMountedDraft($record, $livewire);
                        $revision = static::selectedRevision($data['revision_id'] ?? null, $livewire);
                        try {
                            if ($revision === null || $revision['retryable'] !== true) {
                                throw ValidationException::withMessages(['revision_id' => 'Choose a waiting revision from this kit.']);
                            }
                            app(SoundKitIntake::class)->retry($revision['id'], static::actor());
                            Notification::make()->success()->title('Kit verification requested')->body('The private revision remains available. Refresh and inspect it for the processing result.')->send();
                        } catch (ValidationException) {
                            Notification::make()->warning()->title('No retry queued')->body('The revision may have changed. Reopen its history before retrying.')->send();
                            $action->cancel();
                        }
                    }),
            ])->emptyStateHeading('No sound kit drafts')->emptyStateDescription('Create a private draft with its source reference, then upload a WAV sample ZIP.');
    }

    private static function capture(SoundKitDraft $record, ManageSoundKitDrafts $livewire): array
    {
        $snapshot = app(SoundKitDrafts::class)->snapshot($record->id, static::actor());
        $livewire->expectedKitId = $snapshot['id'];
        $livewire->expectedKitVersion = $snapshot['version'];
        $livewire->kitRevisions = array_column($snapshot['revisions'], null, 'id');

        return $snapshot;
    }

    private static function requireMountedDraft(SoundKitDraft $record, ManageSoundKitDrafts $livewire): void
    {
        abort_unless($livewire->expectedKitId === $record->id && $livewire->expectedKitVersion !== null, 409);
    }

    private static function selectedRevision(mixed $id, ManageSoundKitDrafts $livewire): ?array
    {
        return (is_int($id) || is_string($id)) && preg_match('/\A[1-9][0-9]*\z/D', (string) $id)
            && (string) (int) $id === (string) $id ? ($livewire->kitRevisions[(int) $id] ?? null) : null;
    }

    private static function revisionOptions(ManageSoundKitDrafts $livewire, bool $retryable = false): array
    {
        $options = [];
        foreach ($livewire->kitRevisions as $revision) {
            if (! $retryable || $revision['retryable']) {
                $options[$revision['id']] = 'Revision '.$revision['number'].' · '.static::statusLabel($revision['status']).' · '.$revision['original_name'];
            }
        }

        return $options;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Technically verified', 'quarantined' => 'Waiting for verification',
            'processing' => 'Verifying', 'failed' => 'Failed verification', default => 'Unknown status'
        };
    }

    public static function revisionText(array $revision): string
    {
        $description = $revision['description_snapshot'];
        $lines = ['Revision '.$revision['number'].' · '.static::statusLabel($revision['status']),
            'Source: '.$revision['original_name'].' ('.$revision['source_size_bytes'].' bytes)', 'Source SHA-256: '.$revision['source_sha256'],
            'Verification attempts: '.$revision['attempts'], 'Saved title: '.($description['title'] ?? ''),
            'Saved description: '.($description['description'] ?? ''), 'Saved provenance: '.($description['provenance'] ?? ''),
            'Technical verification does not approve rights, licensing, publication, checkout or delivery.'];
        if ($revision['failure_code'] !== null) {
            $lines[] = 'Verification problem: '.$revision['failure_code'];
        }
        if ($revision['manifest'] !== null) {
            $lines[] = 'Manifest SHA-256: '.$revision['manifest_sha256'];
            $lines[] = 'Verified members:';
            foreach ($revision['manifest']['members'] as $member) {
                $lines[] = $member['name'].' · '.$member['size_bytes'].' bytes · SHA-256 '.$member['sha256'];
            }
        } else {
            $lines[] = 'No verified member manifest is available yet.';
        }

        return implode("\n", $lines);
    }

    private static function formError(ValidationException $error, ManageSoundKitDrafts $livewire, string $fallback = 'title'): never
    {
        $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
        $errors = [];
        foreach ($error->errors() as $field => $messages) {
            $visible = $fallback === 'upload' ? 'upload' : (in_array($field, ['title', 'description', 'provenance'], true) ? $field : $fallback);
            $errors[$path.'.'.$visible] = [...($errors[$path.'.'.$visible] ?? []), ...$messages];
        }
        throw ValidationException::withMessages($errors);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSoundKitDrafts::route('/')];
    }
}
