<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\CheckoutIntent;
use Illuminate\Console\Command;
use Throwable;

/** Recoverable durable-intent scan; never depends on a transient after-commit dispatch. */
final class ReconcileTestCheckout extends Command
{
    protected $signature = 'vasey:reconcile-test-checkout {intent? : Opaque checkout intent UUID} {--session= : Known Stripe test session locator} {--limit=25 : Maximum unresolved intents to inspect (1–100)}';

    protected $description = 'Reconcile retained Stripe test checkout intents without confirming payment or releasing resources';

    public function handle(HostedCheckout $checkout): int
    {
        $id = $this->argument('intent'); $session = $this->option('session'); $limit = $this->option('limit');
        if (! is_string($limit) || ! preg_match('/\A(?:[1-9][0-9]?|100)\z/', $limit) || ($session !== null && $id === null)) {
            $this->error('Supply a valid limit and an intent for explicit session recovery.'); return self::FAILURE;
        }
        $query = CheckoutIntent::query()->orderBy('id');
        if ($id !== null) { $query->where('public_id', $id); }
        else { $query->whereDoesntHave('session')->where('retry_before', '>', now()); }
        $intents = $query->limit((int) $limit)->get();
        if ($id !== null && $intents->isEmpty()) { $this->error('Checkout intent unavailable.'); return self::FAILURE; }
        $failed = false;
        foreach ($intents as $intent) {
            try {
                $result = $checkout->recover($intent, $session);
                $this->line($intent->public_id.' '.$result['status']);
            } catch (Throwable) {
                // Never echo SDK exceptions, private URLs, raw evidence or buyer information.
                $this->error($intent->public_id.' reconciliation unavailable'); $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
