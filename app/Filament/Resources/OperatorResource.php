<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Support\Facades\Gate;

abstract class OperatorResource extends Resource
{
    public static function canAccess(): bool
    {
        return Gate::allows('administer-catalog');
    }
}
