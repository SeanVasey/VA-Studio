<?php

namespace App\Filament\Resources\TrackResource\Pages;

use App\Filament\Resources\TrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTracks extends ManageRecords
{
    protected static string $resource = TrackResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->using(fn (array $data) => TrackResource::saveMetadata(null, $data, $this))];
    }
}
