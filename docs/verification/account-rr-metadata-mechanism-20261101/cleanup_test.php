<?php

$empty = ['sessions' => [], 'transactions' => [], 'metadata_locks' => [], 'data_locks' => []];
$checks = 0;
$check = function (bool $condition) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException('Pure cleanup policy assertion failed: '.$checks);
    }
};
$reject = function (array $sample, float $remaining) use ($check): void {
    try {
        cleanupObservation($sample, $remaining);
    } catch (RuntimeException) {
        $check(true);

        return;
    }
    $check(false);
};
$check(cleanupObservation($empty, 1) === 'clean');
$residual = $empty;
$residual['transactions'] = [['trx_mysql_thread_id' => 41]];
$check(cleanupObservation($residual, 0.9) === 'settle');
$check(cleanupObservation($empty, 0.749) === 'clean');
foreach (['sessions', 'metadata_locks', 'data_locks'] as $key) {
    $locked = $residual;
    $locked[$key] = [['resource' => 'owned']];
    $reject($locked, 0.9);
}
foreach ([0.150, 0.001, 0, -0.1] as $remaining) {
    $reject($residual, $remaining);
}
$reject($empty, 0);
$reject([], 1);
// Finite fake clock: persistent transaction residue never yields a pass.
$remaining = 1.0;
$samples = 0;
try {
    while (cleanupObservation($residual, $remaining) === 'settle') {
        $samples++;
        $remaining -= 0.150;
    }
    $check(false);
} catch (RuntimeException) {
    $check($samples <= 6 && $remaining <= 0.150);
}
emit(['pure_cleanup_policy' => 'PASS', 'assertions' => $checks, 'database_connections' => 0, 'native_close_execution' => false]);
