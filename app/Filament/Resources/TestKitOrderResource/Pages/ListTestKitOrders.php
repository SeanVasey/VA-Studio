<?php

namespace App\Filament\Resources\TestKitOrderResource\Pages;

use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;
use App\Filament\Resources\TestKitOrderResource;

final class ListTestKitOrders extends ListTestCommerceRecords
{
    protected static string $resource = TestKitOrderResource::class;

    public function boot(): void
    {
        // Every Livewire request: a closed kit lane (or production) is indistinguishable from absence.
        abort_unless(app(SoundKitPurchasePolicy::class)->admitted(), 404);
        parent::boot();
    }
}
