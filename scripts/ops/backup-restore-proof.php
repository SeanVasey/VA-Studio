<?php

declare(strict_types=1);

// SYNTHETIC backup/restore proof. Generates its own synthetic SQLite database and private-storage
// sample tree, backs them up with a SHA-256 manifest, restores into an isolated directory and
// verifies byte equality and manifest agreement. It never reads application configuration,
// production data, credentials or the network, and it never writes inside the repository.
//
//   php scripts/ops/backup-restore-proof.php --synthetic-proof --workdir /abs/empty-0700-dir
//   php scripts/ops/backup-restore-proof.php --verify --backup /abs/backup --restore /abs/restore
//
// The equivalent MySQL procedure is documented in docs/ops/backup-restore-proof.md and is not executed here.

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never {
    throw new RuntimeException('Operation failed.');
});

const PROOF_SCOPE = 'synthetic_backup_restore_proof';
const PROOF_MAX_FILE_BYTES = 8_388_608;
const PROOF_MAX_FILES = 512;

$checks = [];
$counts = ['database_bytes' => 0, 'private_files' => 0, 'private_bytes' => 0];
$mode = null;
$record = static function (string $id, bool $ok) use (&$checks): bool {
    $checks[] = ['id' => $id, 'status' => $ok ? 'pass' : 'blocked'];

    return $ok;
};
$finish = static function () use (&$checks, &$counts, &$mode): never {
    $blocked = $checks === [] || in_array('blocked', array_column($checks, 'status'), true);
    echo json_encode([
        'schema_version' => 1,
        'scope' => PROOF_SCOPE,
        'mode' => $mode,
        'synthetic' => true,
        'production_data_used' => false,
        'result' => $blocked ? 'BLOCKED' : 'RESTORE_VERIFIED',
        'counts' => $counts,
        'checks' => $checks,
        'mysql_procedure' => 'documented_not_executed',
        'unverified' => ['mysql_dump_and_restore', 'host_storage_snapshot', 'encryption_key_custody', 'off_host_retention', 'restore_time_objective'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($blocked ? 1 : 0);
};

/** Absolute, canonical, no symlink at any component. */
function proof_canonical(string $path): bool
{
    if (! str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
        return false;
    }
    $cursor = '';
    foreach (explode('/', substr($path, 1)) as $part) {
        if (in_array($part, ['', '.', '..'], true)) {
            return false;
        }
        $cursor .= '/'.$part;
        if (is_link($cursor)) {
            return false;
        }
    }

    return realpath($path) === $path;
}

function proof_require(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('Proof precondition failed.');
    }
}

/** Relative paths inside a tree: lowercase ASCII segments only, no dot segments. */
function proof_relative(string $path): bool
{
    return $path !== '' && strlen($path) <= 240 && preg_match('~\A[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)*\z~D', $path) === 1
        && ! in_array('..', explode('/', $path), true);
}

/** @return array{files: array<string, array{bytes: int, sha256: string}>, directories: list<string>, safe: bool} */
function proof_tree(string $root): array
{
    // The root's own mode and type are proven before anything beneath it: a 0777 root lets other
    // users list and replace children whose own modes are still owner-only.
    $rootStat = lstat($root);
    $rootSafe = is_array($rootStat) && ($rootStat['mode'] & 0170000) === 0040000 && ($rootStat['mode'] & 07777) === 0700;
    $files = [];
    $directories = [];
    $safe = $rootSafe;
    $walk = static function (string $directory, string $prefix) use (&$walk, &$files, &$directories, &$safe): void {
        $entries = scandir($directory);
        sort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            $relative = ltrim($prefix.'/'.$entry, '/');
            $stat = lstat($path);
            $type = $stat['mode'] & 0170000;
            if (! proof_relative($relative) || count($files) >= PROOF_MAX_FILES) {
                $safe = false;
            } elseif ($type === 0040000) {
                $safe = $safe && ($stat['mode'] & 07777) === 0700;
                $directories[] = $relative;
                $walk($path, $relative);
            } elseif ($type === 0100000 && $stat['nlink'] === 1 && $stat['size'] <= PROOF_MAX_FILE_BYTES) {
                $safe = $safe && ($stat['mode'] & 07777) === 0600;
                $files[$relative] = ['bytes' => $stat['size'], 'sha256' => hash_file('sha256', $path)];
            } else {
                // Symlinks, hard links, devices, FIFOs and oversized files are never followed or copied.
                $safe = false;
            }
        }
    };
    $walk($root, '');

    return ['files' => $files, 'directories' => $directories, 'safe' => $safe];
}

function proof_copy(string $from, string $to, string $expectedSha): void
{
    proof_require(is_file($from) && ! is_link($from) && hash_file('sha256', $from) === $expectedSha && ! file_exists($to));
    $in = fopen($from, 'rb');
    $out = fopen($to, 'xb');
    try {
        proof_require(stream_copy_to_stream($in, $out) === filesize($from) && fflush($out));
    } finally {
        fclose($in);
        fclose($out);
    }
    chmod($to, 0600);
    proof_require(hash_file('sha256', $to) === $expectedSha);
}

function proof_mkdir(string $path): void
{
    proof_require(! file_exists($path) && mkdir($path, 0700) && chmod($path, 0700));
}

/** Schema plus every row in rowid order; BLOBs as base64. Independent of page layout. */
function proof_logical(string $database): array
{
    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA query_only = ON');
    $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    $tables = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_NUM);
    $context = hash_init('sha256');
    $rows = [];
    foreach ($tables as [$name, $sql]) {
        proof_require(preg_match('/\A[a-z_]{1,64}\z/D', $name) === 1);
        hash_update($context, $sql."\n");
        $statement = $pdo->query('SELECT * FROM "'.$name.'" ORDER BY rowid');
        $rows[$name] = 0;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            foreach ($row as $column => $value) {
                if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                    $row[$column] = ['base64' => base64_encode($value)];
                }
            }
            hash_update($context, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            $rows[$name]++;
        }
    }

    return ['integrity' => $integrity, 'sha256' => hash_final($context), 'rows' => $rows];
}

