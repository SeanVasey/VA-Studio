<?php

namespace App\Filament\Resources;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\LicenseDiff;
use App\Domain\Rights\LicenseTerms;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Filament\Forms\TypedLicenseFields;
use App\Filament\Forms\LicenseScopeFields;
use App\Filament\Forms\LicenseEconomicFields;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class LicenseVersionResource extends OperatorResource
{
    protected static ?string $model = LicenseVersion::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    private static function fields(): array
    {
        return [
            Select::make('license_template_id')->relationship('template', 'name')->required()->disabled(fn (?LicenseVersion $record) => $record !== null),
            Hidden::make('structured_terms.schema_version')->default(LicenseTerms::SCHEMA_VERSION),
            Textarea::make('authored_source')->required()->rows(12)->columnSpanFull()->helperText('Authored license source. Use Preview before requesting review.'),
            TagsInput::make('structured_terms.features')->label('Feature summaries for review')->required()->visible(fn (Get $get) => (int) $get('structured_terms.schema_version') === 1),
            Select::make('structured_terms.required_asset_roles')->label('Required deliverables')->multiple()->options(['download_mp3' => 'MP3', 'master_wav' => 'WAV master', 'stems_zip' => 'Stems ZIP'])->required(),
            TypedLicenseFields::make(),
            LicenseScopeFields::make(),
            LicenseEconomicFields::make(),
            DateTimePicker::make('effective_from')->label('Offer availability starts (UTC)')->timezone('UTC')->helperText('Leave blank for publication time. This does not start the licensed-use duration.'),
            DateTimePicker::make('effective_until')->label('Offer availability ends (UTC)')->timezone('UTC')->helperText('Leave blank for no scheduled end. This stops new availability; it does not terminate existing rights.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('template.name')->searchable(), TextColumn::make('version'), TextColumn::make('status')->badge(),
            TextColumn::make('effective_from')->label('Availability starts')->dateTime()->placeholder('On publication'), TextColumn::make('effective_until')->label('Availability ends')->dateTime()->placeholder('No scheduled end'),
        ])->recordActions([
            EditAction::make()->visible(fn (LicenseVersion $record) => $record->status === 'draft')->using(fn (LicenseVersion $record, array $data, $livewire) => self::withFormErrors(fn () => app(UpdateLicenseDraft::class)->handle($record, Arr::only($data, ['authored_source', 'structured_terms', 'effective_from', 'effective_until']), auth()->user()), $livewire)),
            Action::make('preview')->label('Preview')->url(fn (LicenseVersion $record) => route('filament.admin.licenses.preview', $record))->openUrlInNewTab(),
            Action::make('compare')->label('Compare changes')->visible(fn (LicenseVersion $record) => $record->predecessor_id !== null)->modalContent(fn (LicenseVersion $record) => view('admin.license-diff', ['changes' => app(LicenseDiff::class)->between(LicenseVersion::findOrFail($record->predecessor_id), $record)]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Action::make('successor')->label('New revision')->requiresConfirmation()->modalDescription('Create an editable successor draft. This version and its review evidence remain retained.')->action(function (LicenseVersion $record) {
                $draft = app(CreateLicenseDraft::class)->handle($record->template, [
                    'authored_source' => $record->authored_source,
                    'structured_terms' => $record->structured_terms,
                    'effective_from' => $record->effective_from,
                    'effective_until' => $record->effective_until,
                ], auth()->user(), $record);
                Notification::make()->title('Draft version '.$draft->version.' created')->success()->send();
            }),
            Action::make('economic_successor')->label('Define economic policies')->visible(fn (LicenseVersion $record) => ($record->structured_terms['schema_version'] ?? null) === 3)
                ->modalDescription('Create a successor with explicit ownership declarations, income and royalty choices, and retained policy text. Preserve the existing terms and add the new source variables. No economic policy is inferred.')
                ->fillForm(fn (LicenseVersion $record) => ['license_template_id' => $record->license_template_id, 'authored_source' => $record->authored_source,
                    'structured_terms' => array_replace($record->structured_terms, ['schema_version' => 4]),
                    'effective_from' => $record->effective_from, 'effective_until' => $record->effective_until])
                ->schema(fn () => self::fields())
                ->action(function (LicenseVersion $record, array $data, $livewire) {
                    $draft = self::withFormErrors(fn () => app(CreateLicenseDraft::class)->handle($record->template, Arr::only($data, ['authored_source', 'structured_terms', 'effective_from', 'effective_until']), auth()->user(), $record), $livewire);
                    Notification::make()->title('Economic policy draft version '.$draft->version.' created')->body('Review the retained policy text and generated declarations before requesting approval.')->success()->send();
                }),
            Action::make('scoped_successor')->label('Define license scope')->visible(fn (LicenseVersion $record) => ($record->structured_terms['schema_version'] ?? null) === 2)
                ->modalDescription('Create a successor with explicit territory and duration. Retain and review the source, add both variables, and choose each new mode. No scope is inferred from existing terms.')
                ->fillForm(fn (LicenseVersion $record) => ['license_template_id' => $record->license_template_id, 'authored_source' => $record->authored_source,
                    'structured_terms' => array_replace($record->structured_terms, ['schema_version' => 3, 'duration' => ['starts_at' => 'grant']]),
                    'effective_from' => $record->effective_from, 'effective_until' => $record->effective_until])
                ->schema(fn () => self::fields())
                ->action(function (LicenseVersion $record, array $data, $livewire) {
                    $draft = self::withFormErrors(fn () => app(CreateLicenseDraft::class)->handle($record->template, Arr::only($data, ['authored_source', 'structured_terms', 'effective_from', 'effective_until']), auth()->user(), $record), $livewire);
                    Notification::make()->title('Scope draft version '.$draft->version.' created')->body('Review the new scope and source before requesting approval.')->success()->send();
                }),
            Action::make('typed_successor')->label('Define usage rights')->visible(fn (LicenseVersion $record) => ($record->structured_terms['schema_version'] ?? null) === 1)
                ->modalDescription('Create a successor with explicit usage rights. Map the retained source deliberately and add the shown variables. No permission or cap is inferred from the old summaries.')
                ->fillForm(fn (LicenseVersion $record) => ['license_template_id' => $record->license_template_id, 'authored_source' => $record->authored_source,
                    'structured_terms' => ['schema_version' => 2, 'required_asset_roles' => $record->requiredAssetRoles()],
                    'effective_from' => $record->effective_from, 'effective_until' => $record->effective_until])
                ->schema(self::fields())->action(function (LicenseVersion $record, array $data, $livewire) {
                    $draft = self::withFormErrors(fn () => app(CreateLicenseDraft::class)->handle($record->template, Arr::only($data, ['authored_source', 'structured_terms', 'effective_from', 'effective_until']), auth()->user(), $record), $livewire);
                    Notification::make()->title('Draft version '.$draft->version.' created')->body('Preview the mapped terms and request a separate review.')->success()->send();
                }),
            Action::make('submit_review')->label('Request review')->visible(fn (LicenseVersion $record) => $record->status === 'draft')->requiresConfirmation()->modalDescription('Freeze this source, its feature summaries and preview for review. Further content changes require a new revision.')->action(fn (LicenseVersion $record) => app(ReviewLicense::class)->submit($record, auth()->user())),
            Action::make('approve')->visible(fn (LicenseVersion $record) => $record->status === 'legal_review' && $record->author_id !== auth()->id() && ! in_array(auth()->id(), $record->content_author_ids ?? [], true))
                ->fillForm(fn (LicenseVersion $record) => ['review_hash' => $record->submission_hash, 'summary_consistency_confirmed' => false])
                ->schema([
                    TextInput::make('review_hash')->label('Submitted review SHA-256')->readOnly()->required(),
                    TextInput::make('approval_reference')->label('Completed review evidence reference')->required()->maxLength(255),
                    Checkbox::make('summary_consistency_confirmed')->label('I reviewed this version and confirm that its feature summaries match the authored source.')->accepted(),
                ])->modalDescription('Review the source and preview using the Preview action before approving this exact submission.')
                ->requiresConfirmation()->action(fn (LicenseVersion $record, array $data) => app(ReviewLicense::class)->approve($record, auth()->user(), $data)),
            Action::make('publish')->visible(fn (LicenseVersion $record) => $record->status === 'approved')->requiresConfirmation()->action(fn (LicenseVersion $record) => app(PublishLicense::class)->handle($record, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => LicenseVersionResource\Pages\ManageLicenseVersions::route('/')];
    }

    public static function withFormErrors(callable $command, $livewire): mixed
    {
        try {
            return $command();
        } catch (ValidationException $exception) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
