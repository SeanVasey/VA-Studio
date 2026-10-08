<?php

namespace App\Domain\Memberships\Billing;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ReflectionFunction;
use ReflectionProperty;
use SensitiveParameter;

/**
 * Default-off gate for provider evidence. It reads raw configuration without invoking getters.
 * Only Stripe test mode is admitted, and only as synthetic rehearsal in local/testing: live mode
 * and production environments refuse until Sean separately authorizes them. A flag, key or event
 * never confers paid-invoice authority.
 */
final class BillingPolicy
{
    public const VERSION = 'production-membership-billing-v1';

    private Container $container;

    private Repository $repository;

    public function __construct()
    {
        $this->container = Container::getInstance();
        $this->repository = $this->container->make('config');
        BillingException::require(get_class($this->repository) === Repository::class, 'changed_policy');
    }

    /** @return array{enabled: bool, provider_io_enabled: bool, account_ref: string, mode: string, approved_subscription_policy_hash: string, environment: ?string, driver: string, provenance: string} */
    public function current(): array
    {
        $configuration = $this->configuration();
        BillingException::require($configuration['enabled'] === true, 'disabled');
        BillingException::require($configuration['mode'] !== 'live', 'live_not_authorized');
        BillingException::require($configuration['mode'] === 'test' && $configuration['provenance'] === IdentityPolicy::REHEARSAL
            && in_array($configuration['environment'], ['local', 'testing'], true), 'provenance');
        BillingException::require(is_string($configuration['account_ref'])
            && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $configuration['account_ref']) === 1, 'account_absent');
        BillingException::require(is_string($configuration['approved_subscription_policy_hash'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $configuration['approved_subscription_policy_hash']) === 1, 'policy_facts_absent');

        return $configuration;
    }

    /** Real provider I/O needs the separate switch and a test-mode own-account key. */
    public function providerIo(): array
    {
        $configuration = $this->current();
        BillingException::require($configuration['provider_io_enabled'] === true, 'provider_io_disabled');
        $this->secret();

        return $configuration;
    }

    public function secret(): string
    {
        $secret = $this->raw()['secret_key'] ?? null;
        BillingException::require(is_string($secret) && preg_match('/\Ask_test_[A-Za-z0-9]{8,240}\z/D', $secret) === 1, 'provider_credential');

        return $secret;
    }

    public function webhookSecret(): string
    {
        $secret = $this->raw()['webhook_secret'] ?? null;
        BillingException::require(is_string($secret) && preg_match('/\Awhsec_[A-Za-z0-9]{8,240}\z/D', $secret) === 1, 'webhook_credential');

        return $secret;
    }

    public function proveConfiguration(#[SensitiveParameter] array $expected): void
    {
        BillingException::require($this->configuration() === $expected, 'changed_policy');
    }

    private function configuration(): array
    {
        $policy = $this->raw();
        $environment = $this->environment();
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->repository);
        $database = is_array($items) ? ($items['database'] ?? null) : null;
        BillingException::require(is_array($database) && is_string($database['default'] ?? null)
            && is_array($database['connections'] ?? null) && is_array($database['connections'][$database['default']] ?? null), 'changed_policy');
        $driver = $database['connections'][$database['default']]['driver'] ?? null;
        foreach (['account_ref', 'mode', 'approved_subscription_policy_hash'] as $key) {
            BillingException::require(($policy[$key] ?? null) === null || is_string($policy[$key]), 'changed_policy');
        }
        BillingException::require(is_bool($policy['enabled'] ?? null) && is_bool($policy['provider_io_enabled'] ?? null)
            && ($environment === null || is_string($environment)) && is_string($driver), 'changed_policy');
        $mode = $policy['mode'] ?? null;

        return ['enabled' => $policy['enabled'], 'provider_io_enabled' => $policy['provider_io_enabled'], 'account_ref' => $policy['account_ref'] ?? null,
            'mode' => $mode, 'approved_subscription_policy_hash' => $policy['approved_subscription_policy_hash'] ?? null,
            'environment' => $environment, 'driver' => $driver,
            'provenance' => $mode === 'test' ? IdentityPolicy::REHEARSAL : ($mode === 'live' ? IdentityPolicy::PRODUCTION : 'none')];
    }

    /**
     * Laravel binds `env` through Container::offsetSet as `fn () => $value`. Read that captured value
     * by reflection instead of resolving it, and refuse any other binding: resolving would run a callback.
     */
    private function environment(): ?string
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        BillingException::require(is_array($instances) && ! array_key_exists('env', $instances), 'changed_policy');
        $bindings = (new ReflectionProperty(Container::class, 'bindings'))->getValue($this->container);
        $concrete = is_array($bindings) ? ($bindings['env']['concrete'] ?? null) : null;
        if ($concrete === null) {
            return null;
        }
        BillingException::require($concrete instanceof Closure, 'changed_policy');
        $function = new ReflectionFunction($concrete);
        $variables = $function->getStaticVariables();
        BillingException::require($function->getClosureScopeClass()?->getName() === Container::class
            && $function->getClosureThis() === $this->container && array_keys($variables) === ['value']
            && is_string($variables['value']), 'changed_policy');

        return $variables['value'];
    }

    private function raw(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        BillingException::require(is_array($instances) && Container::getInstance() === $this->container
            && ($instances['config'] ?? null) === $this->repository, 'changed_policy');
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->repository);
        BillingException::require(is_array($items) && is_array($items['production-membership-billing'] ?? null), 'changed_policy');

        return $items['production-membership-billing'];
    }
}
