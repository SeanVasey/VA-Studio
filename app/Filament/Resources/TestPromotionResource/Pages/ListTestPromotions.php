<?php

namespace App\Filament\Resources\TestPromotionResource\Pages;

use App\Domain\Commerce\PromotionAdministration;
use App\Filament\Resources\TestPromotionResource;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Locked;

class ListTestPromotions extends ListRecords
{
    protected static string $resource = TestPromotionResource::class;

    /** Render-only memoization; never serialized or retained across reactive requests. */
    protected array $campaignDetails = [];

    #[Locked]
    public ?int $expectedAvailabilityRevision = null;

    #[Locked]
    public ?int $expectedCampaignId = null;

    public function boot(): void
    {
        $this->campaignDetails = [];
        $actor = TestPromotionResource::actor();
        $panel = Filament::getCurrentOrDefaultPanel();
        abort_if($panel === null, 403);
        if ($panel->isMultiFactorAuthenticationRequired()) {
            abort_unless(MultiFactorChallenge::make()->hasEnabledProviders($actor), 403);
        }
    }

    public function campaignDetail(int $id): array
    {
        return $this->campaignDetails[$id] ??= app(PromotionAdministration::class)->detail($id, TestPromotionResource::actor());
    }

    public function clearCampaignDetails(): void { $this->campaignDetails = []; }

    public function getTableRecordsPerPage(): int
    {
        return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 25;
    }

    protected function getHeaderActions(): array { return [TestPromotionResource::createAction()]; }
}
