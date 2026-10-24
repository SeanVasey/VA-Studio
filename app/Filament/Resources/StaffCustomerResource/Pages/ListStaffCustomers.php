<?php

namespace App\Filament\Resources\StaffCustomerResource\Pages;

use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\StaffCustomerResource;

final class ListStaffCustomers extends ListTestCommerceRecords
{
    protected static string $resource = StaffCustomerResource::class;
}
