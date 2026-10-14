<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MembershipReservations;
use Illuminate\Container\Container;
use ReflectionProperty;

/** Original exact activation assembly; every terminal comparison reads captured raw maps only. */
final class MemberActivationConfiguration
{
    private Container $container;

    private array $baseline;

    public readonly MemberOriginalConfiguration $original;

    public function __construct(SealedMemberGrantFacts $facts, MembershipReservations $reservations, MemberOriginalArtifacts $artifacts,
        private readonly MemberLicenseAuthorizations $authorizations, private readonly SealedMemberGrants $grants)
    {
        $this->container = Container::getInstance();
        $this->baseline = $this->snapshot();
        // Complete registration must exist before any factory may execute.
        foreach ([MemberLicenseAuthorizations::class => $authorizations, MemberGrantAuthority::class => $grants] as $key => $value) {
            $instance = $this->baseline['instances'][$key];
            $binding = $this->baseline['bindings'][$key];
            MemberGrantException::require($instance === $value || ($instance === null && is_array($binding)
                && ($binding['concrete'] ?? null) instanceof \Closure && is_bool($binding['shared'] ?? null)), 'capability_absent');
        }
        $this->original = new MemberOriginalConfiguration($facts, $reservations, $artifacts);
        foreach ([MemberLicenseAuthorizations::class => $authorizations, MemberGrantAuthority::class => $grants] as $key => $value) {
            MemberGrantException::require($this->container->make($key) === $value, 'foreign_assembly');
            $this->admit();
        }
        $this->admit();
    }

    public function admit(): void
    {
        MemberGrantException::require(Container::getInstance() === $this->container && $this->snapshot() === $this->baseline, 'changed_configuration');
        $this->original->admit();
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['instances', 'bindings', 'aliases', 'extenders', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'] as $property) {
            $map = (new ReflectionProperty(Container::class, $property))->getValue($this->container);
            MemberGrantException::require(is_array($map), 'changed_configuration');
            foreach ([MemberLicenseAuthorizations::class, MemberGrantAuthority::class] as $key) {
                MemberGrantException::require($property !== 'aliases' || ! array_key_exists($key, $map), 'foreign_assembly');
                $result[$property][$key] = self::copy($map[$key] ?? null);
            }
        }

        return $result;
    }

    private static function copy(mixed $value, int $depth = 0): mixed
    {
        MemberGrantException::require($depth <= 32, 'changed_configuration');
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = self::copy($item, $depth + 1);
            }

            return $copy;
        }
        MemberGrantException::require($value === null || is_scalar($value) || is_object($value), 'changed_configuration');

        return $value;
    }
}
