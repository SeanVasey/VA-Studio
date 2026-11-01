<?php

namespace App\Console\Commands;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\OwnAccountStripeGateway;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Http\ProductionCheckout\ProductionCheckoutMount;
use Illuminate\Console\Command;
use Throwable;

/**
 * Retrieval-only operator reconciliation for one production checkout order (lane PCC; activation packet section 1, "no
 * operator command for production reconciliation"). It is the production counterpart of `vasey:reconcile-test-payments`:
 * authenticated GETs of the order's Checkout Session and PaymentIntent, then the same retained persistence as the buyer's
 * reconcile route (HostedCheckout::operatorReconcile). It never creates a session or a payment and never trusts a redirect.
 *
 * It refuses, before any query, unless the composition gate admits (`production_checkout.mounted`, a code literal) and
 * `operator_reconciliation_enabled`, `reconciliation_enabled` and `provider_io_enabled` are all literally true. Idempotent: a
 * repeated run with unchanged provider state writes nothing. Output is one JSON line of identifiers and states; exception text,
 * provider bodies, buyer data and credentials are never printed. Exit codes: 0 reconciled, 1 refused or uncertain.
 */
final class ReconcileProductionCheckout extends Command
{
    protected $signature = 'vasey:production-checkout-reconcile
        {order : Production checkout order public id (UUID)}
        {--session= : Provider Checkout Session id, used only while the order has no bound session (a lost create response)}';

    protected $description = 'Retrieve and retain the provider state of one production checkout order (default-off; retrieval only).';

    public function handle(): int
    {
        $order = $this->argument('order');
        $session = $this->option('session');
        if (! is_string($order) || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $order) !== 1
            || ($session !== null && ! (OwnAccountStripeGateway::sessionId($session, 'test') || OwnAccountStripeGateway::sessionId($session, 'live')))) {
            return $this->refuse('invalid');
        }
        if (! ProductionCheckoutMount::admitted()) {
            return $this->refuse('not_mounted');
        }
        foreach (['operator_reconciliation_enabled', 'reconciliation_enabled', 'provider_io_enabled'] as $flag) {
            if (config('production_checkout.'.$flag) !== true) {
                return $this->refuse('disabled');
            }
        }
        if (! app()->bound(ProviderGateway::class)) {
            return $this->refuse('not_composed');
        }
        try {
            $result = app(HostedCheckout::class)->operatorReconcile($order, $session);
        } catch (CheckoutException $error) {
            return $this->refuse(preg_match('/\A[a-z_]{1,32}\z/', $error->reason) === 1 ? $error->reason : 'unavailable');
        } catch (Throwable) {
            return $this->refuse('unavailable');
        }
        $this->line(json_encode(['code' => 'reconciled', ...$result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->line(json_encode(['code' => 'refused', 'reason' => $reason], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
