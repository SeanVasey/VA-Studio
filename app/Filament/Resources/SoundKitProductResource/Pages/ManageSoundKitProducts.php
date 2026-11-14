<?php

namespace App\Filament\Resources\SoundKitProductResource\Pages;

use App\Filament\Resources\SoundKitProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSoundKitProducts extends ManageRecords
{
    protected static string $resource = SoundKitProductResource::class;

    public function boot(): void
    {
        SoundKitProductResource::actor();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add kit product')->modalHeading('Add kit product')->createAnother(false)->modalWidth('4xl')
            ->databaseTransaction(false)
            ->using(fn (array $data) => SoundKitProductResource::save(null, $data, $this))];
    }
}
