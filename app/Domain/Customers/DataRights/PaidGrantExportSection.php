<?php

namespace App\Domain\Customers\DataRights;

use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The requester's paid-grant copies of their buyer declarations for the data export (D-30, before activation): the V1 paid
 * and V2 paid-tax grant chains, which today run only where `PaidGrantPolicy`/`PaidTaxPolicy` admit them. Each chain keeps an
 * order origin per paid order (keyed by `account_id`, holding the sealed original buyer binding), a grant origin per line
 * (holding a verbatim copy of the order's `buyer_declarations`), once rendered the original contract's manifest, and the
 * account's download history (authorizations, keyed by `account_id`, and their redemptions).
 *
 * Batches and authorizations are found through the requester's account only; lines, originals and redemptions only through
 * those. An authorization must name one of the account's exported lines, and its payload that line's hash. Reads are
 * plain, non-locking selects, as the data-rights READ COMMITTED transaction requires: the chains' own readers take row locks
 * and must not run here. Every payload is opened with its hash checked, and the sealed buyer binding must name the
 * requester's production origin and account, and each line must repeat that binding, so a misfiled row can never enter
 * another customer's export. A missing table or a mismatch refuses with `production_record_unavailable`.
 */
final class PaidGrantExportSection
{
    /** Per chain: section key, tables, the payload schema of each record kind, and the sealed purpose. */
    public const CHAINS = [
        'paidGrantOrders' => ['decoder' => PaidGrantRecords::class, 'purpose' => 'paid-license-grant',
            'tables' => ['batch' => 'paid_order_origins', 'line' => 'paid_grant_origins', 'original' => 'paid_originals',
                'authorization' => 'paid_authorizations', 'redemption' => 'paid_redemptions'],
            'schemas' => ['batch' => 'paid-order-origin-v1', 'line' => 'paid-grant-origin-v1', 'original' => 'paid-first-original-v1',
                'authorization' => 'paid-authorization-v1']],
        'paidTaxGrantOrders' => ['decoder' => PaidTaxRecords::class, 'purpose' => 'paid-tax-license-grant',
            'tables' => ['batch' => 'paid_tax_order_origins', 'line' => 'paid_tax_grant_origins', 'original' => 'paid_tax_originals',
                'authorization' => 'paid_tax_authorizations', 'redemption' => 'paid_tax_redemptions'],
            'schemas' => ['batch' => 'paid-tax-order-origin-v2', 'line' => 'paid-tax-grant-origin-v2', 'original' => 'paid-tax-first-original-v2',
                'authorization' => 'paid-tax-authorization-v2']],
    ];

    /** Paid orders per chain, as for test orders; counted before any payload is loaded. */
    public const MAX_RECORDS = CustomerDataExportDocument::MAX_ORDERS;

    /** Lines, and originals, per chain: the ten lines a paid order may hold (the origin insert guard), times the order cap. */
    public const MAX_LINES = self::MAX_RECORDS * 10;

    /** Download authorizations, and redemptions, per chain. */
    public const MAX_DOWNLOADS = self::MAX_RECORDS * 10;

    /** @return array{paidGrantOrders: list<array>, paidTaxGrantOrders: list<array>} */
    public function build(int $accountId): array
    {
        $origin = ProductionCheckoutExportSection::origin($accountId);
        $sections = [];
        foreach (self::CHAINS as $section => $chain) {
            $sections[$section] = $this->chain($chain, $accountId, $origin);
        }

        return $sections;
    }

    /** @return array<string, int> row counts per retained table, for the deletion record */
    public static function counts(int $accountId): array
    {
        $counts = [];
        foreach (self::CHAINS as $chain) {
            ['batch' => $batch, 'line' => $line, 'original' => $original, 'authorization' => $authorization, 'redemption' => $redemption] = $chain['tables'];
            foreach ($chain['tables'] as $table) {
                self::installed($table);
            }
            $counts[$batch] = DB::table($batch)->where('account_id', $accountId)->count();
            $counts[$line] = self::lines($chain, $accountId)->count();
            $counts[$original] = self::originals($chain, $accountId)->count();
            $counts[$authorization] = DB::table($authorization)->where('account_id', $accountId)->count();
            $counts[$redemption] = self::redemptions($chain, $accountId)->count();
        }

        return $counts;
    }

    private static function installed(string $table): void
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            throw new DataRightsException(503, 'production_record_unavailable');
        }
    }

    /** Lines of the account's batches: reached only through `account_id` on the batch. */
    private static function lines(array $chain, int $accountId): Builder
    {
        return DB::table($chain['tables']['line'].' as l')->join($chain['tables']['batch'].' as b', 'b.id', '=', 'l.batch_id')
            ->where('b.account_id', $accountId);
    }

    private static function originals(array $chain, int $accountId): Builder
    {
        return DB::table($chain['tables']['original'].' as o')->join($chain['tables']['line'].' as l', 'l.id', '=', 'o.origin_id')
            ->join($chain['tables']['batch'].' as b', 'b.id', '=', 'l.batch_id')->where('b.account_id', $accountId);
    }

    private static function redemptions(array $chain, int $accountId): Builder
    {
        return DB::table($chain['tables']['redemption'].' as r')->join($chain['tables']['authorization'].' as a', 'a.id', '=', 'r.authorization_id')
            ->where('a.account_id', $accountId);
    }

    private function chain(array $chain, int $accountId, ?string $origin): array
    {
        foreach ($chain['tables'] as $table) {
            self::installed($table);
        }
        // Count first: an oversized export refuses before any payload is loaded.
        $batchCount = DB::table($chain['tables']['batch'])->where('account_id', $accountId)->count();
        $authorizationCount = DB::table($chain['tables']['authorization'])->where('account_id', $accountId)->count();
        if ($batchCount > self::MAX_RECORDS || self::lines($chain, $accountId)->count() > self::MAX_LINES
            || self::originals($chain, $accountId)->count() > self::MAX_LINES
            || $authorizationCount > self::MAX_DOWNLOADS || self::redemptions($chain, $accountId)->count() > self::MAX_DOWNLOADS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        if ($batchCount === 0) {
            // A download authorization exists only for a paid order of the same account.
            self::require($authorizationCount === 0);

            return [];
        }
        // A paid batch is created only for a production principal, so one without the account's origin is a broken record.
        if ($origin === null) {
            throw new DataRightsException(503, 'production_record_unavailable');
        }
        $batches = DB::table($chain['tables']['batch'])->where('account_id', $accountId)->orderBy('id')->limit(self::MAX_RECORDS + 1)
            ->get(['id', 'public_id', 'account_id', 'order_public_id', 'line_count', 'payload', 'payload_hash', 'created_at']);
        // Re-checked after each read: rows committed between the count and the select must not lift the cap.
        if ($batches->count() > self::MAX_RECORDS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $lines = DB::table($chain['tables']['line'])->whereIn('batch_id', $batches->pluck('id'))->orderBy('batch_id')->orderBy('position')
            ->limit(self::MAX_LINES + 1)->get(['id', 'public_id', 'batch_id', 'position', 'payload', 'payload_hash', 'created_at']);
        if ($lines->count() > self::MAX_LINES) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $originals = DB::table($chain['tables']['original'])->whereIn('origin_id', $lines->pluck('id'))->orderBy('id')
            ->limit(self::MAX_LINES + 1)->get(['origin_id', 'claim_id', 'payload', 'payload_hash', 'created_at']);
        if ($originals->count() > self::MAX_LINES) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $originals = $originals->keyBy('origin_id');
        $downloads = $this->downloads($chain, $accountId, $batches, $lines);
        $lines = $lines->groupBy('batch_id');

        return $batches->map(function (object $batch) use ($chain, $accountId, $origin, $lines, $originals, $downloads): array {
            $batch = (array) $batch;
            $payload = self::open($chain, $batch);
            $buyer = $payload['original_buyer'] ?? null;
            self::require((int) $batch['account_id'] === $accountId && ($payload['schema_version'] ?? null) === $chain['schemas']['batch']
                && ($payload['purpose'] ?? null) === $chain['purpose'] && ($payload['origin_id'] ?? null) === $batch['public_id']
                && ($payload['order_id'] ?? null) === $batch['order_public_id'] && is_array($buyer)
                && is_string($buyer['origin_id'] ?? null) && hash_equals($origin, $buyer['origin_id'])
                && ($buyer['account_id'] ?? null) === $accountId);
            $batchLines = $lines->get($batch['id'], collect())->values();
            self::require($batchLines->count() === (int) $batch['line_count']);

            return ['id' => $batch['public_id'], 'orderId' => $batch['order_public_id'], 'createdAt' => self::time($batch['created_at']),
                'lines' => $batchLines->map(function (object $line, int $index) use ($chain, $batch, $buyer, $originals, $downloads): array {
                    $line = (array) $line;
                    $body = self::open($chain, $line);
                    $source = $body['source'] ?? null;
                    self::require(($body['schema_version'] ?? null) === $chain['schemas']['line'] && ($body['purpose'] ?? null) === $chain['purpose']
                        && ($body['origin_id'] ?? null) === $line['public_id'] && ($body['batch_id'] ?? null) === $batch['public_id']
                        && (int) $line['position'] === $index + 1 && ($body['position'] ?? null) === $index + 1 && is_array($source)
                        && ($source['buyer'] ?? null) === $buyer && is_array($source['buyer_declarations'] ?? null));
                    $original = $originals->get($line['id']);

                    return ['id' => $line['public_id'], 'position' => $index + 1,
                        'title' => is_string($source['product']['title'] ?? null) ? $source['product']['title'] : null,
                        'buyerDeclarations' => $source['buyer_declarations'],
                        'contractOriginal' => $original === null ? null : self::original($chain, (array) $original, $line),
                        'downloads' => $downloads[$line['id']] ?? []];
                })->all()];
        })->all();
    }

    /**
     * The account's download history, keyed by internal line id. Each authorization must name one of the account's exported
     * lines (and, for the tax chain, its order and hash columns) and seal that line's hash; anything else refuses.
     *
     * @return array<int, list<array{kind: string, authorizedAt: string, redeemedAt: ?string}>>
     */
    private function downloads(array $chain, int $accountId, Collection $batches, Collection $lines): array
    {
        $tax = $chain['tables']['authorization'] === 'paid_tax_authorizations';
        $columns = ['id', 'account_id', 'target', 'payload', 'payload_hash', 'created_at', ...($tax ? ['batch_public_id', 'origin_public_id', 'origin_hash'] : ['origin_id'])];
        $authorizations = DB::table($chain['tables']['authorization'])->where('account_id', $accountId)->orderBy('id')
            ->limit(self::MAX_DOWNLOADS + 1)->get($columns);
        if ($authorizations->count() > self::MAX_DOWNLOADS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $redemptions = DB::table($chain['tables']['redemption'])->whereIn('authorization_id', $authorizations->pluck('id'))->orderBy('id')
            ->limit(self::MAX_DOWNLOADS + 1)->get(['authorization_id', 'created_at']);
        if ($redemptions->count() > self::MAX_DOWNLOADS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $redemptions = $redemptions->keyBy('authorization_id');
        $byId = $lines->keyBy('id');
        $byPublic = $lines->keyBy('public_id');
        $batchPublic = $batches->pluck('public_id', 'id');
        $result = [];
        foreach ($authorizations as $authorization) {
            $authorization = (array) $authorization;
            $line = $tax ? $byPublic->get($authorization['origin_public_id']) : $byId->get($authorization['origin_id']);
            self::require($line !== null && (int) $authorization['account_id'] === $accountId);
            $line = (array) $line;
            $payload = self::open($chain, $authorization);
            self::require(($payload['schema_version'] ?? null) === $chain['schemas']['authorization']
                && ($payload['origin_hash'] ?? null) === $line['payload_hash'] && ($payload['kind'] ?? null) === $authorization['target']
                && (! $tax || ($authorization['origin_hash'] === $line['payload_hash']
                    && $authorization['batch_public_id'] === $batchPublic->get($line['batch_id']))));
            $redemption = $redemptions->get($authorization['id']);
            $result[$line['id']][] = ['kind' => $authorization['target'], 'authorizedAt' => self::time($authorization['created_at']),
                'redeemedAt' => $redemption === null ? null : self::time($redemption->created_at)];
        }

        return $result;
    }

    private static function original(array $chain, array $row, array $line): array
    {
        $manifest = self::open($chain, $row);
        $artifact = $manifest['artifact'] ?? null;
        self::require(($manifest['schema_version'] ?? null) === $chain['schemas']['original'] && ($manifest['origin_hash'] ?? null) === $line['payload_hash']
            && ($manifest['claim_id'] ?? null) === $row['claim_id'] && is_array($artifact)
            && is_string($artifact['pdf_hash'] ?? null) && is_int($artifact['size_bytes'] ?? null));

        return ['createdAt' => self::time($row['created_at']), 'sha256' => $artifact['pdf_hash'], 'sizeBytes' => $artifact['size_bytes']];
    }

    /** Hash-checked decryption through the chain's own pure codec; any failure is an unavailable record. */
    private static function open(array $chain, array $row): array
    {
        try {
            return $chain['decoder']::decode($row);
        } catch (Throwable) {
            throw new DataRightsException(503, 'production_record_unavailable');
        }
    }

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new DataRightsException(503, 'production_record_unavailable');
        }
    }

    private static function time(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString();
    }
}
