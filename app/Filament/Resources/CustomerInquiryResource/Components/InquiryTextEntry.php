<?php

namespace App\Filament\Resources\CustomerInquiryResource\Components;

use App\Domain\Inquiries\InquiryAdministration;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;

final class InquiryTextEntry extends TextEntry
{
    public function getState(): mixed
    {
        // A resolved model is not an authorization grant for a later infolist read.
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::getState());
    }
}
