<?php

namespace App\Console\Commands;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\MembershipCustomerPortal;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator rehearsal tool (BL-69), never scheduled and never reachable over HTTP. Opens a Stripe TEST-mode Customer Portal
 * session for the member's own approved subscription customer. Refuses while the Billing policy or write switch is off (the
 * default), outside the test-commerce environments, and for a member with no approved subscription. The short-lived portal URL is
 * printed only with --show-url and is never stored.
 */
final class MembershipBillingTestPortal extends Command
{
    protected $signature = 'membership-billing:test-portal {user : Member user id} {--request-key= : Names one action (default: a fresh key)} {--show-url : Print the short-lived portal URL}';

    protected $description = 'Open a Stripe test-mode Customer Portal session for a member (default-off, test commerce environments only).';

    public function handle(MembershipCustomerPortal $service): int
    {
        $user = User::query()->find($this->argument('user'));
        if ($user === null) {
            $this->error('Unknown user.');

            return self::INVALID;
        }
        $key = $this->option('request-key');
        $key = is_string($key) && $key !== '' ? $key : bin2hex(random_bytes(16));
        try {
            $result = $service->open(ProductionCustomerPrincipal::forUser($user), $user, $key);
        } catch (BillingException $error) {
            $this->error('Refused ('.$error->reason.').');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Refused (unavailable).');

            return self::FAILURE;
        }
        $this->info(sprintf('Opened test portal session %s. Request key: %s', $result['session_ref'], $key));
        if ($this->option('show-url')) {
            $this->line($result['url']);
        }

        return self::SUCCESS;
    }
}
