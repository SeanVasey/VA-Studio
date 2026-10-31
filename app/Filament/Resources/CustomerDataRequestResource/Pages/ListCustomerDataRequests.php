<?php

namespace App\Filament\Resources\CustomerDataRequestResource\Pages;

use App\Filament\Resources\CustomerDataRequestResource;
use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use Livewire\Attributes\Locked;

final class ListCustomerDataRequests extends ListTestCommerceRecords
{
    protected static string $resource = CustomerDataRequestResource::class;

    /** The request version captured when a confirmation opened; the domain refuses a request that changed meanwhile. */
    #[Locked]
    public ?int $expectedRequestVersion = null;
}
