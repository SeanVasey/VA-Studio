<?php

namespace App\Domain\Memberships\Billing;

use DateTimeImmutable;
use DateTimeZone;

/** A parsed UTC value and its query-start anchor. Values alone confer no clock authority. */
final readonly class BillingDatabaseInstant
{
    private function __construct(public string $stamp26, public int $utcMicros, public int $anchorNs) {}

    public static function fromNativeValue(string $stamp26, int $anchorNs): self
    {
        BillingException::require($anchorNs >= 0 && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D', $stamp26) === 1, 'database_clock');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $stamp26, new DateTimeZone('UTC'));
        BillingException::require($date !== false && $date->format('Y-m-d H:i:s.u') === $stamp26
            && $date->getTimestamp() > 0 && $date->getTimestamp() < 253402300800, 'database_clock');

        return new self($stamp26, $date->getTimestamp() * 1_000_000 + (int) $date->format('u'), $anchorNs);
    }

    public function seconds(): int
    {
        return intdiv($this->utcMicros, 1_000_000);
    }

    public function stamp19(): string
    {
        return substr($this->stamp26, 0, 19);
    }
}
