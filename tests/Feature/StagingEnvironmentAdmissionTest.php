<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\ComparePricingSettlement;
use App\Domain\Commerce\Finalization\FinalizationPolicy;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\Orders\OrderPolicy;
use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use App\Domain\Commerce\Payments\StripeWebhookException;
use App\Domain\Commerce\Payments\VerifyStripeWebhook;
use App\Domain\Commerce\PricingPolicy;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Commerce\PromotionAdministration;
use App\Domain\Commerce\PromotionPolicy;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\Readiness\StripeCapabilityPreflight;
use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\Preferences\LocalConsentRuntime;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Delivery\ActivationPolicy;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\TestAccessPolicy;
use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantPolicy;
use App\Domain\Grants\Free\TestFreeGrantIdentity;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\OrderInquiry;
use App\Domain\Media\ScanEngines;
use App\Domain\Memberships\MembershipPolicy;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicy as ProductionMembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Notifications\TransactionalNotificationPolicy;
use App\Domain\Services\Projects\ServiceProjectPolicy;
use App\Domain\SupportAttachments\FixtureAttachmentPolicy;
use App\Filament\Resources\TestPromotionResource;
use App\Models\User;
use App\Support\Environment\TestEnvironment;
use App\Support\SupportAttachmentUi;
use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\ProductionCheckoutProviderFixtures;
use Tests\Support\StripeWebhookFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Staging admits exactly the Stripe-test commerce chain and the synthetic test-customer and test-delivery
 * capabilities that local admits, and nothing that is development-only, live or production-only.
 * Every probe is configured validly, so the environment is the only variable that changes an outcome.
 */
class StagingEnvironmentAdmissionTest extends TestCase
{
    // Provider and delivery gates refuse any open transaction, so no test-wide transaction may wrap the probes.
    use FinalizationDatabaseMigrations;

    /** Test-commerce and test-capability gates, by the file that holds each gate. */
    public const ADMITTING_FILES = [
        'app/Domain/Commerce/Checkout/CheckoutPolicy.php',
        'app/Domain/Commerce/Orders/OrderPolicy.php',
        'app/Domain/Commerce/PricingPolicy.php',
        'app/Domain/Commerce/Inventory/InventoryPolicy.php',
        'app/Domain/Commerce/Payments/PaymentProcessingPolicy.php',
        'app/Domain/Commerce/Payments/StripeSdkCheckoutGateway.php',
        'app/Domain/Commerce/Payments/VerifyStripeWebhook.php',
        'app/Domain/Commerce/Finalization/FinalizationPolicy.php',
        'app/Domain/Commerce/ComparePricingSettlement.php',
        'app/Domain/Commerce/PromotionPolicy.php',
        'app/Domain/Commerce/PromotionUsage.php',
        'app/Domain/Commerce/PromotionAdministration.php',
        'app/Filament/Resources/TestPromotionResource.php',
        'app/Domain/Commerce/UnpaidRelease/UnpaidReleasePolicy.php',
        'app/Domain/Commerce/Operations/TestPaymentExceptionOperations.php',
        'app/Domain/Contracts/ContractIssuancePolicy.php',
        'app/Domain/Delivery/ActivationPolicy.php',
        'app/Domain/Delivery/TestAccessPolicy.php',
        'app/Domain/Delivery/PrepareTestDeliveryStream.php',
        'app/Domain/Customers/CustomerAccessPolicy.php',
        'app/Domain/Customers/Preferences/LocalConsentRuntime.php',
        'app/Domain/Notifications/TransactionalNotificationPolicy.php',
        'app/Domain/Inquiries/OrderInquiry.php',
        'app/Domain/Services/Projects/ServiceProjectPolicy.php',
        'app/Domain/Memberships/MembershipPolicy.php',
        'app/Domain/Grants/Free/FreeGrantPolicy.php',
        'app/Domain/Grants/Free/TestFreeGrantIdentity.php',
        'app/Domain/Grants/Free/FreeGrantDefinitions.php',
        'app/Http/Controllers/FreeGrantController.php',
    ];

