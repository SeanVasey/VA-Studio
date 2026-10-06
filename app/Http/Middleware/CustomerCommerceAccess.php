<?php

namespace App\Http\Middleware;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Support\CommerceRequestIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerCommerceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('quotes', 'quotes/*', 'orders', 'orders/*')) {
            return $next($request);
        }
        try {
            $principal = app(CommerceRequestIdentity::class)->principal($request);
            $response = $next($request);
            // Projection reads and prepared attachments must not escape after an observed withdrawal.
            if ($principal) {
                app(CustomerAccess::class)->current($principal);
                CustomerPrivacy::protect($response);
            }

            return $response;
        } catch (CustomerAccessException) {
            return CustomerPrivacy::error(403);
        }
    }
}
