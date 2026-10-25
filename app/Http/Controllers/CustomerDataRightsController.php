<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\DataRightsPolicy;
use App\Http\Middleware\CustomerPrivacy;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/** Customer data export and deletion requests (FP-082). Default-off: every route answers 404 until enabled. */
final class CustomerDataRightsController
{
    public function index(Request $request): Response
    {
        return $this->handle($request, fn ($rights, $principal, $actor) => response()->json(['dataRequests' => $rights->list($principal, $actor)]), null);
    }

    public function export(Request $request): Response
    {
        return $this->handle($request, function ($rights, $principal, $actor, array $body) {
            if ($body['confirm'] !== true) {
                throw new DataRightsException(422, 'invalid_request');
            }

            return response()->json(['export' => $rights->requestExport($principal, $actor)], 201);
        }, ['confirm']);
    }

    public function download(Request $request): Response
    {
        return $this->handle($request, function ($rights, $principal, $actor, array $body) {
            if (! is_string($body['requestId']) || ! is_string($body['token'])) {
                throw new DataRightsException(422, 'invalid_request');
            }
            $json = $rights->download($principal, $actor, $body['requestId'], $body['token']);

            return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="vasey-audio-data-export.json"']);
        }, ['requestId', 'token']);
    }

    public function deletion(Request $request): Response
    {
        return $this->handle($request, function ($rights, $principal, $actor, array $body) {
            if ($body['confirm'] !== true) {
                throw new DataRightsException(422, 'invalid_request');
            }
            $result = $rights->requestDeletion($principal, $actor);

            return response()->json(['deletion' => $result], $result['replayed'] ? 200 : 201);
        }, ['confirm']);
    }

    /** @param list<string>|null $keys exact JSON object keys for POST bodies; null for reads */
    private function handle(Request $request, callable $operation, ?array $keys): Response
    {
        try {
            if (! app(DataRightsPolicy::class)->enabled()) {
                return $this->error(404, 'not_enabled');
            }
            $body = $keys === null ? [] : $this->body($request, $keys);
            $identity = app(CommerceRequestIdentity::class);
            $principal = $identity->principal($request);
            $actor = $identity->actor($request);
            if ($principal === null || $actor === null) {
                return $this->error(403, 'sign_in_required');
            }

            return CustomerPrivacy::protect($operation(app(CustomerDataRights::class), $principal, $actor, $body));
        } catch (CustomerAccessException) {
            return $this->error(403, 'sign_in_required');
        } catch (DataRightsException $error) {
            return $this->error($error->status, $error->reason);
        }
    }

    /** One bounded JSON object with exactly the expected keys; duplicate keys, nesting and extra fields are refused. */
    private function body(Request $request, array $keys): array
    {
        $raw = $request->attributes->get('_customer_body');
        try {
            $object = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, false, 3, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $object = null;
        }
        if (! $object instanceof stdClass) {
            throw new DataRightsException(422, 'invalid_request');
        }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"\s*:/s', $raw, $tokens);
        $body = get_object_vars($object);
        $actual = array_keys($body);
        sort($actual);
        sort($keys);
        if (count($tokens[0]) !== count($body) || $actual !== $keys) {
            throw new DataRightsException(422, 'invalid_request');
        }
        foreach ($body as $value) {
            if (is_array($value) || $value instanceof stdClass) {
                throw new DataRightsException(422, 'invalid_request');
            }
        }

        return $body;
    }

    private function error(int $status, string $reason): Response
    {
        $message = match ($status) {
            404 => 'This request is not available.',
            409 => 'A request of this kind is already open. Check your requests before trying again.',
            410 => 'This export is no longer available. Request a new export.',
            413 => 'Your data is too large to export here. Contact support@vasey.audio.',
            default => 'Your data request could not be completed. Try again later or contact support@vasey.audio.',
        };

        return CustomerPrivacy::protect(response()->json(['code' => 'CUSTOMER_DATA_REQUEST_UNAVAILABLE', 'reason' => $reason, 'message' => $message], $status));
    }
}
