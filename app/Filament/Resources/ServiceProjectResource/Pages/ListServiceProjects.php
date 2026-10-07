<?php

namespace App\Filament\Resources\ServiceProjectResource\Pages;

use App\Domain\Services\Projects\ServiceProjects;
use App\Filament\Resources\ServiceProjectResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

final class ListServiceProjects extends ListRecords
{
    protected static string $resource = ServiceProjectResource::class;

    public function boot(): void
    {
        app(ServiceProjects::class)->staffActor(Filament::auth()->user());
    }
}
