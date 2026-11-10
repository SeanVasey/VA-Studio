<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\DataRightsPolicy;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Http\Middleware\CustomerPrivacy;
use App\Models\User;
use App\Support\CommerceRequestIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer data export and deletion requests (FP-082, BL-44). Default-off: every route answers 404 until enabled.
 *
 * Two paths never widen each other. A test-account session (`_customer_access`) needs the test customer policy; a production
 * session (`_production_customer_identity`) needs the production identity policy. Both need `customer-data-rights.enabled`. A
 * session carrying both markers is refused, and a marker whose path is off answers 404 as if the route did not exist.
 */
final class CustomerDataRightsController
{
    public function index(Request $request): Response
    {
        return $this->handle($request, fn ($rights, $principal, $actor) => response()->json(['dataRequests' => $principal instanceof ProductionCustomerPrincipal ? $rights->listProduction($principal, $actor) : $rights->list($principal, $actor)]), null);
    }

    public function export(Request $request): Response
    {
        return $this->handle($request, function ($rights, $principal, $actor, array $body) {
            if ($body['confirm'] !== true) {
                throw new DataRightsException(422, 'invalid_request');
            }

            return response()->json(['export' => $principal instanceof ProductionCustomerPrincipal ? $rights->requestProductionExport($principal, $actor) : $rights->requestExport($principal, $actor)], 201);
        }, ['confirm']);
    }

    public function download(Request $request): Response
    {
        return $this->handle($request, function ($rights, $principal, $actor, array $body) {
            if (! is_string($body['requestId']) || ! is_string($body['token'])) {
                throw new DataRightsException(422, 'invalid_request');
            }
            $json = $principal instanceof ProductionCustomerPrincipal ? $rights->downloadProduction($principal, $actor, $body['requestId'], $body['token'])
                : $rights->download($principal, $actor, $body['requestId'], $body['token']);

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
            $result = $principal instanceof ProductionCustomerPrincipal ? $rights->requestProductionDeletion($principal, $actor) : $rights->requestDeletion($principal, $actor);

            return response()->json(['deletion' => $result], $result['replayed'] ? 200 : 201);
        }, ['confirm']);
    }

    /** @param list<string>|null $keys exact JSON object keys for POST bodies; null for reads */
    private function handle(Request $request, callable $operation, ?array $keys): Response
    {
        try {
            $policy = app(DataRightsPolicy::class);
            if (! $policy->anyEnabled()) {
                return $this->error(404, 'not_enabled');
            }
            $body = $keys === null ? [] : $this->body($request, $keys);
            [$principal, $actor] = $this->identity($request, $policy);
            if ($principal === null || $actor === null) {
                return $this->error(403, 'sign_in_required');
            }

            return CustomerPrivacy::protect($operation(app(CustomerDataRights::class), $principal, $actor, $body));
        } catch (CustomerAccessException|IdentityException) {
            return $this->error(403, 'sign_in_required');
        } catch (DataRightsException $error) {
            return $this->error($error->status, $error->reason);
        } catch (QueryException $error) {
            // A deadlock or lock-wait timeout that outlived the service's retries is contention, not a fault: ask the customer to retry.
            if (CustomerDataRights::concurrencyError($error)) {
                return $this->error(503, 'try_again')->header('Retry-After', '5');
            }
            throw $error;
        }
    }

    /**
     * The principal and actor the session proves, from server state only. A production principal is minted from the production
     * origin and the credential on file (`ProductionCustomerSessions::principal`), never from a value in the request.
     *
     * @return array{0: CustomerPrincipal|ProductionCustomerPrincipal|null, 1: ?User}
     */
    private function identity(Request $request, DataRightsPolicy $policy): array
    {
        $production = $request->session()->has('_production_customer_identity');
        $test = $request->session()->has('_customer_access');
        if ($production && $test) {
            throw new CustomerAccessException;
        }
        if ($production) {
            $policy->requireProductionEnabled();
            $actor = Auth::guard('customer')->user();
            if (! $actor instanceof User) {
                throw new IdentityException;
            }

            return [(new ProductionCustomerSessions)->principal($request), $actor];
        }
        if (! $test) {
            // A guest, or a guard user with no session marker (so no proof of how it signed in): never a customer.
            return [null, null];
        }
        $policy->requireEnabled();
        $identity = app(CommerceRequestIdentity::class);

        return [$identity->principal($request), $identity->actor($request)];
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
        $message = $reason === 'try_again' ? 'Your data request could not be completed right now. Please try again in a few seconds.' : match ($status) {
            404 => 'This request is not available.',
            409 => 'A request of this kind is already open. Check your requests before trying again.',
            410 => 'This export is no longer available. Request a new export.',
            413 => 'Your data is too large to export here. Contact support@vasey.audio.',
            default => 'Your data request could not be completed. Try again later or contact support@vasey.audio.',
        };

        return CustomerPrivacy::protect(response()->json(['code' => 'CUSTOMER_DATA_REQUEST_UNAVAILABLE', 'reason' => $reason, 'message' => $message], $status));
    }
}
