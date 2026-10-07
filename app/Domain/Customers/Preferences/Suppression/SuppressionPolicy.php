<?php

namespace App\Domain\Customers\Preferences\Suppression;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Support\CanonicalJson;

final class SuppressionPolicy
{
    public static function capture(): array
    {
        $configuration = config('customer-suppression');
        $binding = is_array($configuration) ? ($configuration['binding'] ?? null) : null;
        $valid = is_array($configuration) && ConsentPolicy::keys($configuration, ['enabled', 'binding']) && $configuration['enabled'] === true
            && self::binding($binding);

        return ['configuration' => $configuration, 'binding' => $valid ? $binding : null, 'hash' => $valid ? CanonicalJson::hash($binding) : null];
    }

    public static function current(array $captured): void
    {
        if (config('customer-suppression') !== $captured['configuration']) {
            throw new ConsentException(503);
        }
    }

    public static function binding(mixed $binding): bool
    {
        return is_array($binding) && ConsentPolicy::keys($binding, ['adapter', 'version', 'scope', 'reviewReference'])
            && ConsentPolicy::version($binding['adapter']) && ConsentPolicy::version($binding['version'])
            && self::text($binding['scope']) && self::text($binding['reviewReference']);
    }

    private static function text(mixed $value): bool
    {
        return ConsentPolicy::text($value, 200, 800) && trim($value) === $value && preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 0;
    }
}
