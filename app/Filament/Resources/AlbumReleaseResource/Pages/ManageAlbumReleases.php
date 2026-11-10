<?php

namespace App\Filament\Resources\AlbumReleaseResource\Pages;

use App\Filament\Resources\AlbumReleaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAlbumReleases extends ManageRecords
{
    protected static string $resource = AlbumReleaseResource::class;

    public function boot(): void
    {
        AlbumReleaseResource::actor();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add release')->modalHeading('Add release')->createAnother(false)->modalWidth('4xl')
            ->databaseTransaction(false)
            ->using(fn (array $data) => AlbumReleaseResource::save(null, $data, $this))];
    }
}
