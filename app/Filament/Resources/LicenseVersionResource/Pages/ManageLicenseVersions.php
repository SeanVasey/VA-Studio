<?php

namespace App\Filament\Resources\LicenseVersionResource\Pages;

use App\Filament\Resources\LicenseVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLicenseVersions extends ManageRecords
{
    protected static string $resource = LicenseVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => array_merge($data, ['author_id' => auth()->id(), 'status' => 'draft']))];
    }
}
