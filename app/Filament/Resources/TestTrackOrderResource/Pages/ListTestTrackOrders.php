<?php

namespace App\Filament\Resources\TestTrackOrderResource\Pages;

use App\Domain\Delivery\TestDeliveryStaffOperations;
use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\TestTrackOrderResource;

final class ListTestTrackOrders extends ListTestCommerceRecords
{
    protected static string $resource = TestTrackOrderResource::class;

    public function boot(): void
    {
        // Every Livewire request: where the delivery control cannot act (production, live mode, no account) the page is absent.
        abort_unless(app(TestDeliveryStaffOperations::class)->admitted(), 404);
        parent::boot();
    }
}
