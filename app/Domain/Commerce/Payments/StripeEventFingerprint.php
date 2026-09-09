<?php

namespace App\Domain\Commerce\Payments;

use stdClass;

/** Receipt comparison only. Provider numbers are retained, never used as money authority. */
final class StripeEventFingerprint
{
    public const VERSION = 'stripe-event-v1';

    public static function hash(stdClass $event): string
    {
        $snapshot = clone $event;
        // This delivery counter can change when the same immutable event is retried.
        unset($snapshot->pending_webhooks);

        return hash('sha256', json_encode(self::sort($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function sort(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $result = new stdClass;
            foreach ($properties as $key => $property) {
                $result->{(string) $key} = self::sort($property);
            }

            return $result;
        }

        return is_array($value) ? array_map(self::sort(...), $value) : $value;
    }
}
