<?php

namespace App\Filament\Resources;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** List-only metadata resources. All mutation, record-detail and bulk abilities are denied. */
abstract class ReadOnlyCommerceResource extends OperatorResource
{
    protected static string|UnitEnum|null $navigationGroup = 'Test commerce';

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return $action === 'viewAny' && Gate::allows('administer-catalog')
            ? Response::allow() : Response::deny();
    }
}
