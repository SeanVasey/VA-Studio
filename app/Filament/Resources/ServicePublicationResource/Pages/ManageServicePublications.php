<?php

namespace App\Filament\Resources\ServicePublicationResource\Pages;

use App\Filament\Resources\ServicePublicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageServicePublications extends ManageRecords
{
    protected static string $resource = ServicePublicationResource::class;

    public function boot(): void
    {
        ServicePublicationResource::actor();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Approve a service page')->modalHeading('Approve a service page')->createAnother(false)
            ->databaseTransaction(false)
            ->using(fn (array $data) => ServicePublicationResource::save(null, $data, $this))];
    }
}
