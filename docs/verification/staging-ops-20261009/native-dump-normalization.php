<?php

declare(strict_types=1);

// Disposable-server proof. Temporary client credentials are never printed or retained.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'vaseyaudio_test'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_USERNAME') !== 'root') {
    throw new RuntimeException('Disposable test-server environment required.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), 'root', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$facts = $pdo->query('SELECT VERSION() AS version, @@datadir AS datadir, @@socket AS socket, @@bind_address AS bind_address')->fetch(PDO::FETCH_ASSOC);
if (! str_starts_with($facts['version'], '8.4.') || $facts['socket'] !== '' || $facts['bind_address'] !== '127.0.0.1'
    || preg_match('#\A'.preg_quote(sys_get_temp_dir(), '#').'/vasey-mysql-test\.[A-Za-z0-9]+/data/\z#', $facts['datadir']) !== 1) {
    throw new RuntimeException('Native disposable helper server required.');
}
$directory = sys_get_temp_dir().'/vasey-dump-proof-'.bin2hex(random_bytes(12));
mkdir($directory, 0700);
$configuration = $directory.'/client.cnf';
file_put_contents($configuration, "[client]\nhost=127.0.0.1\nprotocol=tcp\nport=".getenv('DB_PORT')."\nuser=root\npassword=".getenv('DB_PASSWORD')."\n");
chmod($configuration, 0600);
$run = static function (array $command, ?string $input, string $output) use ($directory): void {
    $descriptors = [0 => $input === null ? ['pipe', 'r'] : ['file', $input, 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $directory.'/stderr', 'w']];
    $environment = getenv();
    if (in_array($command[0], [getenv('MYSQL_DUMP_BIN'), getenv('MYSQL_CLIENT_BIN')], true)) {
        $environment['LD_LIBRARY_PATH'] = getenv('MYSQL_TEST_LIBRARY_PATH');
    }
    $process = proc_open($command, $descriptors, $pipes, null, $environment);
    if ($input === null) {
        fclose($pipes[0]);
    }
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('native subprocess refused');
    }
};
try {
    $pdo->exec("CREATE TABLE dump_probe (id int PRIMARY KEY, inherited varchar(255), explicit text COLLATE utf8mb4_unicode_ci, choices enum('one','two')) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('CREATE TRIGGER dump_probe_insert BEFORE INSERT ON dump_probe FOR EACH ROW SET NEW.id = NEW.id');
    $text = 'A CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    $pdo->prepare('INSERT INTO dump_probe VALUES (1, ?, ?, ?)')->execute([$text, $text, 'one']);
    $dump = [getenv('MYSQL_DUMP_BIN'), '--defaults-extra-file='.$configuration, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', '--skip-dump-date', '--skip-comments', '--databases', getenv('DB_DATABASE')];
    $original = $directory.'/original.sql';
    $redump = $directory.'/redump.sql';
    $run($dump, null, $original);
    $run([getenv('MYSQL_CLIENT_BIN'), '--defaults-extra-file='.$configuration], $original, $directory.'/load-output');
    $run($dump, null, $redump);
    $normalizer = getcwd().'/ops/staging/normalize-mysql-dump.py';
    $run(['python3', '-I', $normalizer, $original], null, $directory.'/original.normalized');
    $run(['python3', '-I', $normalizer, $redump], null, $directory.'/redump.normalized');
    if (hash_file('sha256', $directory.'/original.normalized') !== hash_file('sha256', $directory.'/redump.normalized')) {
        // All rows are explicit synthetic fixtures; keep this bounded schema diagnostic, no credentials.
        foreach (['original', 'redump'] as $name) {
            $lines = array_values(array_filter(file($directory.'/'.$name.'.normalized', FILE_IGNORE_NEW_LINES), static fn ($line) => str_starts_with($line, '  `') || str_starts_with($line, 'DROP TABLE') || str_starts_with($line, 'CREATE TABLE')));
            echo json_encode(['synthetic_schema' => $name, 'lines' => $lines], JSON_THROW_ON_ERROR).PHP_EOL;
        }
        throw new RuntimeException('native normalized dump differs');
    }
    if ($pdo->query('SELECT inherited FROM dump_probe WHERE id=1')->fetchColumn() !== $text) {
        throw new RuntimeException('restored row changed');
    }
    $raw = file_get_contents($redump);
    $changed = preg_replace_callback('/^INSERT INTO .*$/m', static fn ($match) => str_replace($text, 'A COLLATE utf8mb4_unicode_ci', $match[0]), $raw);
    if ($changed === $raw) {
        throw new RuntimeException('row-drift fixture was not changed');
    }
    file_put_contents($directory.'/altered.sql', $changed);
    $run(['python3', '-I', $normalizer, $directory.'/altered.sql'], null, $directory.'/altered.normalized');
    if (hash_file('sha256', $directory.'/altered.normalized') === hash_file('sha256', $directory.'/redump.normalized')) {
        throw new RuntimeException('normalization concealed row drift');
    }
    echo json_encode(['version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'first_and_redump_bytes_equal' => hash_file('sha256', $original) === hash_file('sha256', $redump), 'normalized_dump_equal' => true, 'restored_row_exact' => true, 'changed_insert_refused' => true, 'trigger_count' => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='vaseyaudio_test'")->fetchColumn()], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
