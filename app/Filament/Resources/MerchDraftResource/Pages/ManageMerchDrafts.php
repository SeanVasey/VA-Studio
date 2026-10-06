<?php

namespace App\Filament\Resources\MerchDraftResource\Pages;

use App\Filament\Resources\MerchDraftResource;
use App\Filament\Resources\PrivateProductDraftResource\Pages\ManagePrivateProductDrafts;

final class ManageMerchDrafts extends ManagePrivateProductDrafts
{
    protected static string $resource = MerchDraftResource::class;
}
