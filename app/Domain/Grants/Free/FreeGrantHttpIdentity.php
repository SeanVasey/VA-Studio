<?php

namespace App\Domain\Grants\Free;

use Illuminate\Http\Request;

/** Return only a server session's current typed principal and actor, never a cached-user remint. */
interface FreeGrantHttpIdentity
{
    public function forRequest(Request $request): array;
}
