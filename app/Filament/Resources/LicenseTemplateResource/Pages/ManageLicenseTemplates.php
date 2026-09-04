<?php

namespace App\Filament\Resources\LicenseTemplateResource\Pages;

use App\Filament\Resources\LicenseTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLicenseTemplates extends ManageRecords
{
    protected static string $resource = LicenseTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
