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
            // Focus a non-input first, including when mobile WebKit suppresses input autofocus.
            ->extraModalWindowAttributes(['tabindex' => '-1', 'autofocus' => true])
            ->using(fn (array $data) => TrackResource::saveMetadata(null, $data, $this))];
    }
}
