<?php

namespace App\Filament\Resources\TestPaymentExceptionResource\Pages;

use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\TestPaymentExceptionResource;

final class ListTestPaymentExceptions extends ListTestCommerceRecords
{
    protected static string $resource = TestPaymentExceptionResource::class;
}
