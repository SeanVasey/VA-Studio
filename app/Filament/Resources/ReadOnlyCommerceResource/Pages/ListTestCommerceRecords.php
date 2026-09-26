<?php

namespace App\Filament\Resources\ReadOnlyCommerceResource\Pages;

use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

abstract class ListTestCommerceRecords extends ListRecords
{
    public function boot(): void
    {
        // Reauthorize every Livewire request, including a component mounted before access was removed.
        Gate::authorize('administer-catalog');
        $panel = Filament::getCurrentOrDefaultPanel();
        abort_if($panel === null, 403);
        if ($panel->isMultiFactorAuthenticationRequired()) {
            $user = Filament::auth()->user();
            abort_unless($user !== null && MultiFactorChallenge::make()->hasEnabledProviders($user), 403);
        }
    }

    public function getTableRecordsPerPage(): int
    {
        $requested = $this->tableRecordsPerPage;

        return in_array($requested, [10, 25, 50, '10', '25', '50'], true) ? (int) $requested : 25;
    }

    protected function getHeaderActions(): array { return []; }
}
