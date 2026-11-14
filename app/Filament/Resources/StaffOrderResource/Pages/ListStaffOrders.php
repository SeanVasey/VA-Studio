<?php

namespace App\Filament\Resources\StaffOrderResource\Pages;

use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\StaffOrderResource;

final class ListStaffOrders extends ListTestCommerceRecords
{
    protected static string $resource = StaffOrderResource::class;
}
