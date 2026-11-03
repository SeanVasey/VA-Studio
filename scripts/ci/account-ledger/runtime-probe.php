<?php

// Provisioner profile only: no App/vendor, PDO connection, SQL or credential reads.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    $policy = json_decode(file_get_contents('/qualification/runtime-policy.json'), true, 32, JSON_THROW_ON_ERROR);
    $spec = $policy['runtime'];
    $ok = PHP_VERSION === $spec['php_version'] && PHP_BINARY === $spec['php_binary']
        && hash_file('sha256', PHP_BINARY) === $spec['php_binary_sha256']
        && extension_loaded('pdo_mysql') && phpversion('mysqlnd') === 'mysqlnd 8.4.27'
        && in_array('mysql', PDO::getAvailableDrivers(), true)
        && (new ReflectionClass(Pdo\Mysql::class))->isInternal()
        && (new ReflectionMethod(Pdo\Mysql::class, 'getWarningCount'))->isInternal()
        && posix_geteuid() === 1000 && posix_getegid() === 1000
        && ini_get('memory_limit') === '512M' && ini_get('zend.exception_ignore_args') === '1'
        && ini_get('auto_prepend_file') === '' && ini_get('auto_append_file') === ''
        && ini_get('disable_functions') === '';
    foreach ($spec['php_modules'] as $name => $hash) {
        $ok = $ok && hash_file('sha256', '/usr/lib/php/20240924/'.$name.'.so') === $hash;
    }
    foreach (['proc_open', 'pcntl_waitpid', 'pcntl_wifstopped', 'pcntl_wstopsig',
        'pcntl_wifsignaled', 'pcntl_wtermsig', 'posix_kill', 'socket_import_stream'] as $function) {
        $ok = $ok && function_exists($function) && (new ReflectionFunction($function))->isInternal();
    }
    $ok = $ok && pcntl_signal_get_handler(SIGCHLD) === SIG_DFL
        && pcntl_signal_get_handler(SIGUSR1) === SIG_DFL
        && !file_exists('/var/run/docker.sock') && !is_readable('/var/run/docker.sock');
    if (!$ok) {
        throw new RuntimeException('Runtime refused.');
    }
    echo "{\"profile_match\":true}\n";
} catch (Throwable) {
    exit(2);
}
