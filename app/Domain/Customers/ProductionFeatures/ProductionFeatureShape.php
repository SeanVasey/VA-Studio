<?php

namespace App\Domain\Customers\ProductionFeatures;

use DateTimeImmutable;
use DateTimeZone;

final class ProductionFeatureShape
{
    public static function timestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
            return false;
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $time !== false && $time->format('Y-m-d H:i:s') === $value;
    }
}
