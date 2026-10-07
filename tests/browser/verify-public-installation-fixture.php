<?php

use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

// Read-only database evidence for one UI-created draft, restricted to the ordinary disposable wrapper.
// Raw synthetic rows stay in its private temporary directory; only hashes and fixed receipts leave it.
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    $marker = getenv('VASEY_BROWSER_INQUIRY_MARKER');
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || ! is_string($directory) || is_link($directory)
        || realpath($directory) !== $directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
        || preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory)) !== 1
        || (fileperms($directory) & 0777) !== 0700
        || getenv('APP_ENV') !== 'local' || getenv('APP_URL') !== 'http://127.0.0.1:8173'
        || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_URL') !== ''
        || getenv('DB_DATABASE') !== $directory.'/database.sqlite'
        || realpath($directory.'/database.sqlite') !== $directory.'/database.sqlite' || is_link($directory.'/database.sqlite')
        || ! is_file($directory.'/database.sqlite') || filesize($directory.'/database.sqlite') === 0
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || file_exists($directory.'/config.php')
        || getenv('APP_ROUTES_CACHE') !== $directory.'/routes.php' || getenv('APP_EVENTS_CACHE') !== $directory.'/events.php'
        || file_exists($directory.'/routes.php') || file_exists($directory.'/events.php')
        || ! is_string($marker) || preg_match('/\A[a-f0-9]{64}\z/D', $marker) !== 1
        || ! in_array($mode, ['before', 'after-setup', 'verify'], true)
        || ! in_array($project, ['chromium-desktop', 'webkit-mobile'], true)) {
        throw new RuntimeException('Not an isolated installation browser fixture.');
    }
    foreach (['fixtures.json', 'inquiry-fixture-marker.json'] as $file) {
        if (! is_file($directory.'/'.$file) || is_link($directory.'/'.$file) || realpath($directory.'/'.$file) !== $directory.'/'.$file) {
            throw new RuntimeException('Missing ordinary browser fixture marker.');
        }
    }
    $fixtures = json_decode(file_get_contents($directory.'/fixtures.json'), true, 16, JSON_THROW_ON_ERROR);
    $identity = json_decode(file_get_contents($directory.'/inquiry-fixture-marker.json'), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($fixtures) || array_keys($fixtures) !== ['chromium-desktop', 'webkit-mobile']
        || $identity !== ['marker' => $marker, 'database' => $directory.'/database.sqlite', 'origin' => 'http://127.0.0.1:8173', 'operatorId' => 1]) {
        throw new RuntimeException('Ordinary fixture identity mismatch.');
    }
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || config('app.url') !== 'http://127.0.0.1:8173'
        || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
        || storage_path() !== $directory || config('filesystems.disks.local.root') !== $directory.'/app/private'
        || config('mail.default') !== 'array' || config('payments.stripe.webhook_enabled') !== false
        || config('payments.stripe.checkout_enabled') !== false || config('payments.stripe.processing_enabled') !== false
        || config('payments.stripe.finalization_enabled') !== false || config('payments.stripe.secret_key') !== ''
        || config('payments.stripe.webhook_secret') !== '') {
        throw new RuntimeException('Effective fixture paths or provider isolation changed.');
    }
    // The helper cannot repair or prepare application data, even accidentally inside a called service.
    DB::statement('PRAGMA query_only = ON');
    $operator = User::findOrFail(1);
    if ($operator->email !== 'browser-operator@example.test' || $operator->email_verified_at === null
        || ! Gate::forUser($operator)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($operator)) {
        throw new RuntimeException('Original persisted operator is not authorized.');
    }
    $snapshot = static function (): array {
        return DB::transaction(static function (): array {
            $schema = array_map(static fn (object $row): array => (array) $row,
                DB::select("SELECT type, name, tbl_name, sql FROM main.sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"));
            $rows = [];
            foreach ($schema as $object) {
                if ($object['type'] !== 'table') {
                    continue;
                }
                $table = $object['name'];
                $rows[$table] = DB::table($table)->get()->map(static function (object $row): array {
                    $value = (array) $row;
                    ksort($value, SORT_STRING);

                    return $value;
                })->all();
                usort($rows[$table], static fn (array $left, array $right): int => strcmp(
                    json_encode($left, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    json_encode($right, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
            }
            ksort($rows, SORT_STRING);

            return ['schema' => $schema, 'rows' => $rows];
        });
    };
    $hash = static fn (array $value): string => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $path = $directory.'/installation-'.$project.'.json';
    $write = static function (array $record) use ($path): void {
        umask(0077);
        $stream = fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Private fixture evidence already exists.');
        }
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (! chmod($path, 0600) || fwrite($stream, $json) !== strlen($json) || ! fflush($stream)) {
                throw new RuntimeException('Unable to retain private fixture evidence.');
            }
        } finally {
            fclose($stream);
        }
    };
    $label = 'Synthetic installation preview '.$project;
    if ($mode === 'before') {
        if (file_exists($path) || is_link($path) || SiteRelease::where('label', $label)->exists()) {
            throw new RuntimeException('Installation fixture was already prepared.');
        }
        $before = $snapshot();
        $expectedContent = SiteContentSchema::forEditing(app(SiteContent::class)->current());
        if (CanonicalJson::hash($expectedContent) !== CanonicalJson::hash(SiteContentSchema::forEditing(SiteContentSchema::defaults()))) {
            throw new RuntimeException('The ordinary publication is not the original default site.');
        }
        $write(['marker' => $marker, 'project' => $project, 'phase' => 'before', 'before' => $before, 'expectedContent' => $expectedContent]);
        $receipt = ['phase' => 'before', 'snapshotHash' => $hash($before)];
    } else {
        if (! is_file($path) || is_link($path) || realpath($path) !== $path || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Missing private fixture evidence.');
        }
        $record = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (($record['marker'] ?? null) !== $marker || ($record['project'] ?? null) !== $project) {
            throw new RuntimeException('Private fixture evidence identity changed.');
        }
        $current = $snapshot();
        if ($mode === 'after-setup') {
            if ($record['phase'] !== 'before' || $current['schema'] !== $record['before']['schema']) {
                throw new RuntimeException('Unexpected setup phase or schema mutation.');
            }
            $release = SiteRelease::where('label', $label)->sole();
            $content = app(SiteContent::class)->preview($release->id, $operator);
            if ($release->created_by !== $operator->id || $release->schema_version !== 2
                || CanonicalJson::hash($content) !== CanonicalJson::hash($record['expectedContent']) || $release->content_hash !== CanonicalJson::hash($content)
                || $release->canonicalization_version !== CanonicalJson::VERSION) {
                throw new RuntimeException('The UI draft is not the exact default immutable snapshot.');
            }
            $before = $record['before']['rows'];
            $remaining = $current['rows'];
            $addedRelease = array_values(array_filter($remaining['site_releases'], static fn (array $row): bool => $row['id'] === $release->id));
            $addedAudit = array_values(array_filter($remaining['audit_events'], static fn (array $row): bool => $row['action'] === 'site.release.created'
                && $row['subject_type'] === SiteRelease::class && $row['subject_id'] === $release->id));
            if (count($addedRelease) !== 1 || count($addedAudit) !== 1 || $addedAudit[0]['actor_id'] !== $operator->id
                || json_decode($addedAudit[0]['context'], true, 16, JSON_THROW_ON_ERROR) !== ['content_hash' => $release->content_hash, 'schema_version' => 2]) {
                throw new RuntimeException('The precise draft and receipt audit were not retained.');
            }
            $remaining['site_releases'] = array_values(array_filter($remaining['site_releases'], static fn (array $row): bool => $row['id'] !== $release->id));
            $remaining['audit_events'] = array_values(array_filter($remaining['audit_events'], static fn (array $row): bool => $row['id'] !== $addedAudit[0]['id']));
            if ($remaining !== $before) {
                throw new RuntimeException('Setup changed data beyond one private draft and its audit.');
            }
            $receipt = ['phase' => 'after-setup', 'onlyPrivateDraftAndAuditAdded' => true, 'releaseId' => $release->id,
                'contentHash' => $release->content_hash, 'snapshotHash' => $hash($current)];
            // Replace only the helper's private evidence file; no application database write is available.
            $record['phase'] = 'after-setup';
            $record['afterSetup'] = $current;
            $record['receipt'] = $receipt;
            if (file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), LOCK_EX) === false) {
                throw new RuntimeException('Unable to retain the post-setup evidence.');
            }
        } else {
            if ($record['phase'] !== 'after-setup' || $current !== $record['afterSetup']) {
                throw new RuntimeException('The journey changed retained rows or schema.');
            }
            $receipt = array_replace($record['receipt'], ['phase' => 'verify', 'journeyRowsAndSchemaUnchanged' => true]);
        }
    }
    echo json_encode($receipt, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "Refusing installation evidence outside its exact disposable read-only fixture.\n");
    exit(1);
}