    /** Development-only conveniences and production-lane rehearsals: these stay explicitly local/testing. */
    public const LOCAL_ONLY_FILES = [
        'app/Support/SupportAttachmentUi.php',
        'app/Domain/SupportAttachments/FixtureAttachmentPolicy.php',
        'app/Domain/Customers/ProductionIdentity/IdentityPolicy.php',
        'app/Domain/Customers/ProductionIdentity/Notifications/SmtpIdentitySettings.php',
        'app/Domain/Customers/ProductionIdentity/Notifications/LoopbackSmtp.php',
        'app/Domain/Commerce/ProductionCheckout/ExecutionContextV1.php',
        'app/Domain/Commerce/ProductionTaxCheckout/TaxExecutionContext.php',
        'app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutPolicy.php',
        'app/Domain/Commerce/Readiness/StripeCapabilityPreflight.php',
        'app/Domain/Memberships/Billing/BillingPolicy.php',
        'app/Domain/Memberships/Production/MembershipPolicy.php',
        'app/Domain/Grants/Member/MemberGrantPolicy.php',
        'app/Domain/Grants/ProductionFree/ProductionFreeGrantPolicy.php',
        // #56: paid grant rehearsal is local/testing only; its operative lane refuses staging explicitly.
        'app/Domain/Grants/Paid/PaidGrantPolicy.php',
        // Service project attachment sources stay local/testing only (synthetic attachment evidence).
        'app/Domain/Services/Projects/Attachments/ServiceProjectAttachmentSourceV1.php',
    ];

    private const LOCAL_TESTING_GATE = '/environment\(\[?\'local\', \'testing\'\]?\)|in_array\(\$[a-zA-Z\[\]\']+, \[\'local\', \'testing\'\], true\)/';

    protected function setUp(): void
    {
        parent::setUp();
        DeliveryFixtures::configure();
        InventoryFixtures::configure();
        config([
            'customer.test_accounts_enabled' => true, 'free-grants.test_enabled' => true,
            'customer-preferences.test_grants_enabled' => true, 'services-projects.test_enabled' => true,
            'memberships.test_mode_enabled' => true, 'unpaid-release.enabled' => true,
            'transactional-notifications.test_enabled' => true, 'transactional-notifications.transport' => 'private_capture',
            'transactional-notifications.policy_version' => TransactionalNotificationPolicy::VERSION,
            'support-attachments.fixture_enabled' => true, 'commerce.test_promotions' => '[]',
            // The real SDK gateway admits only an exact sk_test_ credential shape.
            'payments.stripe.secret_key' => 'sk_test_SYNTHETICONLY12345',
        ]);
    }

