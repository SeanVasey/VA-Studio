<?php

namespace App\Filament\Resources\StaffMailDeliveryResource\Pages;

use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\StaffMailDeliveryResource;

final class ListStaffMailDeliveries extends ListTestCommerceRecords
{
    protected static string $resource = StaffMailDeliveryResource::class;
}
