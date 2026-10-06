<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\Finalization\FinalizationException;
use App\Domain\Commerce\Finalization\FinalizationPolicy;
use App\Domain\Commerce\Inventory\ExclusiveSelectionPolicy;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Orders\OrderPolicy;
use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\PricingPolicy;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\Readiness\ProductionCommerceReadiness;
use App\Domain\Commerce\RefundResolution\RefundResolutionPolicy;
use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\CustomerPurchaseClaimPolicy;
use App\Domain\Delivery\ActivationPolicy;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\TestAccessPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionCommerceReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        config([
            'app.debug' => false, 'app.url' => 'https://shop.vasey.invalid',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'app.cipher' => 'AES-256-CBC',
            'database.default' => 'mysql', 'database.connections.mysql.driver' => 'mysql',
            'session.driver' => 'database', 'session.encrypt' => true, 'session.secure' => true,
            'session.http_only' => true, 'session.same_site' => 'lax',
            'filesystems.disks.local' => ['driver' => 'local', 'root' => '/private/synthetic-store', 'serve' => false],
            'queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'queue.connections.database.retry_after' => 1200,
            'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'mail.vasey.invalid', 'port' => 587],
            'mail.from.address' => 'receipts@vasey.invalid',
            'payments.stripe.webhook_enabled' => false, 'payments.stripe.checkout_enabled' => false,
            'payments.stripe.processing_enabled' => false, 'payments.stripe.finalization_enabled' => false,
            'contracts.test_issuance_enabled' => false, 'delivery.test_activation_enabled' => false, 'delivery.test_access_enabled' => false,
            'customer.test_accounts_enabled' => false, 'customer.test_identity_enabled' => false, 'customer.test_purchase_claims_enabled' => false,
            'inquiries.test_order_inquiries_enabled' => false,
            'unpaid-release.enabled' => false, 'refund-resolution.enabled' => false,
            'commerce.test_pricing_policy' => null, 'commerce.test_promotions' => null, 'commerce.test_inventory_policy' => null,
            'commerce.test_exclusive_selection_policy' => null, 'commerce.test_order_policy' => null, 'commerce.test_checkout_policy' => null,
            'payments.stripe.finalization_policy' => null, 'contracts.test_issuance_policy' => null,
            'delivery.test_activation_policy' => null, 'delivery.test_access_policy' => null, 'customer.identity_transport' => null,
            'unpaid-release.policy' => null, 'refund-resolution.policy' => null,
        ]);
        Http::preventStrayRequests();
    }

    private function statuses(): array
    {
        return array_column(app(ProductionCommerceReadiness::class)->collect()['checks'], 'status', 'id');
    }

    public function test_complete_looking_configuration_cannot_manufacture_production_or_acceptance_readiness(): void
    {
        $report = app(ProductionCommerceReadiness::class)->collect();
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('production-track-commerce-readiness-v1', $report['contract_version']);
        $this->assertSame('production_track_commerce_preparation', $report['scope']);
        $this->assertTrue($report['configuration_complete']);
        $this->assertFalse($report['production_commerce_ready']);
        $this->assertFalse($report['deployment_authorized']);
        $this->assertSame(['blocked' => 10, 'configured' => 11, 'unverified' => 12], $report['counts']);
        $this->assertCount(33, $report['checks']);
        $this->assertCount(33, array_unique(array_column($report['checks'], 'id')));
        foreach ($report['checks'] as $check) {
            $this->assertSame(['id', 'category', 'status', 'message', 'sources'], array_keys($check));
            $this->assertSame(match ($check['category']) {
                'implementation' => 'blocked', 'configuration' => 'configured', 'acceptance' => 'unverified',
            }, $check['status']);
            foreach ($check['sources'] as $source) {
                $this->assertFileExists(base_path($source));
            }
        }
    }

    public function test_json_command_is_read_only_and_redacts_every_runtime_value_even_when_configuration_is_unsafe(): void
    {
        $sentinel = 'PRIVATE-CREDENTIAL-PATH-EMAIL-DO-NOT-EXPOSE';
        config([
            'app.key' => $sentinel, 'app.url' => 'https://'.$sentinel.'@private.invalid',
            'payments.stripe.account_id' => $sentinel, 'payments.stripe.mode' => 'live',
            'payments.stripe.secret_key' => $sentinel, 'payments.stripe.webhook_secret' => $sentinel,
            'database.connections.mysql.password' => $sentinel, 'database.connections.mysql.host' => $sentinel,
            'filesystems.disks.local.root' => '/'.$sentinel, 'mail.mailers.smtp.password' => $sentinel,
            'mail.mailers.smtp.url' => 'smtp://'.$sentinel, 'mail.from.address' => $sentinel,
            'commerce.test_order_policy' => $sentinel, 'customer.identity_transport' => $sentinel,
            'payments.stripe.checkout_enabled' => true,
        ]);
        $before = config()->all();
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('getConnections')->never();
        Storage::shouldReceive('disk')->never();
        Mail::shouldReceive('mailer')->never();
        Queue::shouldReceive('connection')->never();
        $this->assertSame(1, Artisan::call('vasey:commerce-readiness', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringNotContainsString($sentinel, $output);
        $this->assertStringNotContainsString(hash('sha256', $sentinel), $output);
        $this->assertSame($before, config()->all());
        $this->assertFalse(json_decode($output, true, 16, JSON_THROW_ON_ERROR)['configuration_complete']);
        Http::assertNothingSent();
    }

    public function test_human_command_exits_nonzero_and_distinguishes_configured_from_unverified(): void
    {
        $this->assertSame(1, Artisan::call('vasey:commerce-readiness'));
        $output = Artisan::output();
        $this->assertStringContainsString('configured', $output);
        $this->assertStringContainsString('unverified', $output);
        $this->assertStringContainsString('Production commerce remains blocked.', $output);
        $this->assertStringNotContainsString(config('app.key'), $output);
        $this->assertStringNotContainsString(config('app.url'), $output);
        $this->assertStringNotContainsString(config('filesystems.disks.local.root'), $output);
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_unsafe_or_malformed_configuration_is_blocked_without_reflecting_values(string $key, mixed $value, string $check): void
    {
        config([$key => $value]);
        $report = app(ProductionCommerceReadiness::class)->collect();
        $this->assertSame('blocked', array_column($report['checks'], 'status', 'id')[$check]);
        $this->assertFalse($report['configuration_complete']);
        $this->assertFalse($report['production_commerce_ready']);
        $this->assertJson(json_encode($report, JSON_THROW_ON_ERROR));
    }

    public static function unsafeConfiguration(): array
    {
        return [
            'debug enabled' => ['app.debug', true, 'debug_disabled'],
            'debug string is not false' => ['app.debug', 'false', 'debug_disabled'],
            'insecure URL' => ['app.url', 'http://shop.vasey.invalid', 'https_origin'],
            'credential URL' => ['app.url', 'https://secret@shop.vasey.invalid', 'https_origin'],
            'URL path' => ['app.url', 'https://shop.vasey.invalid/private', 'https_origin'],
            'URL query' => ['app.url', 'https://shop.vasey.invalid?secret=x', 'https_origin'],
            'URL fragment' => ['app.url', 'https://shop.vasey.invalid#secret', 'https_origin'],
            'URL newline' => ['app.url', "https://shop.vasey.invalid\n", 'https_origin'],
            'URL wrong type' => ['app.url', ['https'], 'https_origin'],
            'invalid base64 key' => ['app.key', 'base64:not-a-key', 'application_key'],
            'key wrong type' => ['app.key', ['secret'], 'application_key'],
            'cipher wrong type' => ['app.cipher', ['AES-256-CBC'], 'application_key'],
            'SQLite target' => ['database.default', 'sqlite', 'mysql_driver_selected'],
            'wrong MySQL driver' => ['database.connections.mysql.driver', 'sqlite', 'mysql_driver_selected'],
            'session not encrypted' => ['session.encrypt', false, 'session_controls'],
            'session insecure' => ['session.secure', false, 'session_controls'],
            'session visible to scripts' => ['session.http_only', false, 'session_controls'],
            'cross-site session' => ['session.same_site', 'none', 'session_controls'],
            'test flag enabled' => ['customer.test_identity_enabled', true, 'test_features_disabled'],
            'unpaid release enabled' => ['unpaid-release.enabled', true, 'test_features_disabled'],
            'refund resolution enabled' => ['refund-resolution.enabled', true, 'test_features_disabled'],
            'false string is not disabled' => ['payments.stripe.checkout_enabled', 'false', 'test_features_disabled'],
            'missing flag is not disabled' => ['delivery.test_access_enabled', null, 'test_features_disabled'],
            'test policy retained' => ['commerce.test_pricing_policy', '{}', 'test_policies_absent'],
            'private capture retained' => ['customer.identity_transport', 'private_capture', 'test_policies_absent'],
            'unpaid release test policy' => ['unpaid-release.policy', '{}', 'test_policies_absent'],
            'refund resolution test policy' => ['refund-resolution.policy', '{}', 'test_policies_absent'],
            'public disk' => ['filesystems.disks.local.visibility', 'public', 'private_local_adapter_selected'],
            'served disk' => ['filesystems.disks.local.serve', true, 'private_local_adapter_selected'],
            'different disk adapter' => ['filesystems.disks.local.driver', 's3', 'private_local_adapter_selected'],
            'noncanonical root' => ['filesystems.disks.local.root', '/private/../public', 'private_local_adapter_selected'],
            'relative root' => ['filesystems.disks.local.root', 'private/store', 'private_local_adapter_selected'],
            'root wrong type' => ['filesystems.disks.local.root', ['private'], 'private_local_adapter_selected'],
            'synchronous queue' => ['queue.default', 'sync', 'async_database_queue_selected'],
            'retry equals claim lease' => ['queue.connections.database.retry_after', 960, 'async_database_queue_selected'],
            'retry wrong type' => ['queue.connections.database.retry_after', '1200', 'async_database_queue_selected'],
            'test log mailer' => ['mail.default', 'log', 'transactional_mail_selected'],
            'mailer wrong type' => ['mail.default', ['smtp'], 'transactional_mail_selected'],
            'mailer config wrong type' => ['mail.mailers.smtp', 'smtp', 'transactional_mail_selected'],
            'mail URL override' => ['mail.mailers.smtp.url', 'smtp://secret@private.invalid', 'transactional_mail_selected'],
            'mail port zero' => ['mail.mailers.smtp.port', 0, 'transactional_mail_selected'],
            'mail port overflow' => ['mail.mailers.smtp.port', '999999999999999999999999', 'transactional_mail_selected'],
            'mail port wrong type' => ['mail.mailers.smtp.port', [], 'transactional_mail_selected'],
            'mail header input' => ['mail.from.address', "sender@vasey.invalid\nBcc: secret", 'transactional_mail_selected'],
            'placeholder sender' => ['mail.from.address', 'hello@example.com', 'transactional_mail_selected'],
        ];
    }

    #[DataProvider('productionPolicyBarriers')]
    public function test_actual_existing_policy_cannot_be_enabled_for_production(string $class, string $method, ?string $exception): void
    {
        config([
            'payments.stripe.mode' => 'test', 'payments.stripe.account_id' => 'acct_Synthetic',
            'payments.stripe.checkout_enabled' => true, 'payments.stripe.processing_enabled' => true,
            'payments.stripe.finalization_enabled' => true, 'contracts.test_issuance_enabled' => true,
            'delivery.test_activation_enabled' => true, 'delivery.test_access_enabled' => true,
            'customer.test_accounts_enabled' => true, 'customer.test_identity_enabled' => true, 'customer.test_purchase_claims_enabled' => true,
            'customer.identity_transport' => 'private_capture',
            'commerce.test_pricing_policy' => json_encode([
                'schema_version' => 1, 'scope' => 'test', 'key' => 'synthetic', 'version' => 1, 'currency' => 'USD',
                'provider' => 'stripe', 'account' => 'acct_Synthetic', 'effective_from' => '2000-01-01T00:00:00Z', 'effective_until' => '2099-01-01T00:00:00Z',
                'tax' => ['mode' => 'fixed_test', 'behavior' => 'exclusive', 'rounding' => 'line_half_up', 'rate_bps' => 0],
            ], JSON_THROW_ON_ERROR),
            'commerce.test_inventory_policy' => json_encode([
                'schema_version' => 1, 'purpose' => 'test_inventory', 'pending' => 'retain_until_verified_resolution', 'ttl_seconds' => 900,
            ], JSON_THROW_ON_ERROR),
            'commerce.test_exclusive_selection_policy' => json_encode([
                'schema_version' => 1, 'purpose' => 'test_exclusive_selection', 'non_exclusive_cutoff' => 'block_while_reserved',
                'existing_pending' => 'retain_until_verified_resolution', 'discounts' => 'explicit_revision_only',
            ], JSON_THROW_ON_ERROR),
            'commerce.test_order_policy' => json_encode([
                'schema_version' => 1, 'purpose' => 'test_order_preparation', 'version' => 'synthetic-v1',
                'seller' => ['legal_name' => 'Synthetic Test Seller'], 'assent' => ['version' => 'synthetic-v1', 'text' => 'Nonbinding synthetic assent.'],
                'buyer_identity' => 'unverified_guest',
            ], JSON_THROW_ON_ERROR),
            'commerce.test_checkout_policy' => json_encode([
                'schema_version' => 1, 'purpose' => 'test_hosted_checkout', 'version' => 'synthetic-v1', 'provider_lifetime_seconds' => 3600,
                'retry_seconds' => 900, 'pending_resources' => 'retain_until_authoritative_finalization', 'tax' => 'fixed_test_zero',
                'return_origin' => 'https://shop.vasey.invalid',
            ], JSON_THROW_ON_ERROR),
        ]);
        if ($exception !== null) {
            try {
                app($class)->{$method}();
                $this->fail('The actual existing policy accepted production.');
            } catch (\Throwable $failure) {
                $this->assertInstanceOf($exception, $failure);
            }
        } else {
            $this->assertFalse(app($class)->{$method}());
        }
        // The same valid synthetic configuration succeeds in its intended environment.
        // Invalid fixtures alone cannot prove an environment barrier.
        $this->app->instance('env', 'testing');
        $this->assertNotEmpty(app($class)->{$method}());
    }

    public static function productionPolicyBarriers(): array
    {
        return [
            'pricing' => [PricingPolicy::class, 'current', QuoteException::class],
            'inventory' => [InventoryPolicy::class, 'current', QuoteException::class],
            'exclusive selection' => [ExclusiveSelectionPolicy::class, 'current', QuoteException::class],
            'order' => [OrderPolicy::class, 'current', QuoteException::class],
            'checkout' => [CheckoutPolicy::class, 'current', QuoteException::class],
            'payment processing' => [PaymentProcessingPolicy::class, 'account', PaymentVerificationException::class],
            'finalization' => [FinalizationPolicy::class, 'account', FinalizationException::class],
            'original issuance' => [ContractIssuancePolicy::class, 'account', ContractIssuanceException::class],
            'fulfillment activation' => [ActivationPolicy::class, 'account', DeliveryException::class],
            'delivery access' => [TestAccessPolicy::class, 'account', DeliveryException::class],
            'customer accounts' => [CustomerAccessPolicy::class, 'enabled', null],
            'customer identity' => [CustomerIdentityPolicy::class, 'enabled', null],
            'guest claims' => [CustomerPurchaseClaimPolicy::class, 'enabled', null],
            'unpaid release' => [UnpaidReleasePolicy::class, 'account', \RuntimeException::class],
            'refund resolution' => [RefundResolutionPolicy::class, 'account', \RuntimeException::class],
        ];
    }

    #[DataProvider('developmentEnvironments')]
    public function test_local_testing_and_staging_do_not_report_production_configuration_complete(string $environment): void
    {
        $this->app->instance('env', $environment);
        $this->assertSame('blocked', $this->statuses()['production_environment']);
        $this->assertFalse(app(ProductionCommerceReadiness::class)->collect()['configuration_complete']);
    }

    public static function developmentEnvironments(): array
    {
        return [['local'], ['testing'], ['staging']];
    }
}
