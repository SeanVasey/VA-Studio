<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\StripeSdkBillingGateway;
use Composer\InstalledVersions;
use Stripe\ApiRequestor;
use Stripe\Util\ApiVersion;
use Tests\Support\BillingHttpFixture;
use Tests\Support\BillingStripeFixtures as F;
use Tests\TestCase;

/** No network: the transport is a loopback fixture. */
class BillingProviderPinTest extends TestCase
{
    public function test_installed_sdk_version_reference_api_version_and_model_files_match_the_pins(): void
    {
        BillingProviderPin::assertInstalled();
        $this->assertSame(BillingProviderPin::SDK_VERSION, InstalledVersions::getPrettyVersion(BillingProviderPin::SDK_PACKAGE));
        $this->assertSame(BillingProviderPin::SDK_REFERENCE, InstalledVersions::getReference(BillingProviderPin::SDK_PACKAGE));
        $this->assertSame(BillingProviderPin::API_VERSION, ApiVersion::CURRENT);
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true, 64, JSON_THROW_ON_ERROR);
        $package = array_values(array_filter($lock['packages'], fn (array $p) => $p['name'] === BillingProviderPin::SDK_PACKAGE))[0];
        $this->assertSame([BillingProviderPin::SDK_VERSION, BillingProviderPin::SDK_REFERENCE], [$package['version'], $package['source']['reference']]);
        $lib = InstalledVersions::getInstallPath(BillingProviderPin::SDK_PACKAGE).'/lib/';
        foreach (BillingProviderPin::MODEL_SHA256 as $file => $sha256) {
            $this->assertSame($sha256, hash_file('sha256', $lib.$file), $file);
        }
    }

    public function test_every_request_carries_the_pinned_stripe_version_and_no_sdk_retries(): void
    {
        F::configure(['provider_io_enabled' => true]);
        $previous = ApiRequestor::httpClient();
        $transport = new BillingHttpFixture(['/v1/account' => F::graph()['account']]);
        $account = (new StripeSdkBillingGateway($transport))->account();
        $this->assertSame(['id' => F::ACCOUNT, 'object' => 'account'], $account);
        $this->assertCount(1, $transport->calls);
        $call = $transport->calls[0];
        $this->assertSame(['get', 'https://api.stripe.com/v1/account'], [strtolower($call['method']), $call['url']]);
        $this->assertContains('Stripe-Version: '.BillingProviderPin::API_VERSION, $call['headers']);
        $this->assertSame(0, $call['max_network_retries']);
        $this->assertSame($previous, ApiRequestor::httpClient(), 'The SDK transport slot must be restored.');
    }
}
