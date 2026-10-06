<?php

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// No application route loads this file. All writes require the existing private disposable harness.
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
    $path = $directory.'/license-draft-'.$project.'.json';
    UnpaidReleaseBrowserFixture::check(! is_link($path));
    $source = 'NONBINDING SYNTHETIC LICENSE DRAFT '.$project.'. No saleable rights.';
    $sources = ['prepared' => $source, 'winner' => $source.' Saved in the second tab.',
        'recovered' => $source.' Reviewed after reopening.', 'uncertain' => $source.' Saved before a deliberately lost response.'];
    if ($mode === 'prepare') {
        UnpaidReleaseBrowserFixture::check(! file_exists($path));
        $before = rows();
        $guards = guards();
        $actor = User::findOrFail(1);
        $template = app(SaveLicenseTemplate::class)->create(['name' => 'Synthetic editable draft '.$project,
            'slug' => 'synthetic-editable-draft-'.$project, 'type' => 'non-exclusive'], $actor);
        $draft = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => $source,
            'structured_terms' => ['schema_version' => 1, 'features' => ['Nonbinding browser verification only'],
                'required_asset_roles' => ['master_wav']]], $actor);
        $after = rows();
        foreach ($before as $table => $records) {
            foreach ($records as $key => $record) {
                UnpaidReleaseBrowserFixture::check(($after[$table][$key] ?? null) === $record);
            }
        }
        UnpaidReleaseBrowserFixture::check(guards() === $guards);
        $fixture = ['purpose' => 'license-draft-native-v1', 'project' => $project,
            'marker' => getenv('VASEY_BROWSER_EXCEPTION_MARKER'), 'database' => $directory.'/database.sqlite', 'operatorId' => 1,
            'templateId' => $template->id, 'versionId' => $draft->id, 'name' => $template->name,
            'sources' => $sources, 'rows' => $after, 'guards' => $guards];
        $encoded = json_encode($fixture, JSON_THROW_ON_ERROR);
        $file = fopen($path, 'x');
        UnpaidReleaseBrowserFixture::check(is_resource($file));
        chmod($path, 0600);
        UnpaidReleaseBrowserFixture::check(fwrite($file, $encoded) === strlen($encoded));
        fclose($file);
        echo json_encode(['name' => $template->name, 'versionId' => $draft->id, 'sources' => $sources], JSON_THROW_ON_ERROR)."\n";
    } else {
        $fixture = UnpaidReleaseBrowserFixture::json($path, 8388608);
        UnpaidReleaseBrowserFixture::check(($fixture['purpose'] ?? null) === 'license-draft-native-v1'
            && ($fixture['project'] ?? null) === $project && ($fixture['marker'] ?? null) === getenv('VASEY_BROWSER_EXCEPTION_MARKER')
            && ($fixture['database'] ?? null) === $directory.'/database.sqlite' && ($fixture['operatorId'] ?? null) === 1
            && is_int($fixture['versionId'] ?? null) && is_int($fixture['templateId'] ?? null)
            && ($fixture['sources'] ?? null) === $sources && is_array($fixture['rows'] ?? null));
        $actual = rows();
        $original = $fixture['rows'];
        $versionId = (string) $fixture['versionId'];
        $draft = LicenseVersion::findOrFail($fixture['versionId']);
        $expectedCount = ['prepared' => 0, 'winner' => 1, 'recovered' => 2, 'uncertain' => 3][$phase];
        UnpaidReleaseBrowserFixture::check($draft->license_template_id === $fixture['templateId'] && $draft->status === 'draft'
            && $draft->published_at === null && $draft->authored_source === $sources[$phase]);
        $version = json_decode($actual['license_versions'][$versionId], true, 64, JSON_THROW_ON_ERROR);
        $previous = json_decode($original['license_versions'][$versionId], true, 64, JSON_THROW_ON_ERROR);
        foreach ($previous as $field => $value) {
            if (in_array($field, ['authored_source', 'updated_at'], true)) {
                continue;
            }
            UnpaidReleaseBrowserFixture::check(($version[$field] ?? null) === $value);
        }
        unset($actual['license_versions'][$versionId], $original['license_versions'][$versionId]);
        $added = array_diff_key($actual['audit_events'], $original['audit_events']);
        UnpaidReleaseBrowserFixture::check(count($added) === $expectedCount);
        foreach ($added as $id => $encoded) {
            $audit = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
            UnpaidReleaseBrowserFixture::check($audit['action'] === 'rights.license.draft_updated'
                && $audit['subject_type'] === LicenseVersion::class && (int) $audit['subject_id'] === $draft->id
                && (int) $audit['actor_id'] === 1);
            $context = json_decode($audit['context'], true, 32, JSON_THROW_ON_ERROR);
            UnpaidReleaseBrowserFixture::check($context['changed_fields'] === ['authored_source']);
            unset($actual['audit_events'][$id]);
        }
        UnpaidReleaseBrowserFixture::check($actual === $original && guards() === $fixture['guards']);
        echo json_encode(['verified' => true, 'phase' => $phase, 'versionId' => $draft->id,
            'source' => $draft->authored_source, 'updates' => $expectedCount, 'originalsUnchanged' => true,
            'guardsUnchanged' => true], JSON_THROW_ON_ERROR)."\n";
    }
} catch (Throwable $error) {
    // Fixed category only; no SQL, private contents, paths or arbitrary exception text.
    $category = $error instanceof ValidationException ? 'validation'
        : ($error instanceof AuthorizationException ? 'authorization' : 'guard-or-evidence');
    fwrite(STDERR, 'Isolated license-draft fixture refused ('.$category.").\n");
    exit(1);
}

function rows(): array
{
    $result = [];
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
        $records = DB::table($table->name)->get()->all();
        $result[$table->name] = [];
        foreach ($records as $record) {
            $encoded = json_encode((array) $record, JSON_THROW_ON_ERROR);
            $key = isset($record->id) ? (string) $record->id : hash('sha256', $encoded);
            $result[$table->name][$key] = $encoded;
        }
        ksort($result[$table->name]);
    }

    return $result;
}

function guards(): string
{
    return hash('sha256', json_encode(DB::select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE type IN ('trigger', 'index') ORDER BY type, name"), JSON_THROW_ON_ERROR));
}
