<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Http\Responses\ProductionTaxCheckoutResponse as PrivateResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Identifier-only HTTP commands. Amounts, tax, provenance, payment and owner bindings are server or provider
 * evidence; no request field can carry a total or a tax figure. The principal is resolved only through the
 * T23 session floor `ProductionCustomerSessions::principal(Request)`.
 */
final class ProductionTaxCheckoutController
{
    public function preview(Request $request): Response
    {
        return $this->run($request, function (User $buyer, array $body, ProductionCustomerPrincipal $principal): array {
            Evidence::keys($body, ['candidateId', 'items']);
            CheckoutException::require(is_int($body['candidateId']) && $body['candidateId'] > 0 && is_array($body['items']), 'invalid', 422);

            return app(ProductionTaxCheckout::class)->preview($principal, $buyer, $body['candidateId'], $body['items']);
        });
    }

    public function order(Request $request): Response
    {
        return $this->run($request, function (User $buyer, array $body, ProductionCustomerPrincipal $principal): array {
            Evidence::keys($body, ['candidateId', 'items', 'previewHash', 'accepted', 'buyer', 'requestKey']);
            CheckoutException::require(is_int($body['candidateId']) && $body['candidateId'] > 0 && is_array($body['items'])
                && Evidence::hash($body['previewHash']) && $body['accepted'] === true && is_array($body['buyer'])
                && is_string($body['requestKey']), 'invalid', 422);

            return app(ProductionTaxCheckout::class)->order($principal, $buyer, $body['candidateId'], $body['items'],
                $body['previewHash'], true, $body['buyer'], $body['requestKey']);
        });
    }

    public function initiate(Request $request, string $order): Response
    {
        return $this->run($request, function (User $buyer, array $body, ProductionCustomerPrincipal $principal) use ($order): array {
            Evidence::keys($body, []);

            return app(ProductionTaxCheckout::class)->initiate($principal, $buyer, $order);
        });
    }

    public function reconcile(Request $request, string $order): Response
    {
        return $this->run($request, function (User $buyer, array $body, ProductionCustomerPrincipal $principal) use ($order): array {
            // No browser locator is accepted: only the retained provider binding selects the authoritative GET.
            Evidence::keys($body, []);

            return app(ProductionTaxCheckout::class)->reconcile($principal, $buyer, $order);
        });
    }

    public function status(Request $request, string $order): Response
    {
        return $this->run($request, fn (User $buyer, array $body, ProductionCustomerPrincipal $principal): array => app(ProductionTaxCheckout::class)->status($principal, $buyer, $order));
    }

    public function returned(Request $request, string $order): Response
    {
        // Browser return is the same retained read: no provider call and no financial write.
        return $this->status($request, $order);
    }

    private function run(Request $request, callable $action): Response
    {
        try {
            CheckoutException::require(TaxCheckoutPolicy::enabled(), 'disabled', 503);
            $body = [];
            if ($request->isMethod('POST')) {
                $raw = $request->attributes->get('_production_tax_checkout_body');
                CheckoutException::require(is_string($raw), 'invalid', 422);
                $decoded = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
                CheckoutException::require($decoded instanceof stdClass, 'invalid', 422);
                // JSON decoding collapses duplicate keys. Count all lexical strings against the decoded tree
                // to refuse hidden or escaped duplicates, including nested items.
                CheckoutException::require(preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $raw) === self::strings($decoded), 'invalid', 422);
                $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            }
            $buyer = $request->user('customer');
            CheckoutException::require($buyer instanceof User, 'identity', 403);
            $principal = app(ProductionCustomerSessions::class)->principal($request);

            return PrivateResponse::protect(response()->json(['checkout' => $action($buyer, $body, $principal)]));
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
