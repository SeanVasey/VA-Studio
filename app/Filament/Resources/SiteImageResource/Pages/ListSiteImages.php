<?php

namespace App\Filament\Resources\SiteImageResource\Pages;

use App\Filament\Resources\SiteImageResource;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListSiteImages extends ListRecords
{
    protected static string $resource = SiteImageResource::class;

    public function boot(): void
    {
        // Recheck the database role and MFA on every reactive request, including polling and actions.
        $actor = SiteImageResource::actor();
        $panel = Filament::getCurrentOrDefaultPanel();
        abort_unless($panel !== null && AdminMultiFactor::satisfiedBy($actor, $panel), 403);
    }

    public function getTableRecordsPerPage(): int
    {
        return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 25;
    }

    protected function getHeaderActions(): array
    {
        return [SiteImageResource::uploadAction()];
    }
}