/** Deterministic synthetic bytes; obviously not audio, contracts or customer data. */
function proof_bytes(string $label, int $length): string
{
    $out = '';
    for ($block = 0; strlen($out) < $length; $block++) {
        $out .= hash('sha256', 'SYNTHETIC-BACKUP-PROOF|'.$label.'|'.$block, true);
    }

    return substr($out, 0, $length);
}

function proof_synthesize(string $source): void
{
    proof_mkdir($source);
    $database = $source.'/database.sqlite';
    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE synthetic_orders (id INTEGER PRIMARY KEY, public_id TEXT NOT NULL UNIQUE, amount_minor INTEGER NOT NULL, currency TEXT NOT NULL, label TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE synthetic_receipts (id INTEGER PRIMARY KEY, event_id TEXT NOT NULL UNIQUE, payload_sha256 TEXT NOT NULL, payload BLOB NOT NULL)');
    $order = $pdo->prepare('INSERT INTO synthetic_orders (public_id, amount_minor, currency, label) VALUES (?, ?, ?, ?)');
    $receipt = $pdo->prepare('INSERT INTO synthetic_receipts (event_id, payload_sha256, payload) VALUES (?, ?, ?)');
    for ($i = 1; $i <= 25; $i++) {
        // Amounts are arbitrary synthetic integers, not prices.
        $order->execute([sprintf('SYNTHETIC-ORDER-%04d', $i), $i * 101, 'XXX', 'SYNTHETIC — not a real order']);
        $payload = proof_bytes('receipt-'.$i, 64 + $i);
        $receipt->bindValue(1, sprintf('evt_SYNTHETIC%04d', $i));
        $receipt->bindValue(2, hash('sha256', $payload));
        $receipt->bindValue(3, $payload, PDO::PARAM_LOB);
        $receipt->execute();
    }
    $pdo->exec('DELETE FROM synthetic_orders WHERE id = 7');
    $pdo = null;
    chmod($database, 0600);

    $private = $source.'/private';
    foreach (['', '/masters', '/stems', '/stems/synthetic-track-0001', '/contracts', '/empty-dir'] as $directory) {
        proof_mkdir($private.$directory);
    }
    foreach ([
        'masters/synthetic-track-0001.bin' => 262144 + 17,
        'masters/synthetic-track-0002.bin' => 4096,
        'stems/synthetic-track-0001/drums.bin' => 65536,
        'stems/synthetic-track-0001/bass.bin' => 65535,
        'contracts/synthetic-contract-0001.bin' => 1234,
        'contracts/zero-length.bin' => 0,
        'readme-synthetic.txt' => -1,
    ] as $relative => $length) {
        $bytes = $length < 0 ? "SYNTHETIC private-storage sample for the backup/restore proof. Not customer data.\n" : proof_bytes($relative, $length);
        $path = $private.'/'.$relative;
        proof_require(file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes));
        chmod($path, 0600);
    }
}

