<?php

namespace Tests\Unit;

use App\Domain\Migration\CatalogDryRun;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogDryRunTest extends TestCase
{
    private function input(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/migration/'.$name.'.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    private function plan(?array $manifest = null, ?array $target = null): array
    {
        return (new CatalogDryRun)->plan(CanonicalJson::encode($manifest ?? $this->input('synthetic-catalog')),
            CanonicalJson::encode($target ?? $this->input('synthetic-target')));
    }

    public function test_deterministic_source_mapped_plan_retains_visibility_without_publication_or_private_values(): void
    {
        $plan = $this->plan();
        $this->assertSame($plan, $this->plan());
        $this->assertSame(['create_draft' => 2, 'skip' => 1, 'conflict' => 0], $plan['counts']);
        $this->assertSame(3, $plan['total']);
        $this->assertSame(0, $plan['production_writes']);
        $this->assertSame(CanonicalJson::VERSION, $plan['canonicalization_version']);
        $this->assertSame(['synthetic-alpha', 'synthetic-beta', 'synthetic-gamma'], array_column($plan['entries'], 'source_id'));
        $this->assertSame(['public', 'draft', 'private'], array_column($plan['entries'], 'source_visibility'));
        $this->assertSame(['draft', null, 'draft'], array_column($plan['entries'], 'proposed_visibility'));
        $this->assertSame([['title', 'slug', 'visibility'], [], ['title', 'slug', 'visibility']], array_column($plan['entries'], 'field_changes'));
        foreach ($plan['entries'] as $entry) {
            $this->assertArrayNotHasKey('title', $entry);
            $this->assertArrayNotHasKey('value', $entry);
        }
        $this->assertStringNotContainsString('SYNTHETIC alpha', CanonicalJson::encode($plan));
        $manifest = $this->input('synthetic-catalog');
        $manifest['records'] = array_reverse($manifest['records']);
        $reordered = $this->plan($manifest);
        $this->assertSame($plan['entries'], $reordered['entries']);
        $this->assertNotSame($plan['input_manifest_sha256'], $reordered['input_manifest_sha256']);
        $this->assertNotSame($plan['plan_sha256'], $reordered['plan_sha256']);
    }

    public function test_restart_and_completed_replay_produce_exact_uninterrupted_checkpoint(): void
    {
        $planner = new CatalogDryRun;
        $plan = $this->plan();
        $first = $planner->checkpoint($plan, null, 1);
        $this->assertFalse($first['complete']);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(1, array_sum($first['counts']));
        $second = $planner->checkpoint($plan, $first, 1);
        $this->assertSame(2, $second['processed']);
        $final = $planner->checkpoint($plan, $second, 1);
        $this->assertTrue($final['complete']);
        $this->assertSame($planner->checkpoint($plan, null, 1000), $final);
        $this->assertSame($final, $planner->checkpoint($plan, $final, 1000));
        $this->assertSame($plan['total'], array_sum($final['counts']));
        $empty = $this->input('synthetic-catalog');
        $empty['records'] = [];
        $this->assertTrue($planner->checkpoint($this->plan($empty), null, 1)['complete']);
    }

    public static function conflicts(): array
    {
        return ['source drift' => ['source', 'source_changed'], 'target drift' => ['target', 'target_changed'],
            'missing mapped target' => ['missing', 'mapped_target_missing'], 'new transform' => ['transform', 'transform_changed'],
            'duplicate source ID' => ['duplicate', 'duplicate_source_identity'], 'two proposed slugs' => ['planned', 'planned_slug_collision'],
            'occupied slug' => ['occupied', 'target_slug_collision'], 'sold source' => ['sold', 'sold_state_requires_disposition'],
            'unknown state' => ['unknown', 'unknown_visibility'], 'foreign mapping namespace' => ['namespace', 'target_slug_collision'],
            'changed approved mapping' => ['projection', 'mapped_projection_conflict']];
    }

    #[DataProvider('conflicts')]
    public function test_conflicts_are_explicit_and_never_become_updates_or_creates(string $case, string $reason): void
    {
        $manifest = $this->input('synthetic-catalog');
        $target = $this->input('synthetic-target');
        switch ($case) {
            case 'source':
                $manifest['records'][1]['value']['title'] = 'SYNTHETIC changed source';
                $manifest['records'][1]['source_sha256'] = CanonicalJson::hash($manifest['records'][1]['value']);
                break;
            case 'target':
            case 'projection':
                $target['tracks'][0]['title'] = 'SYNTHETIC changed target';
                if ($case === 'projection') {
                    $target['mappings'][0]['target_sha256'] = CanonicalJson::hash($target['tracks'][0]);
                }
                break;
            case 'missing': $target['tracks'] = [];
                break;
            case 'transform': $target['mappings'][0]['transform_version'] = 'another-transform-v1';
                break;
            case 'duplicate': $manifest['records'][] = $manifest['records'][0];
                break;
            case 'planned':
                $manifest['records'][2]['value']['slug'] = 'synthetic-alpha';
                $manifest['records'][2]['source_sha256'] = CanonicalJson::hash($manifest['records'][2]['value']);
                break;
            case 'occupied':
                $target['tracks'][] = ['target_id' => 'synthetic-occupied', 'title' => 'SYNTHETIC occupied', 'slug' => 'synthetic-alpha', 'visibility' => 'draft'];
                break;
            case 'sold':
            case 'unknown':
                $manifest['records'][0]['value']['visibility'] = $case;
                $manifest['records'][0]['source_sha256'] = CanonicalJson::hash($manifest['records'][0]['value']);
                break;
            case 'namespace': $target['mappings'][0]['source_system'] = 'synthetic-other-source';
                break;
        }
        $before = CanonicalJson::encode([$manifest, $target]);
        $plan = $this->plan($manifest, $target);
        $matching = array_filter($plan['entries'], fn ($entry) => in_array($reason, $entry['reasons'], true));
        $this->assertNotEmpty($matching);
        foreach ($matching as $entry) {
            $this->assertSame('conflict', $entry['result']);
            $this->assertNull($entry['candidate_reference']);
            $this->assertNull($entry['proposed_visibility']);
            $this->assertSame([], $entry['field_changes']);
        }
        $this->assertSame($plan['total'], array_sum($plan['counts']));
        $this->assertSame(0, $plan['production_writes']);
        $this->assertSame($before, CanonicalJson::encode([$manifest, $target]));
    }

    public static function invalidInputs(): array
    {
        return array_map(fn ($case) => [$case], ['duplicate-key', 'duplicate-nested-key', 'float', 'overflow', 'string-version',
            'numeric-id', 'unknown-field', 'unknown-nested-field', 'object-instead-of-list', 'list-instead-of-object',
            'wrong-digest', 'private-namespace', 'private-title', 'wrong-fixture', 'non-utc-time', 'invalid-date',
            'acquired-null-byte', 'source-null-byte', 'future-watermark', 'unsupported-visibility', 'too-many-records', 'mapping-collision', 'target-id-collision']);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_ambiguous_input_is_refused_before_planning(string $case): void
    {
        $manifest = $this->input('synthetic-catalog');
        $target = $this->input('synthetic-target');
        $raw = null;
        switch ($case) {
            case 'duplicate-key': $raw = str_replace('"schema_version":1', '"schema_version":1,"schema_version":1', CanonicalJson::encode($manifest));
                break;
            case 'duplicate-nested-key': $raw = str_replace('"title":"SYNTHETIC alpha"', '"title":"SYNTHETIC alpha","title":"SYNTHETIC alpha"', CanonicalJson::encode($manifest));
                break;
            case 'float': $raw = str_replace('"schema_version":1', '"schema_version":1.0', CanonicalJson::encode($manifest));
                break;
            case 'overflow': $raw = str_replace('"schema_version":1', '"schema_version":999999999999999999999999', CanonicalJson::encode($manifest));
                break;
            case 'string-version': $manifest['schema_version'] = '1';
                break;
            case 'numeric-id': $manifest['records'][0]['source_id'] = 1;
                break;
            case 'unknown-field': $manifest['commit'] = true;
                break;
            case 'unknown-nested-field': $manifest['records'][0]['value']['rights'] = 'approved';
                break;
            case 'object-instead-of-list': $manifest['records'] = (object) [];
                break;
            case 'list-instead-of-object': $manifest['records'][0]['value'] = [];
                break;
            case 'wrong-digest': $manifest['records'][0]['source_sha256'] = str_repeat('0', 64);
                break;
            case 'private-namespace': $manifest['source_system'] = 'actual-account';
                break;
            case 'private-title': $manifest['records'][0]['value']['title'] = 'PRIVATE SENTINEL';
                break;
            case 'wrong-fixture': $manifest['fixture'] = 'live';
                break;
            case 'non-utc-time': $manifest['acquired_at'] = '2026-10-06T00:00:00+02:00';
                break;
            case 'invalid-date': $manifest['acquired_at'] = '2026-02-30T00:00:00Z';
                break;
            case 'acquired-null-byte': $manifest['acquired_at'] = "2026-10-06T00:00:00Z\0";
                break;
            case 'source-null-byte': $manifest['source_as_of'] = "2026-10-06T00:00:00Z\0";
                break;
            case 'future-watermark': $manifest['source_as_of'] = '2027-01-01T00:00:00Z';
                break;
            case 'unsupported-visibility': $manifest['records'][0]['value']['visibility'] = 'enabled';
                break;
            case 'too-many-records': $manifest['records'] = array_fill(0, 1001, $manifest['records'][0]);
                break;
            case 'mapping-collision': $target['mappings'][] = $target['mappings'][0];
                break;
            case 'target-id-collision': $target['tracks'][] = $target['tracks'][0];
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        (new CatalogDryRun)->plan($raw ?? CanonicalJson::encode($manifest), CanonicalJson::encode($target));
    }

    public static function alteredCheckpoints(): array
    {
        return array_map(fn ($case) => [$case], ['count', 'cursor-gap', 'cursor-type', 'entry', 'order', 'field', 'input', 'target', 'mapping', 'zero-limit', 'oversized-limit']);
    }

    #[DataProvider('alteredCheckpoints')]
    public function test_resume_refuses_changed_binding_or_unverified_prefix(string $case): void
    {
        $planner = new CatalogDryRun;
        $plan = $this->plan();
        $checkpoint = $planner->checkpoint($plan, null, 2);
        $limit = 1;
        switch ($case) {
            case 'count': $checkpoint['counts']['skip'] = 99;
                break;
            case 'cursor-gap': $checkpoint['processed'] = 3;
                break;
            case 'cursor-type': $checkpoint['processed'] = '2';
                break;
            case 'entry': $checkpoint['entries'][0]['source_sha256'] = str_repeat('0', 64);
                break;
            case 'order': $checkpoint['entries'] = array_reverse($checkpoint['entries']);
                break;
            case 'field': $checkpoint['approved'] = true;
                break;
            case 'input':
                $manifest = $this->input('synthetic-catalog');
                $manifest['batch_id'] = 'synthetic-new-batch';
                $plan = $this->plan($manifest);
                break;
            case 'target':
            case 'mapping':
                $target = $this->input('synthetic-target');
                if ($case === 'target') {
                    $target['snapshot_id'] = 'synthetic-new-target';
                } else {
                    $target['mappings'][0]['source_system'] = 'synthetic-other';
                }
                $plan = $this->plan(target: $target);
                break;
            case 'zero-limit': $limit = 0;
                break;
            case 'oversized-limit': $limit = 1001;
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        $planner->checkpoint($plan, $checkpoint, $limit);
    }
}
