<?php

namespace App\Filament\Resources\TrackMetadataPresetResource\Pages;

use App\Filament\Resources\TrackMetadataPresetResource;
use App\Filament\Resources\TrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\Locked;

class ManageTrackMetadataPresets extends ManageRecords
{
    protected static string $resource = TrackMetadataPresetResource::class;

    #[Locked]
    public ?int $expectedPresetId = null;

    #[Locked]
    public ?int $expectedPresetVersion = null;

    public function boot(): void
    {
        TrackMetadataPresetResource::actor();
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->expectedPresetId = null;
        $this->expectedPresetVersion = null;
        parent::unmountAction($cancelParentActions);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Create preset')->modalHeading('Create metadata preset')
            ->modalSubmitActionLabel('Save preset')->createAnother(false)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->using(fn (array $data) => TrackMetadataPresetResource::savePreset(null, $data, $this))];
    }
}
