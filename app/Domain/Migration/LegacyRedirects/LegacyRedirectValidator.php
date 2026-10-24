<?php

namespace App\Domain\Migration\LegacyRedirects;

/**
 * Reconciles the map with the route inventory and the live route table. Errors make the map unfit for activation;
 * warnings (a content target that is not public right now) are reported but do not fail, because the request path
 * re-checks publication and answers 404 instead of redirecting to private content.
 */
final class LegacyRedirectValidator
{
    public function __construct(private readonly LegacyRedirectTargets $targets) {}

    /**
     * @return array{errors: list<string>, warnings: list<string>, counts: array<string, int>,
     *     redirects: list<array{route_id: string, source: string, status: int, target: string}>,
     *     gone: list<array{route_id: string, source: string}>,
     *     retained: list<array{route_id: string, source: string, route: string|null}>,
     *     unresolved: list<array{route_id: string, source: string, reason: string, served_by: string|null}>}
     */
    public function validate(LegacyRedirectMap $map, LegacyRouteInventory $inventory): array
    {
        $errors = [];
        $warnings = [];
        if (! hash_equals($map->inventory['sha256'], $inventory->sha256)) {
            $errors[] = 'Inventory changed: the map is pinned to sha256 '.$map->inventory['sha256'].' but the inventory is '.$inventory->sha256.'. Review every row, then update the pin.';
        }
        if ($map->inventory['rows'] !== count($inventory->rows)) {
            $errors[] = 'The map pins '.$map->inventory['rows'].' inventory rows; the inventory has '.count($inventory->rows).'.';
        }
        $rows = [];
        foreach ($inventory->rows as $row) {
            if (isset($rows[$row['route_id']])) {
                $errors[] = 'Inventory route_id '.$row['route_id'].' appears more than once.';
            }
            $rows[$row['route_id']] = $row;
        }
        $mapped = [];
        foreach ($map->entries as $entry) {
            $mapped[$entry['route_id']] = true;
            $row = $rows[$entry['route_id']] ?? null;
            if ($row === null) {
                $errors[] = $entry['route_id'].' is not an inventory row; the map may not invent legacy URLs.';

                continue;
            }
            if ($row['source_host'] !== $entry['source_host'] || $row['source_path'] !== $entry['source_path']) {
                $errors[] = $entry['route_id'].' source '.$entry['source_host'].$entry['source_path'].' differs from the inventory ('.$row['source_host'].$row['source_path'].').';
            }
        }
        foreach (array_keys($rows) as $id) {
            if (! isset($mapped[$id])) {
                $errors[] = 'Inventory row '.$id.' has no map entry; record it as retained, redirect, gone or unresolved.';
            }
        }

        $report = ['redirects' => [], 'gone' => [], 'retained' => [], 'unresolved' => []];
        foreach ($map->entries as $entry) {
            $id = $entry['route_id'];
            $served = $this->targets->servingRouteForPath($entry['source_path']);
            switch ($entry['disposition']) {
                case 'retained':
                    if ($served === null) {
                        $errors[] = $id.' is marked retained but no route serves '.$entry['source_path'].'.';
                    }
                    $report['retained'][] = ['route_id' => $id, 'source' => $entry['source_path'], 'route' => $served];
                    break;
                case 'unresolved':
                    $report['unresolved'][] = ['route_id' => $id, 'source' => $entry['source_path'], 'reason' => $entry['reason'], 'served_by' => $served];
                    if ($served !== null) {
                        $warnings[] = $id.' is unresolved but '.$entry['source_path'].' is now served by '.$served.'; review it as retained.';
                    }
                    break;
                case 'gone':
                case 'redirect':
                    // A real route always wins, so a shadowed entry would never act: refuse it instead of keeping dead config.
                    if ($served !== null) {
                        $errors[] = $id.' '.$entry['source_path'].' is served by route '.$served.'; a redirect or gone entry may only cover a path no route handles.';
                    }
                    if ($entry['disposition'] === 'gone') {
                        $report['gone'][] = ['route_id' => $id, 'source' => $entry['source_path']];
                        break;
                    }
                    $target = $this->targets->describe($entry, $map);
                    if ($target['problem'] !== null) {
                        $errors[] = $id.' target: '.$target['problem'].'.';
                        break;
                    }
                    if (! $this->targets->currentlyPublic($entry['target'])) {
                        $warnings[] = $id.' target '.$target['path'].' is not public right now; requests answer 404 until it is published.';
                    }
                    $report['redirects'][] = ['route_id' => $id, 'source' => $entry['source_path'], 'status' => $entry['status'], 'target' => $target['path'] ?? $target['url']];
                    break;
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'counts' => [
                'inventory' => count($inventory->rows), 'entries' => count($map->entries),
                'redirect' => count($report['redirects']), 'gone' => count($report['gone']),
                'retained' => count($report['retained']), 'unresolved' => count($report['unresolved']),
            ],
            ...$report,
        ];
    }
}
