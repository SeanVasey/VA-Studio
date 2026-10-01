<?php

namespace App\Filament\Resources\CustomerInquiryResource\Components;

use App\Domain\Inquiries\InquiryAdministration;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;

final class InquiryTextColumn extends TextColumn
{
    public function getState(): mixed
    {
        // Filament can defer a cast or return an already cached column state.
        // Both paths must complete while the current operator remains locked.
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::getState());
    }
}
