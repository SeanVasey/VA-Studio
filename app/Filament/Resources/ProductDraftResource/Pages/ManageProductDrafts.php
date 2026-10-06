<?php

namespace App\Filament\Resources\ProductDraftResource\Pages;

use App\Filament\Resources\ProductDraftResource;
use App\Filament\Resources\TrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\Locked;

class ManageProductDrafts extends ManageRecords
{
    protected static string $resource = ProductDraftResource::class;

    #[Locked]
    public ?int $expectedProductId = null;

    #[Locked]
    public ?int $expectedProductVersion = null;

    #[Locked]
    public array $retainedVersions = [];

    public function boot(): void
    {
        ProductDraftResource::actor();
    }

    public function clearVersionSelection(): void
    {
        $this->expectedProductId = null;
        $this->expectedProductVersion = null;
        $this->retainedVersions = [];
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        $this->clearVersionSelection();

        return parent::mountAction($name, $arguments, $context);
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->clearVersionSelection();
        parent::unmountAction($cancelParentActions);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Create draft')->modalHeading('Create collection or album draft')
            ->modalSubmitActionLabel('Save draft version')->createAnother(false)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->using(fn (array $data) => ProductDraftResource::saveDraft(null, $data, $this))];
    }
}
