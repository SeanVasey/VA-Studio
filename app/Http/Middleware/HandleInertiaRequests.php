<?php

namespace App\Http\Middleware;

use Inertia\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);
        if ($request->is('orders/*/checkout/return')) {
            $response->setVary(['Cookie', 'X-Inertia']);
        }

        return $response;
    }
}