    private function in(string $environment, Closure $probe): mixed
    {
        $this->app->detectEnvironment(fn () => $environment);
        try {
            return $probe();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    private static function completes(Closure $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function invokePrivate(object $target, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }

    private function enrolledAdmin(): User
    {
        $admin = LicenseFixtures::admin();
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        $admin->saveAppAuthenticationSecret($provider->generateSecret());

        return $admin->fresh();
    }

    /** @return array<string, Closure(self): bool> true exactly when the gate admits */
    private static function gates(): array
    {
        return [
            'checkout policy' => fn () => app(CheckoutPolicy::class)->enabled(),
            'checkout account' => fn () => self::completes(fn () => app(CheckoutPolicy::class)->account()),
            'order policy' => fn () => app(OrderPolicy::class)->enabled(),
            'pricing policy' => fn () => self::completes(fn () => app(PricingPolicy::class)->current()),
            'inventory policy' => fn () => self::completes(fn () => InventoryPolicy::requireTestEnvironment()),
            'payment processing' => fn () => self::completes(fn () => app(PaymentProcessingPolicy::class)->account()),
            'checkout gateway reaches the provider transport' => function (): bool {
                $transport = new StagingAccountTransport;
                $previous = ApiRequestor::httpClient();
                try {
                    $account = (new StripeSdkCheckoutGateway($transport))->account();
                } catch (RuntimeException $error) {
                    self::assertSame('STRIPE_CHECKOUT_UNAVAILABLE', $error->getMessage());
                    self::assertSame([], $transport->requests);

                    return false;
                } finally {
                    ApiRequestor::setHttpClient($previous);
                }
                self::assertSame(1, count($transport->requests));

                return $account['id'] === config('payments.stripe.account_id');
            },
            'stripe test webhook' => function (): bool {
                $body = StripeWebhookFixtures::body();
                try {
                    return app(VerifyStripeWebhook::class)->handle($body, StripeWebhookFixtures::signature($body))->eventId === 'evt_FixtureEvent';
                } catch (StripeWebhookException $error) {
                    self::assertSame('STRIPE_WEBHOOK_UNAVAILABLE', $error->errorCode);

                    return false;
                }
            },
            'finalization' => fn () => self::completes(fn () => app(FinalizationPolicy::class)->account()),
            'contract issuance' => fn () => self::completes(fn () => app(ContractIssuancePolicy::class)->account()),
            'fulfillment activation' => fn () => self::completes(fn () => app(ActivationPolicy::class)->account()),
            'owner delivery access' => fn () => self::completes(fn () => app(TestAccessPolicy::class)->account()),
            'owner delivery stream' => function (): bool {
                try {
                    app(PrepareTestDeliveryStream::class)->handle([]);
                } catch (DeliveryException $error) {
                    // Admitted, the empty target is refused next; refused, the environment refuses first.
                    return $error->reason === 'target_unavailable';
                }
                self::fail('An empty delivery target was accepted.');
            },
            'test customer accounts' => fn () => app(CustomerAccessPolicy::class)->enabled(),
            'test customer consent grants' => fn () => app(LocalConsentRuntime::class)->grantsEnabled(),
            'test transactional notifications' => fn () => self::completes(fn () => app(TransactionalNotificationPolicy::class)->requireEnabled()),
            'test order inquiries' => function (): bool {
                try {
                    self::invokePrivate(app(OrderInquiry::class), 'readable');

                    return true;
                } catch (InquiryException $error) {
                    self::assertSame(404, $error->status);

                    return false;
                }
            },
            'test service projects' => fn () => app(ServiceProjectPolicy::class)->enabled(),
            'synthetic memberships' => fn () => app(MembershipPolicy::class)->enabled(),
            'test free grants' => fn () => app(FreeGrantPolicy::class)->enabled(),
            'test free grant definitions' => fn () => self::completes(fn () => app(FreeGrantPolicy::class)
                ->requireDefinition(['schema_version' => 'free-definition-v1', 'test_only' => true])),
            'test free grant identity' => fn () => self::completes(fn () => self::invokePrivate(new TestFreeGrantIdentity, 'enabled')),
            'test free grant authoring' => function (): bool {
                try {
                    app(FreeGrantDefinitions::class)->author([], LicenseFixtures::admin());
                } catch (FreeGrantException $error) {
                    // Admitted, the empty input is refused as invalid (422); refused, the environment answers 404.
                    return $error->status === 422;
                }
                self::fail('Empty free grant input was accepted.');
            },
            'pricing settlement comparison' => function (): bool {
                try {
                    self::invokePrivate(app(ComparePricingSettlement::class), 'compare', ['tax_policy' => ['scope' => 'test']], [], null);
                } catch (InvalidArgumentException $error) {
                    // Admitted, the empty observation fails the next schema check.
                    return $error->getMessage() !== 'test_scope_required';
                }
                self::fail('An empty settlement observation was accepted.');
            },
            'test promotions' => function (): bool {
                try {
                    app(PromotionPolicy::class)->current('SYNTHETIC10');
                } catch (QuoteException $error) {
                    // Admitted, an unconfigured code is a 409 conflict; refused, the environment answers 503.
                    return $error->status === 409;
                }
                self::fail('An unconfigured promotion was accepted.');
            },
            'test promotion usage' => fn () => self::completes(fn () => DB::transaction(
                fn () => self::invokePrivate(app(PromotionUsage::class), 'requireTransaction'))),
            'test promotion administration' => function (): bool {
                $admin = LicenseFixtures::admin();
                try {
                    return self::invokePrivate(app(PromotionAdministration::class), 'actor', $admin)->is($admin);
                } catch (AuthorizationException) {
                    return false;
                }
            },
            'test promotion resource' => function (): bool {
                auth()->login(LicenseFixtures::admin());
                try {
                    return TestPromotionResource::canAccess();
                } finally {
                    auth()->logout();
                }
            },
            'test unpaid release' => fn () => self::completes(fn () => app(UnpaidReleasePolicy::class)->account()),
        ];
    }

    public static function admittingEnvironments(): array
    {
        return ['local' => ['local'], 'testing' => ['testing'], 'staging' => ['staging']];
    }

    public static function refusingEnvironments(): array
    {
        return ['production' => ['production'], 'an unlisted environment' => ['preview'], 'a staging prefix' => ['staging-eu'], 'a cased variant' => ['Staging']];
    }

    #[DataProvider('admittingEnvironments')]
    public function test_every_test_commerce_and_test_capability_gate_admits_local_testing_and_staging(string $environment): void
    {
        $refused = array_keys(array_filter(self::gates(), fn (Closure $probe) => $this->in($environment, $probe) !== true));
        $this->assertSame([], $refused, 'Gates refusing '.$environment);
    }

    #[DataProvider('refusingEnvironments')]
    public function test_every_test_commerce_and_test_capability_gate_refuses_any_other_environment(string $environment): void
    {
        $admitted = array_keys(array_filter(self::gates(), fn (Closure $probe) => $this->in($environment, $probe) !== false));
        $this->assertSame([], $admitted, 'Gates admitting '.$environment);
    }

    public function test_test_payment_exception_operations_reach_their_record_lookup_only_in_admitted_environments(): void
    {
        $admin = $this->enrolledAdmin();
        $lookups = function (string $environment) use ($admin): int {
            return $this->in($environment, function () use ($admin): int {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    DB::transaction(fn () => self::invokePrivate(app(TestPaymentExceptionOperations::class), 'locked', (string) Str::uuid(), $admin));
                    $this->fail('A missing finalization was located.');
                } catch (ModelNotFoundException) {
                    // Both paths end here; only the admitted one reads the finalization table first.
                } finally {
                    DB::disableQueryLog();
                }

                return count(array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'order_finalizations')));
            });
        };
        $this->assertGreaterThan(0, $lookups('staging'));
        $this->assertGreaterThan(0, $lookups('local'));
        $this->assertSame(0, $lookups('production'));
        $this->assertSame(0, $lookups('preview'));
    }

