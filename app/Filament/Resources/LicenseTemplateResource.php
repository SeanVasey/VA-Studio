<?php

namespace App\Filament\Resources;

use App\Domain\Rights\Models\LicenseTemplate;
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
            TextInput::make('slug')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true),
            Select::make('type')->options(['non-exclusive' => 'Non-exclusive', 'exclusive' => 'Exclusive (commerce blocked)', 'free' => 'Free (commerce blocked)'])->required()->default('non-exclusive'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('type')])->recordActions([EditAction::make()->visible(fn (LicenseTemplate $record) => ! $record->versions()->whereNotNull('published_at')->exists())]);
    }

    public static function getPages(): array
    {
        return ['index' => LicenseTemplateResource\Pages\ManageLicenseTemplates::route('/')];
    }
}
