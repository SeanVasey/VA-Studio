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

    /**
     * Redeemed downloads per chain, each exported in full. Redemptions are bounded per line by the grant's `max_downloads`,
     * so this cap is reached only by broken data. Unused authorizations are not capped by the download domain (a buyer
     * may request more than they redeem), so they are exported as counts per line and kind, never row by row: their number
     * cannot make an account's export refuse.
     */
    public const MAX_DOWNLOADS = self::MAX_LINES * 10;

    /**
     * Stored payload bytes this chain may load (batches, lines, originals and redeemed authorizations), summed in the
     * database before anything is loaded. A chain's raw rows are held while each payload is decoded in turn, so peak memory
     * tracks this sum: a PHP-only simulation of 64 MiB peaked at about 84 MiB, and buffered MySQL results can briefly hold
     * a large read twice, which still fits inside the 256M FPM pools. Stored payloads are much larger than what is
     * exported (about 23 KiB per line against well under 1 KiB exported), so this budget is reached at roughly as many
     * lines as a full 1 MiB export holds; a larger account refuses with 413 rather than running out of memory. Checked once, like the counts before it; rows committed after the sum can add to the load.
     */
    public const MAX_PAYLOAD_BYTES = 64 * 1024 * 1024;

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

    /** Stored payload bytes the export would load, summed in the database so nothing is loaded to measure it. */
    private static function payloadBytes(array $chain, int $accountId): int
    {
        $length = DB::raw('COALESCE(SUM(LENGTH(payload)), 0) as bytes');
        $batch = DB::table($chain['tables']['batch'])->where('account_id', $accountId)->value($length);
        $lines = self::lines($chain, $accountId)->value(DB::raw('COALESCE(SUM(LENGTH(l.payload)), 0) as bytes'));
        $originals = self::originals($chain, $accountId)->value(DB::raw('COALESCE(SUM(LENGTH(o.payload)), 0) as bytes'));
        $redeemed = DB::table($chain['tables']['authorization'].' as a')
            ->join($chain['tables']['redemption'].' as r', 'r.authorization_id', '=', 'a.id')
            ->where('a.account_id', $accountId)->value(DB::raw('COALESCE(SUM(LENGTH(a.payload)), 0) as bytes'));

        return (int) $batch + (int) $lines + (int) $originals + (int) $redeemed;
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
            || self::redemptions($chain, $accountId)->count() > self::MAX_DOWNLOADS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        if ($batchCount === 0) {
            // A download authorization exists only for a paid order of the same account.
            self::require($authorizationCount === 0);

            return [];
        }
        if (self::payloadBytes($chain, $accountId) > self::MAX_PAYLOAD_BYTES) {
            throw new DataRightsException(413, 'export_too_large');
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
                        'downloads' => $downloads['redeemed'][$line['id']] ?? [],
                        'unusedAuthorizations' => $downloads['unused'][$line['id']] ?? []];
                })->all()];
        })->all();
    }

    /**
     * The account's download history, keyed by internal line id. Redeemed authorizations are exported in full: each must
     * name one of the account's exported lines (and, for the tax chain, its order and hash columns) and seal that line's
     * hash; anything else refuses. Unused ones are counted per line and kind from their columns alone, and every count must
     * also name one of the account's exported lines (with matching tax columns), or the export refuses.
     *
     * @return array{redeemed: array<int, list<array{kind: string, authorizedAt: string, redeemedAt: string}>>,
     *     unused: array<int, array<string, int>>}
     */
    private function downloads(array $chain, int $accountId, Collection $batches, Collection $lines): array
    {
        $tax = $chain['tables']['authorization'] === 'paid_tax_authorizations';
        $keys = $tax ? ['batch_public_id', 'origin_public_id', 'origin_hash'] : ['origin_id'];
        $columns = array_map(static fn (string $column): string => 'a.'.$column, ['id', 'account_id', 'target', 'payload', 'payload_hash', 'created_at', ...$keys]);
        $authorizations = DB::table($chain['tables']['authorization'].' as a')
            ->join($chain['tables']['redemption'].' as r', 'r.authorization_id', '=', 'a.id')
            ->where('a.account_id', $accountId)->orderBy('a.id')->limit(self::MAX_DOWNLOADS + 1)
            ->get([...$columns, 'r.created_at as redeemed_at']);
        if ($authorizations->count() > self::MAX_DOWNLOADS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $byId = $lines->keyBy('id');
        $byPublic = $lines->keyBy('public_id');
        $batchPublic = $batches->pluck('public_id', 'id');
        $line = function (array $row) use ($tax, $byId, $byPublic, $batchPublic): array {
            $line = $tax ? $byPublic->get($row['origin_public_id']) : $byId->get($row['origin_id']);
            self::require($line !== null);
            $line = (array) $line;
            self::require(! $tax || ($row['origin_hash'] === $line['payload_hash'] && $row['batch_public_id'] === $batchPublic->get($line['batch_id'])));

            return $line;
        };
        $redeemed = [];
        foreach ($authorizations as $authorization) {
            $authorization = (array) $authorization;
            self::require((int) $authorization['account_id'] === $accountId);
            $owner = $line($authorization);
            $payload = self::open($chain, $authorization);
            self::require(($payload['schema_version'] ?? null) === $chain['schemas']['authorization']
                && ($payload['origin_hash'] ?? null) === $owner['payload_hash'] && ($payload['kind'] ?? null) === $authorization['target']);
            $redeemed[$owner['id']][] = ['kind' => $authorization['target'], 'authorizedAt' => self::time($authorization['created_at']),
                'redeemedAt' => self::time($authorization['redeemed_at'])];
        }
        // Bounded by the account's lines times the download kinds; the cap only stops broken data from growing the read.
        $groups = DB::table($chain['tables']['authorization'].' as a')
            ->leftJoin($chain['tables']['redemption'].' as r', 'r.authorization_id', '=', 'a.id')
            ->whereNull('r.authorization_id')->where('a.account_id', $accountId)
            ->groupBy(...array_map(static fn (string $key): string => 'a.'.$key, [...$keys, 'target']))
            ->orderBy('a.target')->limit(self::MAX_DOWNLOADS + 1)
            ->get([...array_map(static fn (string $key): string => 'a.'.$key, [...$keys, 'target']), DB::raw('COUNT(*) as unused')]);
        if ($groups->count() > self::MAX_DOWNLOADS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $unused = [];
        foreach ($groups as $group) {
            $group = (array) $group;
            $owner = $line($group);
            self::require(is_string($group['target']) && $group['target'] !== '' && ! isset($unused[$owner['id']][$group['target']]));
            $unused[$owner['id']][$group['target']] = (int) $group['unused'];
        }
        foreach ($unused as &$kinds) {
            ksort($kinds);
        }
        unset($kinds);

        return ['redeemed' => $redeemed, 'unused' => $unused];
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