    public function test_staging_never_admits_stripe_live_mode_or_a_live_or_restricted_key(): void
    {
        $live = [
            'live mode' => ['payments.stripe.mode' => 'live'],
            'absent mode' => ['payments.stripe.mode' => null],
        ];
        $stripeGates = ['checkout account', 'payment processing', 'checkout gateway reaches the provider transport', 'stripe test webhook',
            'finalization', 'contract issuance', 'fulfillment activation', 'owner delivery access', 'test unpaid release'];
        foreach ($live as $label => $settings) {
            config($settings);
            foreach ($stripeGates as $gate) {
                $this->assertFalse($this->in('staging', self::gates()[$gate]), $gate.' admitted '.$label.' in staging');
            }
            config(['payments.stripe.mode' => 'test']);
        }
        foreach (['sk_'.'live_SYNTHETICONLY12345', 'rk_'.'test_SYNTHETICONLY12345', 'rk_'.'live_SYNTHETICONLY12345', 'pk_'.'test_SYNTHETICONLY12345'] as $key) {
            config(['payments.stripe.secret_key' => $key]);
            $this->assertFalse($this->in('staging', self::gates()['checkout gateway reaches the provider transport']), 'gateway admitted '.substr($key, 0, 8));
        }
        config(['payments.stripe.secret_key' => 'sk_test_SYNTHETICONLY12345']);

        // A signed livemode event is refused even though the receiver is admitted.
        $event = StripeWebhookFixtures::event();
        $event['livemode'] = true;
        $body = StripeWebhookFixtures::body($event);
        $this->in('staging', function () use ($body): void {
            try {
                app(VerifyStripeWebhook::class)->handle($body, StripeWebhookFixtures::signature($body));
                $this->fail('A livemode event was verified in staging.');
            } catch (StripeWebhookException $error) {
                $this->assertSame('STRIPE_WEBHOOK_INVALID', $error->errorCode);
            }
        });
    }

