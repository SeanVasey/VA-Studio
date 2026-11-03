<?php

// PURE API controls. NO Process constructor/start/run/wait/signal or app bootstrap.
// Reflection-injected READY/TERMINATED objects are synthetic, never actual process/reap evidence.
use Symfony\Component\Process\Exception\LogicException as ProcessLogicException;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$root = dirname(__DIR__, 3);
$reflection = new ReflectionClass(Process::class);
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$check(hash_file('sha256', $root.'/vendor/symfony/process/Process.php') === '73c4e31230f9602768b73cda3451ed7ec0dddc20ddef6cc0d6795ea879600c39', 'Locked Process source moved.');
$lock = json_decode(file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$package = array_values(array_filter($lock['packages'], static fn (array $p): bool => $p['name'] === 'symfony/process'))[0];
$check($package['version'] === 'v8.0.13' && $package['source']['reference'] === '4f23d9c7637ead9ed19f697fe93cb87fd9b379d4', 'Process version/ref moved.');
$methods = ['isRunning', 'getExitCode', 'hasBeenSignaled', 'getTermSignal'];
$validate = static function (array $names) use ($reflection): void {
    foreach ($names as $name) {
        if (! $reflection->hasMethod($name) || ! $reflection->getMethod($name)->isPublic()
            || $reflection->getMethod($name)->getNumberOfRequiredParameters() !== 0) {
            throw new RuntimeException('Unsupported status API: '.$name);
        }
    }
};
$validate($methods);
$oldRejected = false;
try {
    $validate(['isRunning', 'getExitCode', 'isSignaled', 'getTermSignal']);
} catch (RuntimeException) {
    $oldRejected = true;
}
$check($oldRejected && ! $reflection->hasMethod('isSignaled'), 'Historical nonexistent API must be refused; do not swallow its Error as cleanup.');
$native = file_get_contents($root.'/tests/Feature/ProductionMembershipHttp/MembershipNativeCustomerRateLimitTest.php');
$check(substr_count($native, '$worker->hasBeenSignaled()') === 1 && ! str_contains($native, '->isSignaled('), 'Actual native observer must use the checked API.');
$set = static function (Process $process, string $name, mixed $value) use ($reflection): void {
    $reflection->getProperty($name)->setValue($process, $value);
};
$observe = static function (Process $process): array {
    $running = $process->isRunning();
    $exit = $process->getExitCode();
    $signaled = $process->hasBeenSignaled();

    return ['terminal' => ! $running && $exit !== null, 'exit' => $exit,
        'signaled' => $signaled, 'signal' => $signaled ? $process->getTermSignal() : null];
};
// Keep all test doubles non-STARTED: locked updateStatus() returns without proc_get_status.
$sigchild = $reflection->getProperty('sigchild');
$originalSigchild = $sigchild->getValue();
$sigchild->setValue(null, false); // Synthetic API condition only; restore in finally, no production state change.
try {
    foreach ([[0, false, 0, true], [137, true, 9, true], [null, false, 0, false]] as [$exit, $signaled, $signal, $terminal]) {
        $process = $reflection->newInstanceWithoutConstructor();
        $set($process, 'status', Process::STATUS_TERMINATED);
        $set($process, 'exitcode', $exit);
        $set($process, 'processInformation', ['running' => false, 'signaled' => $signaled, 'termsig' => $signal]);
        $result = $observe($process);
        $check($result === ['terminal' => $terminal, 'exit' => $exit, 'signaled' => $signaled,
            'signal' => $signaled ? $signal : null], 'Synthetic terminal/non-null/signal API contract differs.');
        unset($process); // Locked destructor stop(0) sees non-STARTED and never signals/opens/waits.
    }
    $ready = $reflection->newInstanceWithoutConstructor(); // Default READY: not terminal, no subprocess exists.
    $check($ready->isRunning() === false && $ready->getExitCode() === null, 'READY cannot establish cleanup.');
    $refused = false;
    try {
        $ready->hasBeenSignaled();
    } catch (ProcessLogicException) {
        $refused = true;
    }
    $check($refused, 'Nonterminal API refusal must remain UNKNOWN, never pass.');
    unset($ready);
} finally {
    $sigchild->setValue(null, $originalSigchild);
}
fwrite(STDOUT, "PURE status API: PASS locked4method contract; oldAPI rejected;3 synthetic terminal/exit cases;READY UNKNOWN. No process spawned; no actual cleanup evidence.\n");
