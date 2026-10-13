<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingSettlement;
use App\Domain\Memberships\Billing\BillingSubscriptionApprovals;
use App\Domain\Memberships\Billing\BillingVerdict;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Stripe\WebhookSignature;

/** Real retained writers with explicit synthetic provider facts; no runtime capability registration. */
trait MembershipAwardFixtures
{
    use MembershipPolicyFixtures;
    use ProductionIdentityFixture;

    protected function awardSetup(): void
    {
        $this->policySetup();
        CarbonImmutable::setTestNow('2026-10-13 12:00:00 UTC');
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
        BillingStripeFixtures::configure();
    }

    protected function awardFixture(array $terms = [], bool $observe = true): array
    {
        $staff = $this->policyStaff();
        $plan = $this->createPolicy($staff, ['billing_price_minor' => BillingStripeFixtures::AMOUNT,
            'billing_currency' => BillingStripeFixtures::CURRENCY, ...$terms]);
        config(['production-membership-billing.approved_subscription_policy_hash' => $plan['policy_hash'],
            'production-memberships.enabled' => true, 'production-memberships.approved_policy_hash' => $plan['policy_hash']]);
        $buyer = $this->enrollThroughLocalSmtp();
        $approved = DB::transaction(fn () => (new BillingSubscriptionApprovals)->approve($plan['id'], BillingStripeFixtures::CUSTOMER,
            BillingStripeFixtures::SUBSCRIPTION, BillingStripeFixtures::PRICE, $buyer['principal'], $buyer['user'], $staff, new MembershipRows));
        $ledger = new BillingLedger;
        $approval = $ledger->binding($approved['id'], (new BillingPolicy)->current());
        $invoice = $ledger->invoice($approval, BillingStripeFixtures::INVOICE);
        $fixture = compact('staff', 'plan', 'buyer', 'approval', 'invoice');
        if ($observe) {
            $fixture['observation'] = $this->awardObservation($fixture);
        }

        return $fixture;
    }

    protected function awardObservation(array $fixture, ?BillingVerdict $verdict = null, array $graph = []): array
    {
        $ledger = new BillingLedger;
        $start = $ledger->startRetrieval();
        $started = CarbonImmutable::now('UTC');
        $verdict ??= BillingSettlement::evaluate(BillingStripeFixtures::snapshots(BillingStripeFixtures::graph($graph), $started->timestamp), $fixture['approval']['expectation']);

        return $ledger->append($fixture['invoice'], $verdict, $started->timestamp, IdentityPolicy::REHEARSAL,
            $start, $ledger->endRetrieval($start), $started);
    }

    protected function awardTestCapabilities(): void
    {
        $this->app->instance(MembershipPaidInvoiceAuthority::class, new SealedMembershipPaidInvoice);
        $this->app->instance(MembershipPolicyFactsAuthority::class, new SealedMembershipPolicyFacts);
        // These deliberately unusable test-only capabilities permit exercising the first operation.
        // They are never registered by application boot and provide no license/grant readiness.
        $license = Mockery::mock(MembershipEligibleLicenseAuthority::class);
        $license->shouldNotReceive('lock');
        $license->shouldNotReceive('proveCurrent');
        $grant = Mockery::mock(MemberGrantAuthority::class);
        $grant->shouldNotReceive('prepare');
        $grant->shouldNotReceive('proveReadyCurrent');
        $this->app->instance(MembershipEligibleLicenseAuthority::class, $license);
        $this->app->instance(MemberGrantAuthority::class, $grant);
    }

    protected function awardHint(string $eventId = 'evt_SYNTHETICAWARD'): array
    {
        $payload = json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'invoice.updated', 'livemode' => false,
            'account' => null, 'api_version' => BillingProviderPin::API_VERSION, 'created' => CarbonImmutable::now('UTC')->timestamp,
            'data' => ['object' => ['id' => BillingStripeFixtures::INVOICE, 'object' => 'invoice',
                'parent' => ['subscription_details' => ['subscription' => BillingStripeFixtures::SUBSCRIPTION]]]]], JSON_THROW_ON_ERROR);

        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, 'whsec_SYNTHETICREHEARSAL'));
    }
}
