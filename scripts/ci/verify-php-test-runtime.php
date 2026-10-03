<?php

// Read-only preflight for the disposable PHP test process; no application boot.
$extensions = ['fileinfo', 'mbstring', 'intl', 'PDO', 'pdo_mysql', 'pdo_sqlite', 'bcmath', 'gd', 'zip', 'curl', 'dom', 'xml', 'xmlwriter', 'posix', 'pcntl'];

if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4 || PHP_INT_SIZE !== 8 || ini_get('memory_limit') !== '512M') {
    fwrite(STDERR, "Tests require 64-bit PHP 8.4 with a 512M memory limit.\n");
    exit(1);
}

foreach ($extensions as $extension) {
    if (! extension_loaded($extension)) {
        fwrite(STDERR, "A required PHP test extension is unavailable.\n");
        exit(1);
    }
}

if (! function_exists('posix_setrlimit') || ! function_exists('pcntl_signal') || ! defined('SIGXFSZ')) {
    fwrite(STDERR, "Native physical-write tests require POSIX resource limits and PCNTL signals.\n");
    exit(1);
}
