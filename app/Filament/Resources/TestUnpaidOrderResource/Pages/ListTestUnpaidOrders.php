<?php

namespace App\Filament\Resources\TestUnpaidOrderResource\Pages;

use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\TestUnpaidOrderResource;

final class ListTestUnpaidOrders extends ListTestCommerceRecords
{
    protected static string $resource = TestUnpaidOrderResource::class;
}
