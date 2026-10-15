<?php

namespace App\Domain\Grants\PaidTax;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use ReflectionProperty;

/** Captured raw model storage only. Final proof invokes no Eloquent getter, offset or cast. */
final readonly class PaidTaxActor
{
    private function __construct(private User $actor, public int $id, private array $original, private array $attributes) {}

    public static function capture(User $actor): self
    {
        [$original, $attributes, $exists] = self::raw($actor);
        PaidTaxException::require($exists === true && is_int($original['id'] ?? null) && $original['id'] >= 1
            && ($attributes['id'] ?? null) === $original['id'], 403);

        return new self($actor, $original['id'], $original, $attributes);
    }

    public function prove(): void
    {
        [$original, $attributes, $exists] = self::raw($this->actor);
        PaidTaxException::require($exists === true && $original === $this->original && $attributes === $this->attributes, 403);
    }

    private static function raw(User $actor): array
    {
        PaidTaxException::require($actor::class === User::class, 403);
        $original = (new ReflectionProperty(Model::class, 'original'))->getValue($actor);
        $attributes = (new ReflectionProperty(Model::class, 'attributes'))->getValue($actor);
        $public = get_object_vars($actor);
        PaidTaxException::require(is_array($original) && is_array($attributes) && is_bool($public['exists'] ?? null), 403);
        foreach ([$original, $attributes] as $values) {
            foreach ($values as $value) {
                PaidTaxException::require($value === null || is_string($value) || is_int($value) || is_bool($value), 403);
            }
        }

        return [$original, $attributes, $public['exists']];
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new \LogicException('Tax actor admission cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new \LogicException('Tax actor admission cannot be deserialized.');
    }
}
