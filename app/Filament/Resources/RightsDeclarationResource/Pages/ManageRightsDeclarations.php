<?php

namespace App\Filament\Resources\RightsDeclarationResource\Pages;

use App\Filament\Resources\RightsDeclarationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRightsDeclarations extends ManageRecords
{
    protected static string $resource = RightsDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
