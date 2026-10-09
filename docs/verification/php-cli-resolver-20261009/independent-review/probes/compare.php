<?php

// Compares the CLI baseline (evidence/53-cli-baseline-*.txt), the stored CLI test artifacts (capture/*.stored-sha256)
// and the FPM green replies (evidence/52-fpm-green-dac1a79.txt): PDF SHA-256 per family, and for the pinned families
// the argument vector, cwd, timeout, stdin hash and child environment the factory produced versus the CLI renderer.
$base = dirname(__DIR__);
$json = function (string $file): array {
    $rows = [];
    foreach (file($file) as $line) {
        if (str_starts_with($line, '{')) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $rows[$row['family'].(isset($row['spawn']) ? '+record' : '')] = $row;
        }
    }

    return $rows;
};
$fpm = $json($base.'/evidence/52-fpm-green-dac1a79.txt');
$failed = false;
foreach (['free', 'production-free', 'paid', 'generic'] as $family) {
    $cli = $json($base.'/evidence/53-cli-baseline-'.$family.'.txt');
    $cliRow = $cli[$family.'+record'] ?? $cli[$family];
    $stored = is_file($base.'/capture/'.$family.'.stored-sha256') ? trim(file_get_contents($base.'/capture/'.$family.'.stored-sha256')) : '(n/a)';
    $same = ($cliRow['sha256'] ?? 'x') === ($fpm[$family]['sha256'] ?? 'y');
    $sameRecorded = $family === 'generic' || ($cliRow['sha256'] ?? 'x') === ($fpm[$family.'+record']['sha256'] ?? 'y');
    $sameStored = $stored === '(n/a)' || $stored === ($cliRow['sha256'] ?? 'x');
    printf("%-16s cli=%s fpm=%s fpm+record=%s stored=%s  identical=%s\n", $family, $cliRow['sha256'] ?? '-', $fpm[$family]['sha256'] ?? '-',
        $fpm[$family.'+record']['sha256'] ?? '-', $stored, ($same && $sameRecorded && $sameStored) ? 'yes' : 'NO');
    $failed = $failed || ! ($same && $sameRecorded && $sameStored);
    if ($family === 'generic') {
        continue;
    }
    $a = $cliRow['spawn'];
    $b = $fpm[$family.'+record']['spawn'];
    $argvRest = array_slice($a['argv'], 1) === array_slice($b['argv'], 1);
    printf("  cli argv0=%s (renderer built %s, factory %s)\n  fpm argv0=%s (renderer built %s, factory %s)\n",
        $a['argv'][0], $a['renderer_built_argv0'], $cliRow['factory'], $b['argv'][0], $b['renderer_built_argv0'], $fpm[$family.'+record']['factory']);
    printf("  argv[1..] identical=%s (%d args) cwd identical=%s timeout cli=%s fpm=%s stdin identical=%s\n",
        $argvRest ? 'yes' : 'NO', count($a['argv']), $a['cwd'] === $b['cwd'] ? 'yes' : 'NO', $a['timeout'], $b['timeout'],
        $a['stdin_sha256'] === $b['stdin_sha256'] ? 'yes' : 'NO');
    printf("  child env cli=%s\n  child env fpm=%s  identical=%s\n", json_encode($a['env_set']), json_encode($b['env_set']), $a['env_set'] == $b['env_set'] ? 'yes' : 'NO');
    $failed = $failed || ! $argvRest || $a['cwd'] !== $b['cwd'] || $a['stdin_sha256'] !== $b['stdin_sha256'] || $a['env_set'] != $b['env_set'];
}
exit($failed ? 1 : 0);
