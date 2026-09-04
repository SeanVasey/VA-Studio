<?php

namespace App\Filament\Resources\OfferResource\Pages;

use App\Domain\Catalog\SaveOfferDraft;
use App\Filament\Resources\OfferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOffers extends ManageRecords
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => app(SaveOfferDraft::class)->handle(null, $data, auth()->user()))];
    }
}
