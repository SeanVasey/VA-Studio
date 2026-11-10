<?php

namespace App\Filament\Resources\TrackCollectionResource\Pages;

use App\Filament\Resources\TrackCollectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTrackCollections extends ManageRecords
{
    protected static string $resource = TrackCollectionResource::class;

    public function boot(): void
    {
        TrackCollectionResource::actor();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add collection')->modalHeading('Add collection')->createAnother(false)->modalWidth('4xl')
            ->databaseTransaction(false)
            ->using(fn (array $data) => TrackCollectionResource::save(null, $data, $this))];
    }
}
