<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\QuoteException;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\PurchaseAccess;
use App\Domain\Delivery\DeliveryByteRange;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\ReadTestDeliveryAuthorization;
use App\Domain\Delivery\ReadTestOwnerDelivery;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Domain\Delivery\TestAccessPolicy;
use App\Domain\Delivery\TransferTestDelivery;
use App\Http\Requests\TestDeliveryRequest;
use App\Http\Responses\TestDeliveryResponse;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class TestOwnerDeliveryController
{
    public function show(string $order, Request $request, CommerceRequestIdentity $owner, ReadTestOwnerDelivery $read): Response
    {
        return $this->run(fn () => response()->json(['delivery' => app(PurchaseAccess::class)->read($order, $owner->forRequest($request), $owner->principal($request), fn ($original) => $read->handle($order, $original))], 200, TestDeliveryResponse::headers()));
    }

    public function issue(string $order, Request $request, CommerceRequestIdentity $owner, IssueTestDelivery $issue): Response
    {
        return $this->run(function () use ($order, $request, $owner, $issue): Response {
            $body = TestDeliveryRequest::issuance($request);
            $authorization = $issue->handle($order, $owner->forRequest($request), $body['grantId'], $body['kind'], $body['idempotencyKey'], $owner->actor($request), $owner->principal($request), self::sessionSecret($request, true));

            return response()->json(['authorization' => [
                'authorizationId' => $authorization->authorizationId, 'token' => $authorization->token(),
                'expiresAt' => $authorization->expiresAt->toIso8601ZuluString(),
                'filename' => $authorization->filename, 'mimeType' => $authorization->mimeType,
            ]], 201, TestDeliveryResponse::headers());
        });
    }

    public function download(string $order, Request $request, CommerceRequestIdentity $owner, ReadTestDeliveryAuthorization $read, RedeemTestDelivery $redeem): Response
    {
        return $this->run(function () use ($order, $request, $owner, $read, $redeem): Response {
            $body = TestDeliveryRequest::download($request);
            $ownerKey = app(PurchaseAccess::class)->owner($order, $owner->forRequest($request), $owner->principal($request));
            // The retained immutable target supplies attachment metadata; the command re-verifies it before consumption.
            $context = $read->forOwner($order, $ownerKey, $body['authorizationId'], $body['token']);
            if ($context['authorization']->policy_version === TestAccessPolicy::V2) {
                // v2: the token proves possession once, in the session the authorization is bound to; the transfer is a GET
                // whose URL carries only the opaque ID. Nothing is consumed here.
                $read->forSession($order, $ownerKey, $body['authorizationId'], self::sessionSecret($request, false));
                self::present($request, $body['authorizationId']);

                return TestDeliveryResponse::seeOther(route('orders.delivery-transfer', ['order' => $order, 'authorization' => $body['authorizationId']]));
            }
            $prepared = $redeem->handle($order, $ownerKey, $body['authorizationId'], $body['token'], $owner->actor($request), $owner->principal($request));

            return TestDeliveryResponse::attachment($prepared, $context['target']['filename'], $context['target']['mime_type']);
        });
    }

    /** v2 transfer: same session (binding plus token presented in it), same owner, then every redemption check again. */
    public function transfer(string $order, string $authorization, Request $request, CommerceRequestIdentity $owner,
        ReadTestDeliveryAuthorization $read, TransferTestDelivery $transfer): Response
    {
        return $this->run(function () use ($order, $authorization, $request, $owner, $read, $transfer): Response {
            $secret = self::sessionSecret($request, false);
            if ($secret === null || ! self::presented($request, $authorization)) {
                throw new DeliveryException('not_found');
            }
            $ownerKey = app(PurchaseAccess::class)->owner($order, $owner->forRequest($request), $owner->principal($request));
            $context = $read->forSession($order, $ownerKey, $authorization, $secret);
            // Evaluated only after owner and session matched; the command re-checks size and hash against its own read.
            $range = DeliveryByteRange::evaluate($request->headers->get('Range'), $request->headers->get('If-Range'),
                $context['target']['file']['size_bytes'], $context['target']['file']['sha256']);
            try {
                $admitted = $transfer->handle($order, $ownerKey, $authorization, $secret, $range, $owner->actor($request), $owner->principal($request));
            } catch (DeliveryException $error) {
                if ($error->reason === 'range_not_satisfiable') {
                    return TestDeliveryResponse::rangeNotSatisfiable($range->sizeBytes);
                }
                throw $error;
            }

            return TestDeliveryResponse::transfer($admitted, $context['target']['filename'], $context['target']['mime_type']);
        });
    }

    private const SESSION_KEY = '_test_delivery_session';

    private const PRESENTED_LIMIT = 32;

    /** A random secret held only in the server-side session; logout (session invalidation) discards it. */
    private static function sessionSecret(Request $request, bool $create): ?string
    {
        $state = $request->session()->get(self::SESSION_KEY);
        if (is_array($state) && is_string($state['secret'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $state['secret']) === 1) {
            return $state['secret'];
        }
        if (! $create) {
            return null;
        }
        $secret = bin2hex(random_bytes(32));
        $request->session()->put(self::SESSION_KEY, ['secret' => $secret, 'presented' => []]);

        return $secret;
    }

    private static function present(Request $request, string $authorization): void
    {
        $state = $request->session()->get(self::SESSION_KEY);
        $presented = is_array($state['presented'] ?? null) ? array_values(array_filter($state['presented'], 'is_string')) : [];
        $presented = array_values(array_diff($presented, [$authorization]));
        $presented[] = $authorization;
        $state['presented'] = array_slice($presented, -self::PRESENTED_LIMIT);
        $request->session()->put(self::SESSION_KEY, $state);
    }

    private static function presented(Request $request, string $authorization): bool
    {
        $presented = $request->session()->get(self::SESSION_KEY)['presented'] ?? null;

        return is_array($presented) && in_array($authorization, $presented, true);
    }

    private function run(callable $operation): Response
    {
        try {
            return $operation();
        } catch (CustomerAccessException $error) {
            return TestDeliveryResponse::error(403);
        } catch (QuoteException $error) {
            return TestDeliveryResponse::error($error->status);
        } catch (DeliveryException $error) {
            return TestDeliveryResponse::domainError($error);
        } catch (HttpExceptionInterface $error) {
            return TestDeliveryResponse::error($error->getStatusCode());
        } catch (Throwable $error) {
            try {
                Log::error('Test delivery request failed.', ['exception_class' => $error::class]);
            } catch (Throwable) { /* A failed logger must not expose the original request or exception. */
            }

            return TestDeliveryResponse::error(503);
        }
    }
}
