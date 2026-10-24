<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectMap;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectMapInvalid;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectValidator;
use App\Domain\Migration\LegacyRedirects\LegacyRouteInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LegacyRedirectFixtures as F;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class LegacyRedirectMapTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->directory = sys_get_temp_dir().'/legacy-redirect-map-'.Str::uuid();
    }

    protected function tearDown(): void
    {
        F::remove($this->directory);
        parent::tearDown();
    }

    /** @return array{errors: list<string>, warnings: list<string>, counts: array<string, int>} */
    private function validate(array $entries, array $hosts = [], ?array $inventoryEntries = null): array
    {
        $files = F::write($this->directory, $entries, $hosts, $inventoryEntries);

        return app(LegacyRedirectValidator::class)->validate(LegacyRedirectMap::fromFile($files['map']), LegacyRouteInventory::read($files['inventory']));
    }

    private function assertError(array $report, string $needle): void
    {
        $this->assertNotEmpty(array_filter($report['errors'], fn (string $error) => str_contains($error, $needle)), $needle.' not in: '.implode(' | ', $report['errors']));
    }

    public function test_the_shipped_map_reconciles_every_inventory_row_without_inventing_or_activating_redirects(): void
    {
        $report = app(LegacyRedirectValidator::class)->validate(
            LegacyRedirectMap::fromFile(config('legacy-redirects.map_path')),
            LegacyRouteInventory::read(config('legacy-redirects.inventory_path')),
        );
        $this->assertSame([], $report['errors']);
        $this->assertSame(['inventory' => 20, 'entries' => 20, 'redirect' => 0, 'gone' => 0, 'retained' => 5, 'unresolved' => 15], $report['counts']);
        $this->assertSame(['URL-001', 'URL-009', 'URL-010', 'URL-011', 'URL-012'], array_column($report['retained'], 'route_id'));
        $this->assertSame(['home', 'editorial.show', 'editorial.show', 'editorial.show', 'editorial.show'], array_column($report['retained'], 'route'));
        foreach ($report['unresolved'] as $row) {
            $this->assertNull($row['served_by'], $row['route_id']);
            $this->assertNotSame('', trim($row['reason']));
        }
        $this->assertFalse(config('legacy-redirects.enabled'));
    }

    public static function malformedMaps(): array
    {
        $ok = F::unresolved('URL-001', '/legacy-fixture/a');

        return [
            'wildcard' => [[...$ok, 'source_path' => '/legacy-fixture/*']],
            'pattern' => [[...$ok, 'source_path' => '/legacy-fixture/{slug}']],
            'query' => [[...$ok, 'source_path' => '/legacy-fixture/a?x=1']],
            'fragment' => [[...$ok, 'source_path' => '/legacy-fixture/a#b']],
            'trailing slash' => [[...$ok, 'source_path' => '/legacy-fixture/a/']],
            'uppercase' => [[...$ok, 'source_path' => '/Legacy-Fixture/a']],
            'dot segment' => [[...$ok, 'source_path' => '/legacy-fixture/../admin']],
            'host with scheme' => [[...$ok, 'source_host' => 'https://legacy.example.test']],
            'bad id' => [[...$ok, 'route_id' => 'ROW-1']],
            'unknown disposition' => [[...$ok, 'disposition' => 'temporary']],
            'unresolved without reason' => [['route_id' => 'URL-001', 'source_host' => F::HOST, 'source_path' => '/legacy-fixture/a', 'disposition' => 'unresolved']],
            'unknown key' => [[...$ok, 'target' => ['type' => 'route', 'name' => 'home']]],
            'redirect 302' => [[...F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'route', 'name' => 'home']), 'status' => 302]],
            'redirect 308' => [[...F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'route', 'name' => 'home']), 'status' => 308]],
            'gone 404' => [[...F::gone('URL-001', '/legacy-fixture/a'), 'status' => 404]],
            'route target with parameters' => [F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'route', 'name' => 'tracks.show', 'parameters' => ['slug' => 'x']])],
            'raw path target' => [F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'path', 'path' => '/'])],
            'about with slug' => [F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'editorial', 'section' => 'about', 'slug' => 'x'])],
            'track slug traversal' => [F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'track', 'slug' => '../admin'])],
        ];
    }

    #[DataProvider('malformedMaps')]
    public function test_structurally_malformed_entries_refuse_the_whole_map(array $entry): void
    {
        $this->expectException(LegacyRedirectMapInvalid::class);
        LegacyRedirectMap::fromArray(F::map([$entry]));
    }

    public function test_duplicate_ids_sources_hosts_and_an_unsupported_query_rule_are_refused(): void
    {
        foreach ([
            F::map([F::unresolved('URL-001', '/legacy-fixture/a'), F::unresolved('URL-001', '/legacy-fixture/b')]),
            F::map([F::unresolved('URL-001', '/legacy-fixture/a'), F::unresolved('URL-002', '/legacy-fixture/a')]),
            F::map([F::unresolved('URL-001', '/legacy-fixture/a')], ['cdn.example.test', 'cdn.example.test']),
            F::map([F::unresolved('URL-001', '/legacy-fixture/a')], ['https://cdn.example.test']),
            ['query_string' => 'preserve'] + F::map([F::unresolved('URL-001', '/legacy-fixture/a')]),
            ['version' => 2] + F::map([F::unresolved('URL-001', '/legacy-fixture/a')]),
            F::map([]),
        ] as $index => $map) {
            try {
                LegacyRedirectMap::fromArray($map);
                $this->fail('Map '.$index.' was accepted.');
            } catch (LegacyRedirectMapInvalid $invalid) {
                $this->assertNotSame([], $invalid->problems);
            }
        }
        foreach (['{', '[]', '"x"', str_repeat(' ', 10)] as $json) {
            try {
                LegacyRedirectMap::fromJson($json);
                $this->fail('JSON was accepted: '.$json);
            } catch (LegacyRedirectMapInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_internal_targets_must_resolve_to_existing_get_routes_without_missing_parameters(): void
    {
        $report = $this->validate([
            F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'route', 'name' => 'home']),
            F::redirect('URL-002', '/legacy-fixture/b', ['type' => 'route', 'name' => 'no-such-route']),
            F::redirect('URL-003', '/legacy-fixture/c', ['type' => 'route', 'name' => 'tracks.show']),
            F::redirect('URL-004', '/legacy-fixture/d', ['type' => 'route', 'name' => 'quotes.store']),
            F::redirect('URL-005', '/legacy-fixture/e', ['type' => 'editorial', 'section' => 'about', 'slug' => null]),
        ]);
        $this->assertError($report, 'URL-002 target: route no-such-route does not exist');
        $this->assertError($report, 'URL-003 target: target route needs parameters');
        $this->assertError($report, 'URL-004 target: route quotes.store does not answer GET');
        $this->assertCount(3, $report['errors']);
        $this->assertSame(['/', '/about'], array_column($report['redirects'], 'target'));
        // The default release has no about page: valid map, but the live check warns and the request path answers 404.
        $this->assertSame(['URL-005 target /about is not public right now; requests answer 404 until it is published.'], $report['warnings']);
    }

    public function test_external_targets_need_https_on_the_maps_own_allowlist_and_never_point_back_at_a_legacy_host(): void
    {
        $report = $this->validate([
            F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'external', 'url' => 'https://cdn.example.test/kit']),
            F::redirect('URL-002', '/legacy-fixture/b', ['type' => 'external', 'url' => 'https://attacker.example/phish']),
            F::redirect('URL-003', '/legacy-fixture/c', ['type' => 'external', 'url' => 'http://cdn.example.test/kit']),
            F::redirect('URL-004', '/legacy-fixture/d', ['type' => 'external', 'url' => 'https://user:pass@cdn.example.test/kit']),
            F::redirect('URL-005', '/legacy-fixture/e', ['type' => 'external', 'url' => 'https://cdn.example.test:8443/kit']),
            F::redirect('URL-006', '/legacy-fixture/f', ['type' => 'external', 'url' => 'https://'.F::HOST.'/legacy-fixture/a']),
            F::redirect('URL-007', '/legacy-fixture/g', ['type' => 'external', 'url' => '//attacker.example/x']),
            F::redirect('URL-008', '/legacy-fixture/h', ['type' => 'external', 'url' => 'https://CDN.example.test/kit']),
        ], ['cdn.example.test', F::HOST]);
        $this->assertError($report, 'URL-002 target: external host attacker.example is not on the map\'s external_hosts allowlist (open redirect refused)');
        foreach (['URL-003', 'URL-004', 'URL-005', 'URL-007'] as $id) {
            $this->assertError($report, $id.' target: external target must be a plain https URL');
        }
        $this->assertError($report, 'URL-006 target: external target points back at the legacy host');
        $this->assertError($report, 'URL-008 target: external host CDN.example.test is not on the map');
        $this->assertCount(7, $report['errors']);
        $this->assertSame([['route_id' => 'URL-001', 'source' => '/legacy-fixture/a', 'status' => 301, 'target' => 'https://cdn.example.test/kit']], $report['redirects']);
    }

    public function test_chains_loops_and_shadowed_sources_are_refused_so_every_redirect_is_one_hop(): void
    {
        $selection = QuoteFixtures::selection();
        $slug = $selection['track']->slug;
        $report = $this->validate([
            // A target that is another entry's source is a chain even before route checks.
            F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'track', 'slug' => $slug]),
            F::gone('URL-002', '/tracks/'.$slug),
            // Its own source: a loop.
            F::redirect('URL-003', '/about', ['type' => 'editorial', 'section' => 'about', 'slug' => null]),
            // A real route wins, so a redirect or gone entry on a served path would be dead configuration.
            F::gone('URL-004', '/'),
            F::retained('URL-005', '/legacy-fixture/not-served'),
        ]);
        $this->assertError($report, 'URL-001 target: target /tracks/'.$slug.' is the source of URL-002 (redirect chain)');
        $this->assertError($report, 'URL-002 /tracks/'.$slug.' is served by route tracks.show');
        $this->assertError($report, 'URL-003 target: target is the entry\'s own source (loop)');
        $this->assertError($report, 'URL-003 /about is served by route editorial.show');
        $this->assertError($report, 'URL-004 / is served by route home');
        $this->assertError($report, 'URL-005 is marked retained but no route serves /legacy-fixture/not-served');
        $this->assertCount(6, $report['errors']);
    }

    public function test_content_targets_that_are_drafts_or_unpublished_only_warn_and_never_count_as_public(): void
    {
        $draft = Track::create(['title' => 'Private draft name', 'artist' => 'Private artist', 'slug' => 'private-draft-target']);
        $selection = QuoteFixtures::selection();
        $report = $this->validate([
            F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'track', 'slug' => $draft->slug]),
            F::redirect('URL-002', '/legacy-fixture/b', ['type' => 'track', 'slug' => $selection['track']->slug]),
            F::redirect('URL-003', '/legacy-fixture/c', ['type' => 'editorial', 'section' => 'blog', 'slug' => 'missing-entry']),
        ]);
        $this->assertSame([], $report['errors']);
        $this->assertSame([
            'URL-001 target /tracks/private-draft-target is not public right now; requests answer 404 until it is published.',
            'URL-003 target /blog/missing-entry is not public right now; requests answer 404 until it is published.',
        ], $report['warnings']);
    }

    public function test_the_map_must_match_the_pinned_inventory_row_for_row(): void
    {
        $entries = [F::unresolved('URL-001', '/legacy-fixture/a'), F::unresolved('URL-002', '/legacy-fixture/b')];
        $report = $this->validate($entries, [], [$entries[0], F::unresolved('URL-003', '/legacy-fixture/c')]);
        $this->assertError($report, 'URL-002 is not an inventory row; the map may not invent legacy URLs.');
        $this->assertError($report, 'Inventory row URL-003 has no map entry');
        $report = $this->validate([F::unresolved('URL-001', '/legacy-fixture/moved')], [], [F::unresolved('URL-001', '/legacy-fixture/a')]);
        $this->assertError($report, 'URL-001 source legacy.example.test/legacy-fixture/moved differs from the inventory (legacy.example.test/legacy-fixture/a).');

        $files = F::write($this->directory, [F::unresolved('URL-001', '/legacy-fixture/a')]);
        file_put_contents($files['inventory'], file_get_contents($files['inventory']).'URL-002,'.F::HOST.',/legacy-fixture/b,/,SRC,R,v,c,p'."\n");
        $report = app(LegacyRedirectValidator::class)->validate(LegacyRedirectMap::fromFile($files['map']), LegacyRouteInventory::read($files['inventory']));
        $this->assertError($report, 'Inventory changed: the map is pinned to sha256');
        $this->assertError($report, 'The map pins 1 inventory rows; the inventory has 2.');
    }
}
