<?php

declare(strict_types=1);

/*
 * Placeholder census for Sean's private license templates (production contract profile, increment 4 gate).
 *
 *   php scripts/contract-placeholder-census.php /private/path/basic.txt /private/path/premium.txt ...
 *
 * Diffs the `{NAME}` placeholders the given templates actually use against ContractVariableBinder::PLACEHOLDERS and
 * reports unknown placeholders, stray braces and doubled symbols (`${PRODUCT_PRICE}`, `{PUBLISHING_RIGHTS}%`). It reads
 * only the files named on the command line and refuses any file inside this repository: the real templates are
 * private and must never be committed or read from a checkout. Output is JSON with names, counts and line numbers
 * only; template text is never printed. Exit status: 0 clean, 1 findings, 2 usage or unreadable input.
 */

use App\Domain\Contracts\Production\ContractTemplateCensus;

require dirname(__DIR__).'/vendor/autoload.php';

$repository = realpath(dirname(__DIR__));
$paths = array_slice($argv, 1);
if ($paths === [] || $repository === false) {
    fwrite(STDERR, "usage: php scripts/contract-placeholder-census.php <template-file>...\n");
    exit(2);
}

$templates = [];
foreach ($paths as $path) {
    $real = realpath($path);
    if ($real === false || ! is_file($real) || is_link($path)) {
        fwrite(STDERR, "refused: not a regular file: {$path}\n");
        exit(2);
    }
    if ($real === $repository || str_starts_with($real, $repository.'/')) {
        fwrite(STDERR, "refused: {$path} is inside the repository; private templates are read only from outside it\n");
        exit(2);
    }
    if (filesize($real) > 1048576) {
        fwrite(STDERR, "refused: {$path} is larger than 1 MiB\n");
        exit(2);
    }
    $label = basename($real);
    if (array_key_exists($label, $templates)) {
        fwrite(STDERR, "refused: two templates are named {$label}\n");
        exit(2);
    }
    $templates[$label] = (string) file_get_contents($real);
}

$report = ContractTemplateCensus::census($templates);
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
exit($report['ok'] ? 0 : 1);
