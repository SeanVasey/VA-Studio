<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use InvalidArgumentException;

/**
 * Adapter from one non-track product family sheet (sound kits, services, memberships) to the order-line rows the
 * history normalizer already understands, so every family ends in the same normalized line record.
 *
 * The line record shape is frozen (`vasey-legacy-history-v2`), so a family adds no field. Its product id is carried
 * in `track_source_id` as `<prefix>:<source id>` (`kit:`, `service:`, `membership:`); the normalizer refuses a track
 * id inside those namespaces, so a family line can never be read as a track or linked to one. The family's terms
 * column (kit license, service package, membership plan) is declared by the operator exactly like a license name.
 *
 * Archive-only, like every line: a family line is history. It records no subscription, credit, open job, deposit
 * or download right, it is never reviewable as a `verified_grant`, and it is never an exclusive.
 */
final class ProductFamilyAdapter
{
    /** family => [sheet key used in findings and inputs, file name, id namespace] */
    public const FAMILIES = [
        'kits' => ['sheet' => 'kit_orders', 'file' => 'kit_orders.csv', 'prefix' => 'kit'],
        'services' => ['sheet' => 'service_orders', 'file' => 'service_orders.csv', 'prefix' => 'service'],
        'memberships' => ['sheet' => 'membership_orders', 'file' => 'membership_orders.csv', 'prefix' => 'membership'],
    ];

    public const COLUMNS = ['required' => ['source_order_id', 'source_line_id', 'source_customer_id', 'ordered_at', 'status',
        'product_source_id', 'terms', 'amount', 'refunded_amount'], 'optional' => ['payment_reference', 'currency']];

    /** Family field => the order-line field it fills. */
    private const FIELDS = ['product_source_id' => 'track_source_id', 'terms' => 'license'];

    public readonly string $sheet;

    public readonly string $file;

    public readonly string $prefix;

    public function __construct(public readonly string $family)
    {
        if (! isset(self::FAMILIES[$family])) {
            throw new InvalidArgumentException('mapping_product_families');
        }
        ['sheet' => $this->sheet, 'file' => $this->file, 'prefix' => $this->prefix] = self::FAMILIES[$family];
    }

    /** The family whose namespace a stored `track_source_id` is in, or null for a track. */
    public static function familyOf(string $trackSourceId): ?string
    {
        foreach (self::FAMILIES as $family => $definition) {
            if (str_starts_with($trackSourceId, $definition['prefix'].':')) {
                return $family;
            }
        }

        return null;
    }

    public function qualify(string $productSourceId): string
    {
        return $this->prefix.':'.$productSourceId;
    }

    /**
     * Family sheet rows (as HistoryNormalizer aligns them) in the order-line row shape. The product id stays raw
     * here so the normalizer validates it like any source id before qualifying it.
     *
     * @param  list<array{row: int, cells: array<string, ?string>, row_sha256: string}>  $rows
     * @return list<array{row: int, cells: array<string, ?string>, row_sha256: string, sheet: string, family: string, columns: array<string, string>}>
     */
    public function orderRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row['cells'] as $field => $value) {
                $cells[self::FIELDS[$field] ?? $field] = $value;
            }
            $result[] = ['row' => $row['row'], 'cells' => $cells, 'row_sha256' => $row['row_sha256'], 'sheet' => $this->sheet,
                'family' => $this->family, 'columns' => array_flip(self::FIELDS)];
        }

        return $result;
    }
}
