<?php

declare(strict_types=1);

// Genuine client authentication against only the guarded disposable helper server.
$directory = null;
$pdo = null;
$proofCode = 0;
try {
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'vaseyaudio_test'
        || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_USERNAME') !== 'root'
        || getenv('DB_PORT') !== '3306' || getenv('DB_SOCKET') !== '') {
        throw new RuntimeException('Guarded disposable environment required.');
    }
    $sourceSha = $argv[1] ?? '';
    if (! preg_match('/\A[0-9a-f]{40}\z/', $sourceSha)) {
        throw new RuntimeException('Exact source required.');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=vaseyaudio_test', 'root', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $facts = $pdo->query('SELECT VERSION() AS version, @@datadir AS datadir, @@socket AS socket, @@bind_address AS bind_address')->fetch(PDO::FETCH_ASSOC);
    if (! str_starts_with($facts['version'], '8.4.') || $facts['socket'] !== '' || $facts['bind_address'] !== '127.0.0.1'
        || preg_match('#\A'.preg_quote(sys_get_temp_dir(), '#').'/vasey-mysql-test\.[A-Za-z0-9]+/data/\z#', $facts['datadir']) !== 1) {
        throw new RuntimeException('Native disposable helper server required before account mutation.');
    }
    $directory = sys_get_temp_dir().'/vasey-public-backup-native-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    mkdir($directory.'/bin', 0700);
    $password = bin2hex(random_bytes(20));
    $pdo->exec("CREATE USER 'vasey_backup'@'127.0.0.1' IDENTIFIED BY '$password'");
    $configuration = $directory.'/backup.my.cnf';
    $writeProfile = static function (string $value) use ($configuration): void {
        file_put_contents($configuration, "[client]\nhost=127.0.0.1\nport=3306\nuser=vasey_backup\npassword=$value\n");
        chmod($configuration, 0600);
    };
    $client = '/workspace/.va-studio-toolchain/mysql-image/root/usr/bin/mysql';
    $libraries = '/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64:/workspace/.va-studio-toolchain/mysql-image/root/usr/lib64/mysql/private';
    // A toolchain-only adapter preserves native MySQL libraries after the source's env -i.
    file_put_contents($directory.'/bin/mysql', '#!/bin/sh'."\n".'exec env LD_LIBRARY_PATH='.escapeshellarg($libraries).' '.escapeshellarg($client).' "$@"'."\n");
    chmod($directory.'/bin/mysql', 0700);
    $run = static function (array $command, array $environment, ?string $input = null): array {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Owned subprocess unavailable.');
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    };
    [$gitCode, $source] = $run(['git', '-C', getcwd(), 'show', $sourceSha.':ops/staging/provision.sh'], ['PATH' => '/usr/bin:/bin']);
    if ($gitCode !== 0) {
        throw new RuntimeException('Assessed source unavailable.');
    }
    $start = strpos($source, 'backup_credential_custody()');
    $end = strpos($source, 'if ! user_exists vasey_backup;');
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Exact source authentication functions unavailable.');
    }
    $functions = substr($source, $start, $end - $start);
    $shell = <<<'SHELL'
die() { echo "$*" >&2; exit 1; }
stat() {
  if [ "$2" = '%u %a %h' ]; then printf '0 %s %s\n' "$(command stat -c %a "$3")" "$(command stat -c %h "$3")";
  else command stat "$@"; fi
}
SHELL;
    $shell .= "\n".$functions."\nbackup_credential_custody\nbackup_credential_authentication\nprintf 'AUTH_VERIFIED\\n'\n";
    $environment = ['PATH' => $directory.'/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'BACKUP_CNF' => $configuration];
    $writeProfile($password);
    [$good, $goodOutput] = $run(['bash', '-euo', 'pipefail', '-c', $shell], $environment);
    if ($good !== 0 || trim($goodOutput) !== 'AUTH_VERIFIED') {
        throw new RuntimeException('Valid native credential refused.');
    }
    $writeProfile(bin2hex(random_bytes(20)));
    [$bad, $badOutput] = $run(['bash', '-euo', 'pipefail', '-c', $shell], $environment);
    if ($bad === 0 || str_contains($badOutput, 'AUTH_VERIFIED')) {
        throw new RuntimeException('Wrong native password accepted.');
    }
    $writeProfile($password);
    $pdo->exec("DROP USER 'vasey_backup'@'127.0.0.1'");
    $pdo->exec("CREATE USER 'vasey_backup'@'localhost' IDENTIFIED BY '$password'");
    [$otherCode, $otherIdentity] = $run([$directory.'/bin/mysql', '--defaults-file='.$configuration,
        '--no-login-paths', '--protocol=TCP', '--connect-timeout=5', '--batch', '--skip-column-names',
        '-e', 'SELECT CURRENT_USER()'], $environment);
    if ($otherCode !== 0 || trim($otherIdentity) !== 'vasey_backup@localhost') {
        throw new RuntimeException('Unexpected-identity native case did not authenticate before admission.');
    }
    [$wrongHost, $wrongHostOutput] = $run(['bash', '-euo', 'pipefail', '-c', $shell], $environment);
    if ($wrongHost === 0 || str_contains($wrongHostOutput, 'AUTH_VERIFIED')) {
        throw new RuntimeException('Unexpected native account identity accepted.');
    }
    echo json_encode(['source' => $sourceSha, 'server_version' => $facts['version'], 'valid_credential_authenticates' => true,
        'wrong_password_refused' => true, 'unexpected_identity_refused' => true, 'native_client' => true,
        'root_ownership_modeled' => true, 'host_acceptance' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Neither SQL containing generated passwords nor native stderr may enter a receipt.
    fwrite(STDERR, 'Native backup credential proof refused: '.($error instanceof RuntimeException ? $error->getMessage() : 'guarded operation failed').PHP_EOL);
    $proofCode = 1;
} finally {
    if ($directory !== null) {
        foreach (glob($directory.'/bin/*') as $file) {
            unlink($file);
        }
        rmdir($directory.'/bin');
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
exit($proofCode);
