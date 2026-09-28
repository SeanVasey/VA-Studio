<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\ReadTestDeliveryAuthorization;
use App\Domain\Delivery\ReadTestOwnerDelivery;
use App\Domain\Delivery\RedeemTestDelivery;
use App\Http\Requests\TestDeliveryRequest;
use App\Http\Responses\TestDeliveryResponse;
use App\Support\QuoteOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class TestOwnerDeliveryController
{
    public function show(string $order, Request $request, QuoteOwner $owner, ReadTestOwnerDelivery $read): Response
    {
        return $this->run(fn () => response()->json(['delivery' => $read->handle($order, $owner->forRequest($request))], 200, TestDeliveryResponse::headers()));
    }

    public function issue(string $order, Request $request, QuoteOwner $owner, IssueTestDelivery $issue): Response
    {
        return $this->run(function () use ($order, $request, $owner, $issue): Response {
            $body = TestDeliveryRequest::issuance($request);
            $authorization = $issue->handle($order, $owner->forRequest($request), $body['grantId'], $body['kind'], $body['idempotencyKey']);
            return response()->json(['authorization' => [
                'authorizationId' => $authorization->authorizationId, 'token' => $authorization->token(),
                'expiresAt' => $authorization->expiresAt->toIso8601ZuluString(),
                'filename' => $authorization->filename, 'mimeType' => $authorization->mimeType,
            ]], 201, TestDeliveryResponse::headers());
        });
    }

    public function download(string $order, Request $request, QuoteOwner $owner, ReadTestDeliveryAuthorization $read, RedeemTestDelivery $redeem): Response
    {
        return $this->run(function () use ($order, $request, $owner, $read, $redeem): Response {
            $body = TestDeliveryRequest::download($request);
            $ownerKey = $owner->forRequest($request);
            // The retained immutable target supplies attachment metadata; the command re-verifies it before consumption.
            $context = $read->forOwner($order, $ownerKey, $body['authorizationId'], $body['token']);
            $prepared = $redeem->handle($order, $ownerKey, $body['authorizationId'], $body['token']);
            return TestDeliveryResponse::attachment($prepared, $context['target']['filename'], $context['target']['mime_type']);
        });
    }

    private function run(callable $operation): Response
    {
        try { return $operation(); }
        catch (DeliveryException $error) { return TestDeliveryResponse::domainError($error); }
        catch (HttpExceptionInterface $error) { return TestDeliveryResponse::error($error->getStatusCode()); }
        catch (Throwable $error) {
            try { Log::error('Test delivery request failed.', ['exception_class' => $error::class]); }
            catch (Throwable) { /* A failed logger must not expose the original request or exception. */ }
            return TestDeliveryResponse::error(503);
        }
    }
}
