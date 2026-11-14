<?php

namespace App\Filament\Resources\ProductionTaxRefusedOrderResource\Pages;

use App\Filament\Resources\ProductionTaxRefusedOrderResource;
use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;

final class ListProductionTaxRefusedOrders extends ListTestCommerceRecords
{
    protected static string $resource = ProductionTaxRefusedOrderResource::class;
}
