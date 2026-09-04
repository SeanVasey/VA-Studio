<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Rights\Models\LicenseVersion;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OfferResource extends OperatorResource
{
    protected static ?string $model = Offer::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('track_id')->relationship('track', 'title')->searchable()->required()->live(),
            Select::make('license_version_id')->options(fn () => LicenseVersion::query()->where('status', 'published')->with('template')->get()->mapWithKeys(fn ($version) => [$version->id => $version->template->name.' v'.$version->version]))->required(),
            TextInput::make('price_minor')->label('Price in cents')->integer()->minValue(1)->required()->helperText('2999 = $29.99. Server prices will govern checkout.'),
            Select::make('currency')->options(['USD' => 'USD'])->default('USD')->required(),
            Select::make('deliverable_asset_ids')->label('Exact verified asset revisions')->multiple()->options(fn (Get $get) => MediaAsset::query()->where('track_id', $get('track_id'))->where('status', 'ready')->whereIn('role', ['download_mp3', 'master_wav', 'stems_zip'])->get()->mapWithKeys(fn ($asset) => [$asset->id => $asset->role.' #'.$asset->id.' '.$asset->original_name]))->required(),
            Toggle::make('is_active')->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('track.title'), TextColumn::make('licenseVersion.template.name'), TextColumn::make('price_minor')->money('USD', divideBy: 100), TextColumn::make('currency'), TextColumn::make('is_active')])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => OfferResource\Pages\ManageOffers::route('/')];
    }
}
