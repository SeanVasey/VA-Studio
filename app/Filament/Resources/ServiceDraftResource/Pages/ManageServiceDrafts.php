<?php

namespace App\Filament\Resources\ServiceDraftResource\Pages;

use App\Filament\Resources\PrivateProductDraftResource\Pages\ManagePrivateProductDrafts;
use App\Filament\Resources\ServiceDraftResource;

final class ManageServiceDrafts extends ManagePrivateProductDrafts
{
    protected static string $resource = ServiceDraftResource::class;
}
