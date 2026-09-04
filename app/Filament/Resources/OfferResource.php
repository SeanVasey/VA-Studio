<?php

namespace App\Filament\Resources;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Rights\Models\LicenseVersion;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
            Select::make('track_id')->relationship('track', 'title')->searchable()->required()->live()->disabled(fn (?Offer $record) => $record !== null),
            Select::make('license_version_id')->options(fn () => LicenseVersion::query()->where('status', 'published')->with('template')->get()->mapWithKeys(fn ($version) => [$version->id => $version->template->name.' v'.$version->version]))->required(),
            TextInput::make('price_minor')->label('Draft price in cents')->integer()->minValue(1)->required()->helperText('Saving a draft keeps the published price. Publish a revision to make changes available.'),
            Select::make('currency')->options(['USD' => 'USD'])->default('USD')->required(),
            Select::make('deliverable_asset_ids')->label('Exact verified asset revisions')->multiple()->options(fn (Get $get) => MediaAsset::query()->where('track_id', $get('track_id'))->where('status', 'ready')->whereIn('role', ['download_mp3', 'master_wav', 'stems_zip'])->get()->mapWithKeys(fn ($asset) => [$asset->id => $asset->role.' #'.$asset->id.' '.$asset->original_name]))->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('track.title')->searchable(),
            TextColumn::make('licenseVersion.template.name')->label('Draft license'),
            TextColumn::make('price_minor')->label('Draft price')->money('USD', divideBy: 100),
            TextColumn::make('currentRevision.price_minor')->label('Published price')->money('USD', divideBy: 100)->placeholder('Unpublished'),
            TextColumn::make('currentRevision.revision')->label('Revision')->placeholder('Unpublished'),
            TextColumn::make('is_active')->label('Active')->badge(),
        ])->recordActions([
            EditAction::make()->label('Edit draft')->using(fn (Offer $record, array $data) => app(SaveOfferDraft::class)->handle($record, $data, auth()->user())),
            Action::make('publish_revision')->label('Publish revision')->requiresConfirmation()->modalDescription('Publish the draft price, license and verified files as an immutable revision. Earlier revisions remain in history.')->action(fn (Offer $record) => app(PublishOffer::class)->handle($record, auth()->user())),
            Action::make('deactivate')->visible(fn (Offer $record) => $record->is_active)->requiresConfirmation()->action(fn (Offer $record) => app(DeactivateOffer::class)->handle($record, auth()->user())),
            Action::make('history')->modalHeading('Published offer history')->modalContent(fn (Offer $record) => view('admin.offer-history', ['revisions' => $record->revisions()->orderByDesc('revision')->get(), 'currentId' => $record->current_revision_id]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => OfferResource\Pages\ManageOffers::route('/')];
    }
}
