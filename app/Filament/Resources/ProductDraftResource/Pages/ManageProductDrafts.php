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

    #[Locked]
    public ?int $memberRefreshProductId = null;

    #[Locked]
    public ?int $memberRefreshVersion = null;

    #[Locked]
    public ?string $memberRefreshHash = null;

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

    public function clearMemberRefresh(): void
    {
        $this->memberRefreshProductId = null;
        $this->memberRefreshVersion = null;
        $this->memberRefreshHash = null;
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        $this->clearVersionSelection();
        $this->clearMemberRefresh();

        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (\Throwable $exception) {
            $this->clearMemberRefresh();

            throw $exception;
        }
    }

    public function unmountAction(bool|string|null $cancelParentActions = null): void
    {
        $this->clearVersionSelection();
        $this->clearMemberRefresh();
        parent::unmountAction($cancelParentActions);
    }

    public function updating(string $property, mixed $value): void
    {
        if (explode('.', $property)[0] === 'mountedActions'
            && ! preg_match('/\AmountedActions\.[0-9]+\.data(?:\.|\z)/D', $property)) {
            $this->clearMemberRefresh();
        }
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        try {
            return parent::callMountedAction($arguments);
        } finally {
            $this->clearMemberRefresh();
        }
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Create draft')->modalHeading('Create collection or album draft')
            ->modalSubmitActionLabel('Save draft version')->createAnother(false)
            ->extraModalWindowAttributes(TrackResource::metadataModalAttributes())
            ->using(fn (array $data) => ProductDraftResource::saveDraft(null, $data, $this))];
    }
}
