<?php

namespace App\Filament\Resources\ContractIdentityHoldResource\Pages;

use App\Filament\Resources\ContractIdentityHoldResource;
use App\Filament\Resources\ReadOnlyCommerceResource\Pages\ListTestCommerceRecords;

/** Reauthorizes staff (and required MFA) on every Livewire request through ListTestCommerceRecords::boot(). */
final class ListContractIdentityHolds extends ListTestCommerceRecords
{
    protected static string $resource = ContractIdentityHoldResource::class;
}
