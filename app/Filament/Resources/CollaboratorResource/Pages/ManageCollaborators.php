<?php

namespace App\Filament\Resources\CollaboratorResource\Pages;

use App\Filament\Resources\CollaboratorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCollaborators extends ManageRecords
{
    protected static string $resource = CollaboratorResource::class;

    public function boot(): void
    {
        CollaboratorResource::actor();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add collaborator')->modalHeading('Add collaborator')->createAnother(false)
            ->databaseTransaction(false)
            ->using(fn (array $data) => CollaboratorResource::save(null, $data, $this))];
    }
}