function proof_backup(string $source, string $backup): void
{
    proof_mkdir($backup);
    $pdo = new PDO('sqlite:'.$source.'/database.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // A transactionally consistent online copy; the SQLite counterpart of mysqldump --single-transaction.
    $pdo->exec("VACUUM INTO '".str_replace("'", "''", $backup.'/database.sqlite')."'");
    $pdo = null;
    chmod($backup.'/database.sqlite', 0600);

    $tree = proof_tree($source.'/private');
    proof_require($tree['safe']);
    proof_mkdir($backup.'/private');
    foreach ($tree['directories'] as $directory) {
        proof_mkdir($backup.'/private/'.$directory);
    }
    foreach ($tree['files'] as $relative => $file) {
        proof_copy($source.'/private/'.$relative, $backup.'/private/'.$relative, $file['sha256']);
    }
    $logical = proof_logical($backup.'/database.sqlite');
    $manifest = json_encode([
        'schema_version' => 1, 'scope' => PROOF_SCOPE, 'synthetic' => true,
        'database' => ['path' => 'database.sqlite', 'bytes' => filesize($backup.'/database.sqlite'),
            'sha256' => hash_file('sha256', $backup.'/database.sqlite'), 'logical_sha256' => $logical['sha256'], 'rows' => $logical['rows']],
        'private' => ['root' => 'private', 'directories' => $tree['directories'], 'files' => $tree['files']],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    proof_require(file_put_contents($backup.'/manifest.json', $manifest, LOCK_EX) === strlen($manifest));
    chmod($backup.'/manifest.json', 0600);
    $digest = hash('sha256', $manifest)."  manifest.json\n";
    proof_require(file_put_contents($backup.'/manifest.json.sha256', $digest, LOCK_EX) === strlen($digest));
    chmod($backup.'/manifest.json.sha256', 0600);
}

/** @return array<string, mixed> */
function proof_manifest(string $backup): array
{
    $manifest = file_get_contents($backup.'/manifest.json');
    $digest = file_get_contents($backup.'/manifest.json.sha256');
    proof_require(strlen($manifest) <= 1048576 && hash_equals(hash('sha256', $manifest)."  manifest.json\n", $digest));
    $decoded = json_decode($manifest, true, 16, JSON_THROW_ON_ERROR);
    proof_require(is_array($decoded) && ($decoded['scope'] ?? null) === PROOF_SCOPE && ($decoded['synthetic'] ?? null) === true
        && is_array($decoded['private']['files'] ?? null) && is_array($decoded['private']['directories'] ?? null));
    foreach (array_keys($decoded['private']['files']) as $relative) {
        proof_require(is_string($relative) && proof_relative($relative));
    }

    return $decoded;
}

function proof_restore(string $backup, string $restore, array $manifest): void
{
    proof_mkdir($restore);
    proof_copy($backup.'/database.sqlite', $restore.'/database.sqlite', $manifest['database']['sha256']);
    proof_mkdir($restore.'/private');
    foreach ($manifest['private']['directories'] as $directory) {
        proof_require(is_string($directory) && proof_relative($directory));
        proof_mkdir($restore.'/private/'.$directory);
    }
    // Restore only what the authenticated manifest lists; never an unlisted backup file.
    foreach ($manifest['private']['files'] as $relative => $file) {
        proof_copy($backup.'/private/'.$relative, $restore.'/private/'.$relative, $file['sha256']);
    }
}

function proof_same_bytes(string $left, string $right): bool
{
    if (! is_file($left) || ! is_file($right) || is_link($left) || is_link($right) || filesize($left) !== filesize($right)) {
        return false;
    }
    $a = fopen($left, 'rb');
    $b = fopen($right, 'rb');
    try {
        while (! feof($a)) {
            if (fread($a, 65536) !== fread($b, 65536)) {
                return false;
            }
        }

        return feof($b) || fread($b, 1) === '';
    } finally {
        fclose($a);
        fclose($b);
    }
}

function proof_verify(string $backup, string $restore, callable $record, array &$counts, ?array $source = null): void
{
    try {
        $manifest = proof_manifest($backup);
        $record('manifest_digest_matches', true);
    } catch (Throwable) {
        $record('manifest_digest_matches', false);

        return;
    }
    $files = $manifest['private']['files'];
    $database = $manifest['database'];
    $record('backup_database_matches_manifest', is_file($backup.'/database.sqlite') && ! is_link($backup.'/database.sqlite')
        && filesize($backup.'/database.sqlite') === $database['bytes'] && hash_file('sha256', $backup.'/database.sqlite') === $database['sha256']);
    $backupTree = proof_tree($backup.'/private');
    $record('backup_private_tree_matches_manifest', $backupTree['safe'] && $backupTree['files'] === $files
        && $backupTree['directories'] === $manifest['private']['directories']);

    $restoredDatabase = $restore.'/database.sqlite';
    $record('restored_database_bytes_equal_backup', proof_same_bytes($backup.'/database.sqlite', $restoredDatabase)
        && hash_file('sha256', $restoredDatabase) === $database['sha256']);
    try {
        $logical = proof_logical($restoredDatabase);
        $record('restored_database_integrity_ok', $logical['integrity'] === 'ok');
        $record('restored_database_logical_matches_manifest', $logical['sha256'] === $database['logical_sha256'] && $logical['rows'] === $database['rows']);
        if ($source !== null) {
            $record('restored_database_logical_matches_source', $logical['sha256'] === $source['sha256'] && $logical['rows'] === $source['rows']);
        }
    } catch (Throwable) {
        $record('restored_database_integrity_ok', false);
    }

    $restoredTree = proof_tree($restore.'/private');
    $record('restored_private_tree_safe', $restoredTree['safe']);
    $record('restored_private_files_match_manifest', $restoredTree['files'] === $files && $restoredTree['directories'] === $manifest['private']['directories']);
    $equal = true;
    foreach (array_keys($files) as $relative) {
        $equal = $equal && proof_same_bytes($backup.'/private/'.$relative, $restore.'/private/'.$relative);
    }
    $record('restored_private_bytes_equal_backup', $equal);
    $counts = ['database_bytes' => (int) $database['bytes'], 'private_files' => count($files),
        'private_bytes' => array_sum(array_column($files, 'bytes'))];
}

try {
    $arguments = array_slice($argv, 1);
    $repository = dirname(__DIR__, 2);
    $outsideRepository = static fn (string $path): bool => $path !== $repository && ! str_starts_with($path.'/', $repository.'/');
    if (count($arguments) === 3 && $arguments[0] === '--synthetic-proof' && $arguments[1] === '--workdir') {
        $mode = 'synthetic_proof';
        $workdir = $arguments[2];
        $ok = proof_canonical($workdir) && is_dir($workdir) && $outsideRepository($workdir)
            && function_exists('posix_geteuid') && fileowner($workdir) === posix_geteuid()
            && (fileperms($workdir) & 07777) === 0700 && scandir($workdir) === ['.', '..'];
        if (! $record('workdir_isolated_empty_private', $ok)) {
            $finish();
        }
        proof_synthesize($workdir.'/source');
        $source = proof_logical($workdir.'/source/database.sqlite');
        $record('synthetic_source_created', $source['integrity'] === 'ok' && array_sum($source['rows']) === 49);
        proof_backup($workdir.'/source', $workdir.'/backup');
        $record('backup_written', true);
        proof_restore($workdir.'/backup', $workdir.'/restore', proof_manifest($workdir.'/backup'));
        $record('restore_written_to_isolated_location', true);
        $record('restored_private_bytes_equal_source', (static function () use ($workdir): bool {
            $tree = proof_tree($workdir.'/source/private');
            foreach (array_keys($tree['files']) as $relative) {
                if (! proof_same_bytes($workdir.'/source/private/'.$relative, $workdir.'/restore/private/'.$relative)) {
                    return false;
                }
            }

            return $tree['safe'] && $tree['files'] !== [];
        })());
        proof_verify($workdir.'/backup', $workdir.'/restore', $record, $counts, $source);
    } elseif (count($arguments) === 5 && $arguments[0] === '--verify' && $arguments[1] === '--backup' && $arguments[3] === '--restore') {
        $mode = 'verify';
        [$backup, $restore] = [$arguments[2], $arguments[4]];
        $ok = proof_canonical($backup) && proof_canonical($restore) && is_dir($backup) && is_dir($restore)
            && $backup !== $restore && ! str_starts_with($restore.'/', $backup.'/') && ! str_starts_with($backup.'/', $restore.'/')
            && $outsideRepository($backup) && $outsideRepository($restore);
        if (! $record('verify_paths_isolated', $ok)) {
            $finish();
        }
        proof_verify($backup, $restore, $record, $counts);
    } else {
        $record('usage_synthetic_proof_or_verify', false);
    }
} catch (Throwable) {
    // Never echo exception text: it can contain paths or bytes.
    $record('completed_without_error', false);
}
$finish();
