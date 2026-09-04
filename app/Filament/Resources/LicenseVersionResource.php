<?php

namespace App\Filament\Resources;

use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LicenseVersionResource extends OperatorResource
{
    protected static ?string $model = LicenseVersion::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('license_template_id')->relationship('template', 'name')->required(),
            TextInput::make('version')->integer()->minValue(1)->required(),
            Textarea::make('authored_source')->required()->rows(12)->columnSpanFull()->helperText('Authored legal source. No default terms are supplied.'),
            TagsInput::make('structured_terms.features')->label('Reviewed feature summaries')->required(),
            Select::make('structured_terms.required_asset_roles')->label('Required deliverables')->multiple()->options(['download_mp3' => 'MP3', 'master_wav' => 'WAV master', 'stems_zip' => 'Stems ZIP'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('template.name'), TextColumn::make('version'), TextColumn::make('status')->badge()])->recordActions([
            EditAction::make()->visible(fn (LicenseVersion $record) => $record->status === 'draft'),
            Action::make('submit_review')->visible(fn (LicenseVersion $record) => $record->status === 'draft')->action(fn (LicenseVersion $record) => app(ReviewLicense::class)->submit($record, auth()->user())),
            Action::make('approve')->visible(fn (LicenseVersion $record) => $record->status === 'legal_review' && $record->author_id !== auth()->id())->schema([
                TextInput::make('approval_reference')->label('Qualified review evidence reference')->required()->maxLength(255),
                TextInput::make('renderer_version')->label('Pinned contract renderer version')->required()->maxLength(255),
                TextInput::make('render_fixture_hash')->label('Reviewed rendered fixture SHA-256')->required()->regex('/^[a-f0-9]{64}$/'),
            ])->requiresConfirmation()->action(fn (LicenseVersion $record, array $data) => app(ReviewLicense::class)->approve($record, auth()->user(), $data)),
            Action::make('publish')->visible(fn (LicenseVersion $record) => $record->status === 'approved')->requiresConfirmation()->action(fn (LicenseVersion $record) => app(PublishLicense::class)->handle($record, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => LicenseVersionResource\Pages\ManageLicenseVersions::route('/')];
    }
}