    public function test_staging_never_admits_production_identity_or_production_live_funds(): void
    {
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::PRODUCTION]);
        $this->assertTrue($this->in('production', fn () => app(IdentityPolicy::class)->enabled()));
        $this->assertFalse($this->in('staging', fn () => app(IdentityPolicy::class)->enabled()));
        config(['production-customer-identity.provenance' => IdentityPolicy::REHEARSAL]);
        $this->assertTrue($this->in('local', fn () => app(IdentityPolicy::class)->enabled()));
        $this->assertFalse($this->in('staging', fn () => app(IdentityPolicy::class)->enabled()), 'identity rehearsal stays local/testing');

        ProductionCheckoutProviderFixtures::configure();
        config(['production_checkout.funds_mode' => 'live', 'production_checkout.secret_key' => 'sk_'.'live_'.'SYNTHETIC']);
        $machine = ProductionCheckoutProviderFixtures::machine();
        $this->assertSame('live', $this->in('production', fn () => ExecutionContextV1::current($machine)->fundsMode));
        $this->assertFalse($this->in('staging', fn () => self::completes(fn () => ExecutionContextV1::current($machine))));
        $this->in('staging', function (): void {
            $report = app(StripeCapabilityPreflight::class)->collect();
            $this->assertSame('blocked', collect($report['checks'])->firstWhere('id', 'funds_mode_environment')['status']);
        });
        config(['production_checkout.funds_mode' => 'test', 'production_checkout.secret_key' => 'sk_'.'test_'.'SYNTHETIC']);
        $this->assertFalse($this->in('staging', fn () => self::completes(fn () => ExecutionContextV1::current($machine))), 'production-lane test funds stay local/testing');
        config(['production-tax-checkout.enabled' => true]);
        $this->assertTrue($this->in('local', fn () => TaxCheckoutPolicy::enabled()));
        $this->assertFalse($this->in('staging', fn () => TaxCheckoutPolicy::enabled()), 'production tax checkout rehearsal stays local/testing');
    }

    public function test_staging_never_enables_the_operative_paid_grant_lane(): void
    {
        // #56's paid family: rehearsal is local/testing only; the operative lane is production-only, never staging.
        config(['paid-grants.rehearsal_enabled' => true, 'paid-grants.operative_enabled' => true]);
        $enabled = fn (): bool => (new PaidGrantPolicy)->enabled();
        $this->assertTrue($this->in('local', $enabled));
        $this->assertTrue($this->in('production', $enabled));
        $this->assertFalse($this->in('staging', $enabled));
        $policy = ['schema_version' => 1, 'version' => 'synthetic-operative', 'purpose' => 'paid-original-delivery',
            'provenance' => 'verified_production', 'max_downloads' => 3, 'authorization_seconds' => 60];
        config(['paid-grants.delivery_policy' => $policy, 'production-customer-identity.enabled' => true,
            'production-customer-identity.provenance' => IdentityPolicy::PRODUCTION]);
        $proof = function (string $environment) use ($policy): string {
            try {
                PaidGrantPolicy::provePure($policy, app('config'), $environment);

                return 'admitted';
            } catch (PaidGrantException $error) {
                return (string) $error->status;
            }
        };
        $this->assertSame('admitted', $proof('production'));
        $this->assertSame('403', $proof('staging'));
    }

    public function test_staging_never_admits_verified_production_membership_or_member_grant_provenance(): void
    {
        config(['member-grants.enabled' => true, 'member-grants.provenance' => IdentityPolicy::PRODUCTION,
            'member-grants.approved_definition_hash' => str_repeat('a', 64), 'member-grants.approved_profile_hash' => str_repeat('b', 64),
            'member-grants.approved_original_terms_hash' => str_repeat('c', 64)]);
        foreach ([MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class, MembershipReservationAuthority::class] as $capability) {
            $this->app->instance($capability, Mockery::mock($capability));
        }
        $memberGrant = function (): string {
            try {
                (new MemberGrantPolicy)->current();

                return 'admitted';
            } catch (MemberGrantException $error) {
                return $error->reason;
            }
        };
        $this->assertSame('admitted', $this->in('production', $memberGrant));
        $this->assertSame('provenance', $this->in('staging', $memberGrant));

        config(['production-memberships.enabled' => true, 'production-memberships.version' => ProductionMembershipPolicy::VERSION,
            'production-memberships.provenance' => IdentityPolicy::PRODUCTION, 'production-memberships.approved_policy_hash' => str_repeat('a', 64)]);
        foreach ([MembershipPaidInvoiceAuthority::class, MembershipPolicyFactsAuthority::class, MembershipEligibleLicenseAuthority::class, MemberGrantAuthority::class] as $capability) {
            $this->app->instance($capability, Mockery::mock($capability));
        }
        $membership = function (): string {
            try {
                (new ProductionMembershipPolicy)->current();

                return 'admitted';
            } catch (MembershipException $error) {
                return $error->reason;
            }
        };
        // Verified production needs the native driver; only the configured driver name is read, never a connection.
        $default = config('database.default');
        config(['database.connections.staging_probe_native' => ['driver' => 'mysql'], 'database.default' => 'staging_probe_native']);
        try {
            $this->assertSame('admitted', $this->in('production', $membership));
            $this->assertSame('provenance', $this->in('staging', $membership));
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_development_only_and_testing_only_conveniences_still_refuse_staging(): void
    {
        $this->assertTrue($this->in('local', fn () => SupportAttachmentUi::enabled()));
        $this->assertFalse($this->in('staging', fn () => SupportAttachmentUi::enabled()), 'support attachment fixtures');
        $this->assertFalse($this->in('staging', fn () => ScanEngines::accepted('test-only')), 'synthetic scan engine');
        $this->assertTrue($this->in('staging', fn () => ScanEngines::accepted('clamav')));
        $this->assertFalse($this->in('staging', fn () => self::completes(fn () => app(FixtureAttachmentPolicy::class)
            ->assertCurrent(['family' => 'test_service_project_v1']))), 'support attachment fixture policy');
    }

    public function test_the_helper_names_exactly_three_test_commerce_environments_and_two_mfa_environments(): void
    {
        $this->assertSame(['local', 'testing', 'staging'], TestEnvironment::TEST_COMMERCE);
        foreach (['local' => true, 'testing' => true, 'staging' => true, 'production' => false, 'preview' => false, 'staging-eu' => false, 'Staging' => false, '' => false] as $environment => $admitted) {
            $this->assertSame($admitted, $this->in($environment, fn () => TestEnvironment::admitsTestCommerce()), 'test commerce: '.$environment);
            $this->assertSame($environment === 'staging', $this->in($environment, fn () => TestEnvironment::refusesProductionOnly()), 'production-only: '.$environment);
            $this->assertSame(in_array($environment, ['production', 'staging'], true), $this->in($environment, fn () => TestEnvironment::requiresStaffMfa()), 'mfa: '.$environment);
        }
        $this->assertTrue(TestEnvironment::isStaging('staging'));
        foreach ([null, 'production', 'Staging', 'staging ', 1] as $other) {
            $this->assertFalse(TestEnvironment::isStaging($other));
        }
    }

    public function test_source_census_routes_every_admitting_gate_through_the_helper_and_leaves_local_only_gates_explicit(): void
    {
        foreach (self::ADMITTING_FILES as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertStringContainsString('TestEnvironment::admitsTestCommerce()', $source, $file);
            $this->assertDoesNotMatchRegularExpression(self::LOCAL_TESTING_GATE, $source, $file.' keeps a local/testing-only gate');
        }
        foreach (self::LOCAL_ONLY_FILES as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertMatchesRegularExpression(self::LOCAL_TESTING_GATE, $source, $file);
            $this->assertStringNotContainsString('admitsTestCommerce', $source, $file);
        }
        // Exhaustive: every local/testing gate anywhere in app/ is a classified local-only file, so a gate added later
        // (as #56's paid family did) must be decided here instead of silently refusing or admitting staging.
        $gated = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $path) {
            if ($path->getExtension() === 'php' && preg_match(self::LOCAL_TESTING_GATE, file_get_contents($path->getPathname()))) {
                $gated[] = Str::after($path->getPathname(), base_path().'/');
            }
        }
        sort($gated);
        $classified = self::LOCAL_ONLY_FILES;
        sort($classified);
        $this->assertSame($classified, $gated);
        // No gate names staging itself: the helper is the single place that admits it.
        $named = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $path) {
            if ($path->getExtension() === 'php' && preg_match('/[\'"]staging[\'"]/', file_get_contents($path->getPathname()))) {
                $named[] = Str::after($path->getPathname(), base_path().'/');
            }
        }
        $this->assertSame(['app/Support/Environment/TestEnvironment.php'], $named);
    }
}

/** Answers one account read; records every request so a refused gateway can prove it sent nothing. */
final class StagingAccountTransport implements ClientInterface
{
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [$method, $absUrl];

        return [json_encode(['id' => config('payments.stripe.account_id'), 'object' => 'account'], JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_synthetic']];
    }
}
