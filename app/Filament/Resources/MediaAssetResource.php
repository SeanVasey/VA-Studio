<?php

namespace App\Filament\Resources;

use App\Domain\Media\Models\MediaAsset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MediaAssetResource extends OperatorResource
{
    protected static ?string $model = MediaAsset::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('track_id')->relationship('track', 'title')->searchable()->required(),
            Select::make('role')->options(array_combine(MediaAsset::ROLES, MediaAsset::ROLES))->required(),
            TextInput::make('original_name')->label('Original filename / operator label')->required()->maxLength(255),
            FileUpload::make('storage_path')->label('Private upload')->disk('local')->directory('quarantine')->visibility('private')->maxSize(204800)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'application/zip'])->required()->downloadable(false)->openable(false)->previewable(false)->helperText('Stored privately in quarantine. Promotion awaits isolated scanning and audio-worker implementation; uploading does not publish a file.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('track.title'), TextColumn::make('role'), TextColumn::make('original_name'), TextColumn::make('status')->badge(), TextColumn::make('size_bytes')->numeric()]);
    }

    public static function getPages(): array
    {
        return ['index' => MediaAssetResource\Pages\ManageMediaAssets::route('/')];
    }
}
