<?php

namespace App\Domain\Customers\DataRights;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The requester's production checkout records for the data export (D-30): V1 reviews and orders and V2 tax checkout orders,
 * each with the buyer declarations frozen into it. Rows are found through the account's one production identity origin
 * (`production_identity_origins.account_id` is unique) and read with plain, non-locking selects, as the data-rights READ
 * COMMITTED transaction requires: the pinned checkout readers take row locks and must not run here. Every row is opened with
 * its hash checked before decryption, and its own sealed buyer binding must name the same origin, so a misfiled row can never
 * enter another customer's export.
 */
final class ProductionCheckoutExportSection
{
    /** Retained production checkout records that carry buyer data, with their sealed purpose. */
    public const RECORDS = [
        'production_checkout_reviews' => ['purpose' => 'production_checkout_review', 'section' => 'productionCheckoutReviews'],
        'production_checkout_orders' => ['purpose' => 'production_checkout_order', 'section' => 'productionCheckoutOrders'],
        'production_tax_checkout_orders' => ['purpose' => 'production_tax_checkout_order', 'section' => 'productionTaxCheckoutOrders'],
    ];

    /** @return array{productionCheckoutReviews: list<array>, productionCheckoutOrders: list<array>, productionTaxCheckoutOrders: list<array>} */
    public function build(int $accountId): array
    {
        $origin = self::origin($accountId);
        $sections = [];
        foreach (self::RECORDS as $table => $record) {
            $sections[$record['section']] = $origin === null ? [] : $this->records($table, $record['purpose'], $origin);
        }

        return $sections;
    }

    /** @return array<string, int> row counts per retained table, for the deletion record */
    public static function counts(int $accountId): array
    {
        $origin = self::origin($accountId);
        $counts = [];
        foreach (array_keys(self::RECORDS) as $table) {
            $counts[$table] = $origin === null || ! self::installed($table) ? 0 : DB::table($table)->where('buyer_origin_id', $origin)->count();
        }

        return $counts;
    }

    private static function origin(int $accountId): ?string
    {
        if (! self::installed('production_identity_origins')) {
            return null;
        }
        $origin = DB::table('production_identity_origins')->where('account_id', $accountId)->value('public_id');

        return is_string($origin) ? $origin : null;
    }

    private static function installed(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function records(string $table, string $purpose, string $origin): array
    {
        if (! self::installed($table)) {
            return [];
        }
        $columns = ['public_id', 'created_at', 'payload_ciphertext', 'payload_hash', 'canonicalization_version', 'buyer_origin_id'];
        $columns = [...$columns, ...match ($table) {
            CheckoutSchema::TABLES['order'] => ['total_minor', 'line_count'],
            TaxCheckoutSchema::TABLES['order'] => ['currency', 'subtotal_minor', 'line_count'],
            default => [],
        }];
        $rows = DB::table($table)->where('buyer_origin_id', $origin)->orderBy('id')
            ->limit(CustomerDataExportDocument::MAX_ROWS + 1)->get($columns);
        if ($rows->count() > CustomerDataExportDocument::MAX_ROWS) {
            throw new DataRightsException(413, 'export_too_large');
        }

        return $rows->map(function (object $row) use ($purpose, $origin, $table): array {
            $row = (array) $row;
            try {
                $body = Evidence::open($row, $purpose);
            } catch (CheckoutException) {
                throw new DataRightsException(503, 'production_record_unavailable');
            }
            $buyer = $body['buyer'] ?? null;
            if (! hash_equals($origin, (string) $row['buyer_origin_id']) || ! is_array($buyer)
                || ! is_string($buyer['origin_id'] ?? null) || ! hash_equals($origin, $buyer['origin_id'])
                || ! is_array($body['buyer_declarations'] ?? null)) {
                throw new DataRightsException(503, 'production_record_unavailable');
            }

            return ['id' => $row['public_id'], 'createdAt' => CarbonImmutable::parse($row['created_at'], 'UTC')->toIso8601ZuluString(),
                'buyerDeclarations' => $body['buyer_declarations']] + match ($table) {
                    CheckoutSchema::TABLES['order'] => ['totalMinor' => (int) $row['total_minor'], 'lineCount' => (int) $row['line_count']],
                    TaxCheckoutSchema::TABLES['order'] => ['currency' => $row['currency'], 'subtotalMinor' => (int) $row['subtotal_minor'],
                        'lineCount' => (int) $row['line_count']],
                    default => [],
                };
        })->all();
    }
}
