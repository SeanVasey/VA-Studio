<?php

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// No application route loads this helper. Preparation creates private synthetic drafts only;
// verification is read-only and every edit must come from the real operator command/action.
try {
    require_once __DIR__.'/unpaid-release-fixture.php';
    UnpaidReleaseBrowserFixture::check(PHP_SAPI === 'cli');
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    $phase = $argv[3] ?? null;
    UnpaidReleaseBrowserFixture::check(in_array($mode, ['prepare', 'verify'], true)
        && array_key_exists($project ?? '', UnpaidReleaseBrowserFixture::PROJECTS)
        && count($argv) === ($mode === 'prepare' ? 3 : 4)
        && ($mode === 'prepare' || in_array($phase, ['prepared', 'winner', 'recovered', 'uncertain'], true)));
    $directory = UnpaidReleaseBrowserFixture::directory();
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    UnpaidReleaseBrowserFixture::effective($directory);
    $path = $directory.'/bulk-license-source-'.$project.'.json';
    UnpaidReleaseBrowserFixture::check(! is_link($path));
    $prefix = 'NONBINDING SYNTHETIC BULK LICENSE '.$project.'. No saleable rights.';
    $sources = ['prepared' => [$prefix.' First draft.', $prefix.' Second draft.'],
        'winner' => $prefix.' Saved in a separate editor.',
        'recovered' => $prefix.' Explicitly reviewed replacement.',
        'uncertain' => $prefix.' Saved before a deliberately lost response.'];
    if ($mode === 'prepare') {
        UnpaidReleaseBrowserFixture::check(! file_exists($path));
        $before = bulkLicenseSourceRows();
        $guards = bulkLicenseSourceGuards();
        $actor = User::findOrFail(1);
        $template = app(SaveLicenseTemplate::class)->create(['name' => 'Synthetic bulk draft '.$project,
            'slug' => 'synthetic-bulk-draft-'.$project, 'type' => 'non-exclusive'], $actor);
        $versions = [];
        foreach ([$sources['prepared'][0], $sources['prepared'][1], $prefix.' Unselected retained draft.'] as $source) {
            $versions[] = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => $source,
                'structured_terms' => ['schema_version' => 1, 'features' => ['Nonbinding native verification only'],
                    'required_asset_roles' => ['master_wav']]], $actor)->fresh();
        }
        $after = bulkLicenseSourceRows();
        foreach ($before as $table => $records) {
            foreach ($records as $key => $record) {
                UnpaidReleaseBrowserFixture::check(($after[$table][$key] ?? null) === $record);
            }
        }
        UnpaidReleaseBrowserFixture::check(bulkLicenseSourceGuards() === $guards);
        $fixture = ['purpose' => 'bulk-license-source-native-v1', 'project' => $project,
            'marker' => getenv('VASEY_BROWSER_EXCEPTION_MARKER'), 'database' => $directory.'/database.sqlite',
            'operatorId' => 1, 'templateId' => (int) $template->id, 'name' => $template->name,
            'versionIds' => [(int) $versions[0]->id, (int) $versions[1]->id],
            'versionNumbers' => [(int) $versions[0]->version, (int) $versions[1]->version],
            'unselectedId' => (int) $versions[2]->id, 'sources' => $sources, 'rows' => $after, 'guards' => $guards];
        UnpaidReleaseBrowserFixture::write($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        echo json_encode(array_intersect_key($fixture, array_flip(['name', 'versionIds', 'versionNumbers', 'unselectedId', 'sources'])), JSON_THROW_ON_ERROR)."\n";
    } else {
        $fixture = UnpaidReleaseBrowserFixture::json($path, 8388608);
        UnpaidReleaseBrowserFixture::check(($fixture['purpose'] ?? null) === 'bulk-license-source-native-v1'
            && ($fixture['project'] ?? null) === $project && ($fixture['marker'] ?? null) === getenv('VASEY_BROWSER_EXCEPTION_MARKER')
            && ($fixture['database'] ?? null) === $directory.'/database.sqlite' && ($fixture['operatorId'] ?? null) === 1
            && is_int($fixture['templateId'] ?? null) && is_int($fixture['unselectedId'] ?? null)
            && is_array($fixture['versionIds'] ?? null) && array_is_list($fixture['versionIds'])
            && count($fixture['versionIds']) === 2 && is_int($fixture['versionIds'][0]) && is_int($fixture['versionIds'][1])
            && $fixture['versionIds'][0] < $fixture['versionIds'][1]
            && $fixture['unselectedId'] > $fixture['versionIds'][1] && ($fixture['sources'] ?? null) === $sources
            && is_array($fixture['rows'] ?? null));
        $actual = bulkLicenseSourceRows();
        $original = $fixture['rows'];
        $expectedCount = ['prepared' => 0, 'winner' => 1, 'recovered' => 3, 'uncertain' => 5][$phase];
        $contents = [];
        $actualSources = [];
        foreach ($fixture['versionIds'] as $index => $id) {
            $version = LicenseVersion::findOrFail($id);
            $source = match ($phase) {
                'prepared' => $sources['prepared'][$index],
                'winner' => $index === 0 ? $sources['winner'] : $sources['prepared'][1],
                default => $sources[$phase],
            };
            UnpaidReleaseBrowserFixture::check($version->license_template_id === $fixture['templateId']
                && $version->status === 'draft' && $version->published_at === null && $version->authored_source === $source);
            $saved = json_decode($actual['license_versions'][(string) $id], true, 64, JSON_THROW_ON_ERROR);
            $previous = json_decode($original['license_versions'][(string) $id], true, 64, JSON_THROW_ON_ERROR);
            foreach ($previous as $field => $value) {
                if (in_array($field, ['authored_source', 'updated_at'], true)) {
                    continue;
                }
                UnpaidReleaseBrowserFixture::check(($saved[$field] ?? null) === $value);
            }
            // No-op phases must retain the complete row, including its timestamp.
            if ($phase === 'prepared' || ($phase === 'winner' && $index === 1)) {
                UnpaidReleaseBrowserFixture::check($actual['license_versions'][(string) $id] === $original['license_versions'][(string) $id]);
            }
            $contents[$id] = ['authored_source' => $sources['prepared'][$index], 'structured_terms' => $version->structured_terms,
                'effective_from' => $version->effective_from?->utc()->format('Y-m-d H:i:s'),
                'effective_until' => $version->effective_until?->utc()->format('Y-m-d H:i:s')];
            $actualSources[] = $source;
            unset($actual['license_versions'][(string) $id], $original['license_versions'][(string) $id]);
        }
        $added = array_diff_key($actual['audit_events'], $original['audit_events']);
        UnpaidReleaseBrowserFixture::check(count($added) === $expectedCount);
        $sequence = [];
        if ($expectedCount > 0) {
            $sequence[] = [$fixture['versionIds'][0], 'rights.license.draft_updated', $sources['winner']];
        }
        foreach (['recovered' => 3, 'uncertain' => 5] as $batchPhase => $threshold) {
            if ($expectedCount >= $threshold) {
                foreach ($fixture['versionIds'] as $id) {
                    $sequence[] = [$id, 'rights.license.draft_source_bulk_updated', $sources[$batchPhase]];
                }
            }
        }
        $auditIds = [];
        $batchHashes = [];
        foreach (array_values($added) as $index => $encoded) {
            $audit = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
            [$id, $action, $source] = $sequence[$index];
            UnpaidReleaseBrowserFixture::check($audit['action'] === $action && $audit['subject_type'] === LicenseVersion::class
                && (int) $audit['subject_id'] === $id && (int) $audit['actor_id'] === 1);
            $context = json_decode($audit['context'], true, 32, JSON_THROW_ON_ERROR);
            $after = [...$contents[$id], 'authored_source' => $source];
            $auditBefore = $action === 'rights.license.draft_updated'
                ? ['license_template_id' => $fixture['templateId'], ...$contents[$id]] : $contents[$id];
            $auditAfter = $action === 'rights.license.draft_updated'
                ? ['license_template_id' => $fixture['templateId'], ...$after] : $after;
            UnpaidReleaseBrowserFixture::check($context['changed_fields'] === ['authored_source']
                && $context['canonicalization_version'] === CanonicalJson::VERSION
                && $context['before_hash'] === CanonicalJson::hash($auditBefore)
                && $context['after_hash'] === CanonicalJson::hash($auditAfter));
            $keys = array_keys($context);
            sort($keys);
            if ($action === 'rights.license.draft_source_bulk_updated') {
                UnpaidReleaseBrowserFixture::check($keys === ['after_hash', 'batch_review_hash', 'before_hash', 'canonicalization_version', 'changed_fields', 'schema_version']
                    && $context['schema_version'] === 1 && preg_match('/\A[a-f0-9]{64}\z/D', $context['batch_review_hash']) === 1);
                $batchHashes[] = $context['batch_review_hash'];
            } else {
                UnpaidReleaseBrowserFixture::check($keys === ['after_hash', 'before_hash', 'canonicalization_version', 'changed_fields', 'schema_version']
                    && $context['schema_version'] === 1);
            }
            $contents[$id] = $after;
            $auditIds[] = (int) $audit['id'];
        }
        UnpaidReleaseBrowserFixture::check(count(array_unique($auditIds)) === $expectedCount);
        if ($expectedCount >= 3) {
            UnpaidReleaseBrowserFixture::check($batchHashes[0] === $batchHashes[1]);
        }
        if ($expectedCount === 5) {
            UnpaidReleaseBrowserFixture::check($batchHashes[2] === $batchHashes[3] && $batchHashes[0] !== $batchHashes[2]);
        }
        foreach (array_keys($added) as $id) {
            unset($actual['audit_events'][$id]);
        }
        UnpaidReleaseBrowserFixture::check($actual === $original && bulkLicenseSourceGuards() === $fixture['guards']);
        echo json_encode(['verified' => true, 'phase' => $phase, 'versionIds' => $fixture['versionIds'],
            'sources' => $actualSources, 'updates' => $expectedCount, 'auditIds' => $auditIds,
            'batchHashes' => $batchHashes, 'originalsUnchanged' => true, 'guardsUnchanged' => true], JSON_THROW_ON_ERROR)."\n";
    }
} catch (Throwable) {
    // Fixed category only: no SQL, private prose, credentials, paths or exception messages.
    fwrite(STDERR, "Isolated bulk-license source evidence refused.\n");
    exit(1);
}

function bulkLicenseSourceRows(): array
{
    $result = [];
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
        $result[$table->name] = [];
        foreach (DB::table($table->name)->get() as $record) {
            $encoded = json_encode((array) $record, JSON_THROW_ON_ERROR);
            $key = isset($record->id) ? (string) $record->id : hash('sha256', $encoded);
            $result[$table->name][$key] = $encoded;
        }
        ksort($result[$table->name]);
    }

    return $result;
}

function bulkLicenseSourceGuards(): string
{
    return hash('sha256', json_encode(DB::select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE type IN ('trigger', 'index') ORDER BY type, name"), JSON_THROW_ON_ERROR));
}
