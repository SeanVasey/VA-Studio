<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Http\Responses\ProductionCheckoutResponse as PrivateResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Identifier-only HTTP commands. Amounts, provenance, payment and owner bindings are server evidence. */
final class ProductionCheckoutController
{
    public function review(Request $request): Response
    {
        return $this->run($request, function (ProductionCustomerAccess $access, User $buyer, array $body, ProductionCustomerPrincipal $principal): array {
            Evidence::keys($body, ['candidateId', 'items', 'basisId', 'buyer', 'requestKey']);
            CheckoutException::require(is_int($body['candidateId']) && $body['candidateId'] > 0 && is_array($body['items'])
                && is_array($body['buyer']) && self::uuid($body['basisId']) && is_string($body['requestKey']), 'invalid', 422);

            return app(ProductionCheckout::class)->review($principal, $buyer, $body['candidateId'],
                $body['items'], $body['basisId'], $body['buyer'], $body['requestKey']);
        });
    }

    public function accept(Request $request): Response
    {
        return $this->run($request, function (ProductionCustomerAccess $access, User $buyer, array $body, ProductionCustomerPrincipal $principal): array {
            Evidence::keys($body, ['reviewId', 'reviewHash', 'accepted', 'requestKey']);
            CheckoutException::require(self::uuid($body['reviewId']) && Evidence::hash($body['reviewHash'])
                && $body['accepted'] === true && is_string($body['requestKey']), 'invalid', 422);

            return app(ProductionCheckout::class)->accept($principal, $buyer, $body['reviewId'],
                $body['reviewHash'], true, $body['requestKey']);
        });
    }

    public function initiate(Request $request, string $order): Response
    {
        return $this->run($request, function (ProductionCustomerAccess $access, User $buyer, array $body, ProductionCustomerPrincipal $principal) use ($order): array {
            Evidence::keys($body, []);

            return app(HostedCheckout::class)->initiate($principal, $buyer, $order);
        });
    }

    public function reconcile(Request $request, string $order): Response
    {
        return $this->run($request, function (ProductionCustomerAccess $access, User $buyer, array $body, ProductionCustomerPrincipal $principal) use ($order): array {
            CheckoutException::require($body === [] || array_keys($body) === ['sessionLocator'], 'invalid', 422);
            $locator = $body['sessionLocator'] ?? null;
            CheckoutException::require($locator === null || (is_string($locator) && strlen($locator) <= 128), 'invalid', 422);

            // This locator only selects a fresh authenticated GET; it never confirms a payment.
            return app(HostedCheckout::class)->reconcile($principal, $buyer, $order, $locator);
        });
    }

    public function status(Request $request, string $order): Response
    {
        return $this->run($request, fn (ProductionCustomerAccess $access, User $buyer, array $body, ProductionCustomerPrincipal $principal): array => app(HostedCheckout::class)->status($principal, $buyer, $order));
    }

    public function returned(Request $request, string $order): Response
    {
        // Browser return uses the same retained read; no provider call or financial write.
        return $this->status($request, $order);
    }

    private function run(Request $request, callable $action): Response
    {
        try {
            CheckoutException::require(config('production_checkout.http_enabled') === true, 'disabled', 503);
            $body = [];
            if ($request->isMethod('POST')) {
                $raw = $request->attributes->get('_production_checkout_body');
                CheckoutException::require(is_string($raw), 'invalid', 422);
                $decoded = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
                CheckoutException::require($decoded instanceof stdClass, 'invalid', 422);
                // JSON decoding collapses duplicate keys. Count all lexical strings against the
                // decoded tree to refuse hidden/escaped duplicates, including nested items.
                CheckoutException::require(preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $raw) === self::strings($decoded), 'invalid', 422);
                $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            }
            $buyer = $request->user('customer');
            CheckoutException::require($buyer instanceof User, 'identity', 403);
            $principal = app(ProductionCustomerSessions::class)->principal($request);
            $access = app(ProductionCustomerAccess::class);

            return PrivateResponse::protect(response()->json(['checkout' => $action($access, $buyer, $body, $principal)]));
        } catch (CheckoutException $error) {
            return PrivateResponse::error($error->status);
        } catch (IdentityException) {
            return PrivateResponse::error(403);
        } catch (ValidationException) {
            return PrivateResponse::error(422);
        } catch (\JsonException) {
            return PrivateResponse::error(422);
        } catch (Throwable) {
            return PrivateResponse::error(503);
        }
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $value) === 1;
    }

    private static function strings(mixed $value): int
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            return count($properties) + array_sum(array_map(self::strings(...), $properties));
        }
        if (is_array($value)) {
            return array_sum(array_map(self::strings(...), $value));
        }

        return is_string($value) ? 1 : 0;
    }
}
