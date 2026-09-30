<?php

namespace App\Filament\Resources\SiteReleaseResource\Pages;

use App\Filament\Resources\SiteReleaseResource;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Locked;

class ListSiteReleases extends ListRecords
{
    protected static string $resource = SiteReleaseResource::class;

    #[Locked]
    public ?int $expectedPublicationRevision = null;

    #[Locked]
    public ?int $expectedScheduleId = null;

    #[Locked]
    public ?int $schedulingReleaseId = null;

    public function boot(): void
    {
        // Recheck the database role and MFA on every reactive request, including search and actions.
        $actor = SiteReleaseResource::actor();
        $panel = Filament::getCurrentOrDefaultPanel();
        abort_unless($panel !== null && AdminMultiFactor::satisfiedBy($actor, $panel), 403);
    }

    public function getTableRecordsPerPage(): int
    {
        return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 25;
    }

    public function getSubheading(): ?string
    {
        return SiteReleaseResource::scheduleSummary();
    }

    protected function getHeaderActions(): array
    {
        return [SiteReleaseResource::createDraftAction(), SiteReleaseResource::cancelScheduleAction(), SiteReleaseResource::scheduleHistoryAction()];
    }
}
