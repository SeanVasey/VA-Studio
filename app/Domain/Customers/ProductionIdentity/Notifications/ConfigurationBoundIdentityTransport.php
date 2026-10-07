<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use JsonSerializable;
use LogicException;
use ReflectionProperty;

/** Refuse stale server settings without a late container, crypto, configuration or socket callback. */
final readonly class ConfigurationBoundIdentityTransport implements IdentityNoticeTransport, JsonSerializable
{
    public function __construct(private Container $container, private Repository $config,
        private array $settings, private mixed $key, private ConfiguredSmtpIdentityTransport $transport) {}

    private function current(): bool
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->config);

        return ($instances['config'] ?? null) === $this->config
            && is_array($items['production-identity-smtp'] ?? null)
            && is_array($items['app'] ?? null)
            && ($items['production-identity-smtp']['settings'] ?? null) === $this->settings
            && ($items['app']['key'] ?? null) === $this->key;
    }

    public function provenance(): string
    {
        if (! $this->current()) {
            throw new IdentityException;
        }

        return $this->transport->provenance();
    }

    public function capabilityVersion(): string
    {
        if (! $this->current()) {
            throw new IdentityException;
        }

        return $this->transport->capabilityVersion();
    }

    public function submit(IdentityMail $mail): IdentityAcceptance
    {
        if (! $this->current()) {
            throw new DefinitelyNotSubmitted;
        }

        return $this->transport->submit($mail);
    }

    public function __debugInfo(): array
    {
        return ['configured' => true];
    }

    public function __serialize(): never
    {
        throw new LogicException('Private SMTP configuration cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Private SMTP configuration is not an HTTP projection.');
    }
}
