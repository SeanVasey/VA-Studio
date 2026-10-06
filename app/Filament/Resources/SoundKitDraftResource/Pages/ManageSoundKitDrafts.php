<?php

namespace App\Filament\Resources\SoundKitDraftResource\Pages;

use App\Filament\Resources\SoundKitDraftResource;
use App\Filament\Resources\TrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\Locked;

class ManageSoundKitDrafts extends ManageRecords
{
    protected static string $resource = SoundKitDraftResource::class;

    #[Locked]
    public ?int $expectedKitId = null;

    #[Locked]
    public ?int $expectedKitVersion = null;

    #[Locked]
    public array $kitRevisions = [];

    public function boot(): void
    {
        SoundKitDraftResource::actor();
    }

    private function clearKitAction(): void
    {
        $this->expectedKitId = null;
        $this->expectedKitVersion = null;
        $this->kitRevisions = [];
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        $this->clearKitAction();

        return parent::mountAction($name, $arguments, $context);
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->clearKitAction();
        parent::unmountAction($cancelParentActions);
    }

    public function getTableRecordsPerPage(): int
    {
        return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 25;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Create kit draft')->modalHeading('Create private sound kit draft')
            ->modalSubmitActionLabel('Save kit draft')->createAnother(false)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->using(fn (array $data) => SoundKitDraftResource::saveDraft(null, $data, $this))];
    }
}
