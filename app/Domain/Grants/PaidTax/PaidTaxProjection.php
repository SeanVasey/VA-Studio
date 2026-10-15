<?php

namespace App\Domain\Grants\PaidTax;

/** Primitive versioned display state only. This value grants no download or purchase authority. */
final readonly class PaidTaxProjection implements \JsonSerializable
{
    private function __construct(private array $value) {}

    /** @internal Only the authenticated retained graph builder supplies this display value. */
    public static function fromTrusted(array $value): self
    {
        PaidTaxInput::keys($value, ['id', 'orderId', 'purpose', 'schemaVersion', 'provenance', 'fulfilled', 'lines']);
        PaidTaxInput::uuid($value['id']);
        PaidTaxInput::uuid($value['orderId']);
        PaidTaxException::require($value['purpose'] === 'paid-tax-license-grant' && $value['schemaVersion'] === 2
            && $value['provenance'] === 'synthetic_rehearsal' && is_bool($value['fulfilled'])
            && is_array($value['lines']) && array_is_list($value['lines']) && count($value['lines']) >= 1 && count($value['lines']) <= 10);
        foreach ($value['lines'] as $line) {
            PaidTaxException::require(is_array($line) && is_array($line['tax'] ?? null) && is_array($line['preTax'] ?? null)
                && is_int($line['position'] ?? null) && is_int($line['attempts'] ?? null));
            foreach (['line_subtotal_minor', 'line_tax_minor', 'line_total_minor', 'order_subtotal_minor', 'order_tax_minor', 'order_total_minor'] as $key) {
                PaidTaxException::require(is_int($line['tax'][$key] ?? null));
            }
        }
        $nodes = 0;
        self::primitive($value, 0, $nodes);

        return new self($value);
    }

    private static function primitive(mixed $value, int $depth, int &$nodes): void
    {
        PaidTaxException::require($depth <= 32 && ++$nodes <= 40000);
        if (is_array($value)) {
            foreach ($value as $item) {
                self::primitive($item, $depth + 1, $nodes);
            }

            return;
        }
        PaidTaxException::require($value === null || is_string($value) || is_bool($value) || is_int($value)
            || is_float($value) && is_finite($value));
    }

    public function toArray(): array
    {
        return $this->value;
    }

    public function jsonSerialize(): array
    {
        return $this->value;
    }
}
