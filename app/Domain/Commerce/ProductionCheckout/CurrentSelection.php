<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Domain\Media\PrivateMediaFiles;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** Exact v1 non-exclusive rows and fresh private bytes; scoped/exclusive production is a dependent consumer. */
final class CurrentSelection
{
    public static function load(CurrentRows $reader, array $items, CarbonImmutable $at): array
    {
        $items = PreparationSelection::items($items);
        $graph = PreparationSelection::load($reader, $items);
        $selection = PreparationSelection::interpret($graph, $items, $at);
        // Complete link sets prevent an unlinked successor evading historical scope governance.
        $revisionIds = array_column($graph['revisions'], 'id');
        $links = $revisionIds === [] ? [] : $reader->rows('rights_scope_offers', 'offer_revision_id IN ('.implode(',', array_fill(0, count($revisionIds), '?')).')', $revisionIds, 257);
        CheckoutException::require($links === [], 'unsupported_inventory');
        $bytes = self::bytes($graph, $selection);

        return ['items' => $items, 'graph' => $graph, 'links' => $links, 'selection' => $selection,
            'bytes' => $bytes, 'selection_hash' => CanonicalJson::hash(['items' => $items, 'graph' => $graph, 'links' => $links, 'selection' => $selection])];
    }

    private static function bytes(array $graph, array $selection): array
    {
        $bytes = [];
        $assetIds = [];
        foreach ($selection['lines'] as $line) {
            $assetIds = [...$assetIds, ...array_column($line['offer_snapshot']['assets'], 'id'), $line['offer_snapshot']['preview']['id']];
        }
        foreach (array_unique($assetIds) as $id) {
            $asset = PreparationSelection::one($graph['media'], $id);
            $path = app(PrivateMediaFiles::class)->resolve($asset['storage_path']);
            clearstatcache(true, $path);
            $before = @lstat($path);
            $input = @fopen($path, 'rb');
            $opened = $input ? fstat($input) : false;
            try {
                CheckoutException::require($input !== false && is_array($before) && is_array($opened)
                    && $before['ino'] === $opened['ino'] && $before['dev'] === $opened['dev']
                    && ($opened['mode'] & 0170000) === 0100000 && $opened['size'] === $asset['size_bytes'], 'private_bytes');
                $hash = hash_init('sha256');
                hash_update_stream($hash, $input);
                $actual = hash_final($hash);
                $after = fstat($input);
                CheckoutException::require($after['size'] === $opened['size'] && $after['mtime'] === $opened['mtime']
                    && $after['ctime'] === $opened['ctime'] && hash_equals($asset['sha256'], $actual), 'private_bytes');
                $bytes[] = ['asset_id' => $id, 'sha256' => $actual, 'size_bytes' => $opened['size']];
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
            }
        }

        return $bytes;
    }

    public static function proveBytes(array $expected): void
    {
        Evidence::same($expected['bytes'], self::bytes($expected['graph'], $expected['selection']));
    }

    /** Terminal raw rows only: no renderer/storage/decryption/canonicalizer callback after this proof. */
    public static function proveCurrent(CurrentRows $reader, array $expected, CarbonImmutable $at): void
    {
        $actual = PreparationSelection::load($reader, $expected['items']);
        Evidence::same($expected['graph'], $actual);
        $ids = array_column($actual['revisions'], 'id');
        $links = $ids === [] ? [] : $reader->rows('rights_scope_offers', 'offer_revision_id IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids, 257);
        Evidence::same($expected['links'], $links);
        PreparationSelection::effective($actual, $at);
    }
}
