<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipCustomerHistory;
use App\Http\Middleware\CustomerPrivacy;
use App\Support\CommerceRequestIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class CustomerMembershipHistoryController
{
    public function index(Request $request): Response
    {
        return $this->read($request);
    }

    public function show(Request $request, string $bucket): Response
    {
        return $this->read($request, (int) $bucket);
    }

    private function read(Request $request, ?int $bucket = null): Response
    {
        $stream = $request->getContent(true);
        if (! is_resource($stream) || stream_get_contents($stream, 1) !== '') {
            return CustomerPrivacy::error(422);
        }
        try {
            $identity = app(CommerceRequestIdentity::class);
            $principal = $identity->principal($request);
            $buyer = $identity->actor($request);
            if ($principal === null || $buyer === null) {
                return CustomerPrivacy::error(403);
            }
            $history = $bucket === null
                ? app(MembershipCustomerHistory::class)->buckets($principal, $buyer)
                : app(CreditLedger::class)->customerHistory($bucket, $principal, $buyer);

            return CustomerPrivacy::protect(response()->json(['history' => $history]));
        } catch (AuthorizationException|CustomerAccessException|ValidationException) {
            return CustomerPrivacy::error(403);
        }
    }
}
