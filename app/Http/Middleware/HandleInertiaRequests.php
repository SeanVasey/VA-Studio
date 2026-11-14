<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);
        // The Tax licenses shell sets its private headers in the controller; keep Cookie variance after Inertia's Vary.
        if ($request->is('orders/*/checkout/return', 'paid-tax-licenses')) {
            $response->setVary(['Cookie', 'X-Inertia']);
        }

        return $response;
    }
}
