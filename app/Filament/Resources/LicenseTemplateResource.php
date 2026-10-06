<?php

namespace App\Filament\Resources;

use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Filament\Resources\LicenseTemplateResource\Pages\ManageLicenseTemplates;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LicenseTemplateResource extends OperatorResource
{
    protected static ?string $model = LicenseTemplate::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true),
            Select::make('type')->options(['non-exclusive' => 'Non-exclusive', 'exclusive' => 'Exclusive (commerce blocked)', 'free' => 'Free (commerce blocked)'])->required()->default('non-exclusive'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('type'),
            TextColumn::make('authoring_state')->label('Identity')->badge()
                ->state(fn (LicenseTemplate $record): string => static::frozen($record) ? 'Frozen after review' : 'Editable')
                ->description(fn (LicenseTemplate $record): ?string => static::frozen($record) ? 'Create a new successor template for identity changes.' : null),
        ])->recordActions([
            EditAction::make()->visible(fn (LicenseTemplate $record): bool => ! static::frozen($record))->databaseTransaction(false)
                ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
                ->modalDescription('Edit the template name, URL, or type while all versions are drafts. '.SaveLicenseTemplate::FROZEN_MESSAGE)
                ->mountUsing(function (LicenseTemplate $record, ManageLicenseTemplates $livewire, Schema $schema): void {
                    $schema->fill($livewire->captureTemplateReview($record)['display']);
                })
                ->using(fn (LicenseTemplate $record, array $data, Action $action, ManageLicenseTemplates $livewire) => $livewire->updateTemplate($record, $data, $action)),
        ]);
    }

    public static function frozen(LicenseTemplate $record): bool
    {
        return $record->versions()->where(fn ($query) => $query->where('status', '!=', 'draft')->orWhereNotNull('published_at'))->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ManageLicenseTemplates::route('/')];
    }
}
