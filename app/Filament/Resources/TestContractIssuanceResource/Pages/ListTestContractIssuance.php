<?php

namespace App\Filament\Resources\TestContractIssuanceResource\Pages;

use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\TestContractIssuanceResource;

final class ListTestContractIssuance extends ListTestCommerceRecords
{
    protected static string $resource = TestContractIssuanceResource::class;
}
