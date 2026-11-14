<?php

namespace App\Console\Commands;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\MembershipSubscriptionCheckout;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator rehearsal tool (BL-69), never scheduled and never reachable over HTTP. Creates a Stripe TEST-mode Checkout Session in
 * subscription mode for one existing member and one approved plan version. Refuses while any Billing, membership or write switch
 * is off (the default) and outside the test-commerce environments. Prints the session reference and expiry; the hosted URL (a
 * bearer link to a test checkout) only with --show-url. Re-running with the same --request-key returns the original session.
 */
final class MembershipBillingTestCheckout extends Command
{
    protected $signature = 'membership-billing:test-checkout {user : Member user id} {plan_version : Approved plan version id} {--request-key= : Names one action; reuse to retry it (default: a fresh key)} {--show-url : Print the hosted checkout URL}';

    protected $description = 'Create a Stripe test-mode subscription Checkout Session for a member (default-off, test commerce environments only).';

    public function handle(MembershipSubscriptionCheckout $service): int
    {
        $user = User::query()->find($this->argument('user'));
        if ($user === null) {
            $this->error('Unknown user.');

            return self::INVALID;
        }
        $key = $this->option('request-key');
        $key = is_string($key) && $key !== '' ? $key : bin2hex(random_bytes(16));
        try {
            $result = $service->create(ProductionCustomerPrincipal::forUser($user), $user, (string) $this->argument('plan_version'), $key);
        } catch (BillingException $error) {
            $this->error('Refused ('.$error->reason.').');

            return self::FAILURE;
        } catch (Throwable) {
            // Identity, policy and SDK failures can carry provider or member detail; name nothing.
            $this->error('Refused (unavailable).');

            return self::FAILURE;
        }
        $this->info(sprintf('Created test checkout session %s (expires %s UTC) for plan version %s. Request key: %s', $result['session_ref'],
            gmdate('Y-m-d H:i:s', $result['expires_at']), $result['plan_version_id'], $key));
        if ($this->option('show-url')) {
            $this->line($result['url']);
        }

        return self::SUCCESS;
    }
}
