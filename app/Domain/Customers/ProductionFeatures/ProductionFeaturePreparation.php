<?php

namespace App\Domain\Customers\ProductionFeatures;

use Closure;
use LogicException;

/** Server-only preparation; it neither authorizes output nor creates a closing lease. */
final class ProductionFeaturePreparation
{
    private ?string $bytes = null;

    private ?ProductionFeatureRelease $release = null;

    public function __construct(private readonly Closure $guard, private readonly int $maximumBytes,
        private readonly bool $export = false) {}

    public function start(ProductionFeatureContext $context): void
    {
        ProductionFeatureOperation::assertLive($context);
        if ($this->bytes !== null || $this->release !== null || $this->maximumBytes < 1 || $this->maximumBytes > 3 * 1024 * 1024) {
            throw new ProductionFeatureException;
        }
        ($this->guard)();
        $context->guard($this->guard);
    }

    public function prepare(ProductionFeatureContext $context, array $projection): void
    {
        ProductionFeatureOperation::assertLive($context);
        if ($this->bytes !== null) {
            throw new ProductionFeatureException;
        }
        $remaining = 100000;
        self::plain($projection, $remaining);
        $bytes = json_encode($this->export ? ['export' => $projection] : $projection,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($bytes) > $this->maximumBytes) {
            throw new ProductionFeatureException;
        }
        $this->bytes = $bytes;
    }

    /** Callable only during run's private, callback-free positive commit window. */
    public function seal(ProductionFeatureContext $context): void
    {
        if ($this->bytes === null || $this->release !== null) {
            throw new ProductionFeatureException;
        }
        $this->release = ProductionFeatureRelease::fromOperation($context);
    }

    public function bytes(): string
    {
        if ($this->bytes === null || $this->release === null) {
            throw new ProductionFeatureException;
        }

        return $this->bytes;
    }

    public function finish(): void
    {
        if ($this->release === null) {
            throw new ProductionFeatureException;
        }
        $this->release->finish();
    }

    private static function plain(mixed $value, int &$remaining, int $depth = 0): void
    {
        if (--$remaining < 0 || $depth > 24) {
            throw new ProductionFeatureException;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::plain($child, $remaining, $depth + 1);
            }
        } elseif (! is_null($value) && ! is_string($value) && ! is_bool($value) && ! is_int($value)) {
            throw new ProductionFeatureException;
        }
    }

    public function __serialize(): never
    {
        throw new LogicException('Feature preparation cannot be serialized.');
    }
}
