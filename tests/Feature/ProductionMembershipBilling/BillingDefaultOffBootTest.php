<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Domain\Memberships\Billing\StripeSdkBillingGateway;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Jobs\RetrieveMembershipInvoice;
use Illuminate\Contracts\Container\BindingResolutionException;
use Tests\Support\BillingHttpFixture;
use Tests\Support\BillingStripeFixtures as F;
use Tests\TestCase;

/** The shipped configuration and a production boot refuse every Billing entry point before provider I/O. */
class BillingDefaultOffBootTest extends TestCase
{
    public function test_shipped_configuration_is_literal_default_off(): void
    {
        $this->assertSame(['enabled' => false, 'provider_io_enabled' => false, 'account_ref' => null, 'mode' => null,
            'approved_subscription_policy_hash' => null, 'secret_key' => null, 'webhook_secret' => null],
            require base_path('config/production-membership-billing.php'));
        $this->assertSame(require base_path('config/production-membership-billing.php'), config('production-membership-billing'));
    }

    public function test_production_boot_with_defaults_refuses_every_entry_point_without_provider_io(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'));
        $transport = new BillingHttpFixture([]);
        foreach ([
            'policy' => fn () => (new BillingPolicy)->current(),
            'provider_io' => fn () => (new BillingPolicy)->providerIo(),
            'gateway' => fn () => (new StripeSdkBillingGateway($transport))->account(),
            'webhook' => fn () => (new BillingWebhookIntake)->receive('{}', 't=1,v1=00'),
            'reconciliation' => fn () => (new BillingReconciliation(new StripeSdkBillingGateway($transport)))->retrieve('00000000-0000-4000-8000-000000000000', F::INVOICE),
        ] as $entry => $call) {
            try {
                $call();
                $this->fail($entry.' must refuse with shipped defaults.');
            } catch (BillingException $error) {
                $this->assertSame('disabled', $error->reason, $entry);
            }
        }
        $this->assertSame([], $transport->calls);
    }

    public function test_production_refuses_even_when_enabled_and_live_mode_is_not_authorized(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        foreach (['test' => 'provenance', 'live' => 'live_not_authorized', null => 'provenance'] as $mode => $reason) {
            config(['production-membership-billing' => ['enabled' => true, 'provider_io_enabled' => true, 'account_ref' => F::ACCOUNT,
                'mode' => $mode === '' ? null : $mode, 'approved_subscription_policy_hash' => str_repeat('a', 64),
                'secret_key' => null, 'webhook_secret' => null]]);
            try {
                (new BillingPolicy)->current();
                $this->fail('Production billing evidence is not authorized.');
            } catch (BillingException $error) {
                $this->assertSame($reason, $error->reason, (string) $mode);
            }
        }
    }

    public function test_no_gateway_reconciliation_or_paid_invoice_capability_is_registered(): void
    {
        $this->assertFalse($this->app->bound(BillingProviderGateway::class));
        $this->assertFalse($this->app->bound(MembershipPaidInvoiceAuthority::class));
        $this->expectException(BindingResolutionException::class);
        $this->app->call([new RetrieveMembershipInvoice('00000000-0000-4000-8000-000000000000', F::INVOICE), 'handle']);
    }
}
