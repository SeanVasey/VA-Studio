<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only admission of one history source package: `mapping.json`, `customers.csv`, `orders.csv`,
 * `contracts.csv` and the original contract files in `contracts/`. Nothing is written, moved or deleted.
 *
 * A real package is private source material (customer e-mail addresses, payment references, executed
 * contracts): every file must be a caller-owned 0600 single-link file in caller-owned 0700 directories outside
 * any Git working tree, read through PrivateSourceFiles. Only a package whose mapping declares a synthetic
 * fixture may be read from this checkout, and only from tests/Fixtures/.
 */
final class HistorySourceFiles
{
    public const SHEETS = ['customers' => 'customers.csv', 'orders' => 'orders.csv', 'contracts' => 'contracts.csv'];

    public const MAPPING = 'mapping.json';

    public const CONTRACT_DIRECTORY = 'contracts';

    public const MAX_SHEET_BYTES = 8388608;

    public const MAX_CONTRACT_BYTES = 16777216;

    public const MAX_CONTRACT_FILES = 2000;

    public const MAX_TOTAL_CONTRACT_BYTES = 268435456;

    /**
     * @return array{mapping: string, sheets: array<string, string>, contracts: array<string, string>, synthetic_repository_fixture: bool}
     *     contract file name => exact original bytes, sorted by name
     */
    public function read(string $directory): array
    {
        $this->path($directory);
        $repository = dirname(__DIR__, 4);
        $inside = $directory === $repository || str_starts_with($directory, $repository.'/');
        if ($inside) {
            $this->require(str_starts_with($directory, $repository.'/tests/Fixtures/'), 'source_inside_repository');
        } else {
            try {
                (new PrivateSourceFiles)->privateDirectory($directory);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('source_not_private');
            }
        }
        $read = fn (string $path, int $maximum): string => $inside ? $this->fixture($path, $maximum) : $this->private($path, $maximum);
        $mapping = $read($directory.'/'.self::MAPPING, HistoryMapping::MAX_BYTES);
        if ($inside) {
            $this->require((new HistoryMapping)->decode($mapping)['acquisition_method'] === 'synthetic_fixture', 'source_inside_repository');
        }
        $sheets = [];
        foreach (self::SHEETS as $sheet => $name) {
            $sheets[$sheet] = $read($directory.'/'.$name, self::MAX_SHEET_BYTES);
        }
        $contractDirectory = $directory.'/'.self::CONTRACT_DIRECTORY;
        $contracts = [];
        clearstatcache(true, $contractDirectory);
        if (@lstat($contractDirectory) !== false) {
            $stat = $this->path($contractDirectory);
            $this->require(($stat['mode'] & 0170000) === 0040000, 'source_contract_directory');
            $entries = @scandir($contractDirectory, SCANDIR_SORT_ASCENDING);
            $this->require(is_array($entries), 'source_contract_directory');
            $total = 0;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->require(count($contracts) < self::MAX_CONTRACT_FILES, 'source_too_many_contracts');
                $this->require(preg_match('/[\x00-\x1f\x7f\/]/', $entry) === 0 && strlen($entry) <= 255, 'source_contract_name');
                $bytes = $read($contractDirectory.'/'.$entry, self::MAX_CONTRACT_BYTES);
                $total += strlen($bytes);
                $this->require($total <= self::MAX_TOTAL_CONTRACT_BYTES, 'source_contracts_too_large');
                $contracts[$entry] = $bytes;
            }
        }
        ksort($contracts, SORT_STRING);

        return ['mapping' => $mapping, 'sheets' => $sheets, 'contracts' => $contracts, 'synthetic_repository_fixture' => $inside];
    }

    /** One private operator file beside a package, such as delta dispositions (owner names and reasons). */
    public function sideFile(string $path, int $maximum = 1048576): string
    {
        $this->path($path);
        $repository = dirname(__DIR__, 4);
        if ($path === $repository || str_starts_with($path, $repository.'/')) {
            $this->require(str_starts_with($path, $repository.'/tests/Fixtures/'), 'source_inside_repository');

            return $this->fixture($path, $maximum);
        }

        return $this->private($path, $maximum);
    }

    private function private(string $path, int $maximum): string
    {
        try {
            return (new PrivateSourceFiles)->protectedFile($path, $maximum)['bytes'];
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('source_not_private');
        }
    }

    /** A checked-in synthetic fixture: a regular, link-free file read through one descriptor whose identity holds. */
    private function fixture(string $path, int $maximum): string
    {
        $stat = $this->path($path);
        $this->require(($stat['mode'] & 0170000) === 0100000 && $stat['size'] > 0 && $stat['size'] <= $maximum, 'source_file');
        $handle = @fopen($path, 'rb');
        $this->require($handle !== false, 'source_file');
        try {
            $before = fstat($handle);
            $bytes = stream_get_contents($handle, $maximum + 1);
            $after = fstat($handle);
        } finally {
            fclose($handle);
        }
        $this->require(is_string($bytes) && $before !== false && $after !== false && strlen($bytes) === $stat['size']
            && $before['ino'] === $stat['ino'] && $after['ino'] === $stat['ino'] && $after['size'] === $stat['size'], 'source_changed');

        return $bytes;
    }

    /** Absolute, canonical, symlink-free paths only. */
    private function path(string $path): array
    {
        $this->require($path !== '' && strlen($path) <= 4096 && $path[0] === '/' && ! str_contains($path, "\0")
            && ! str_contains($path, '//') && ($path === '/' || ! str_ends_with($path, '/')), 'source_path');
        $cursor = '';
        $stat = null;
        foreach (explode('/', substr($path, 1)) as $component) {
            $this->require(! in_array($component, ['', '.', '..'], true), 'source_path');
            $cursor .= '/'.$component;
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            $this->require($stat !== false && ($stat['mode'] & 0170000) !== 0120000, 'source_path');
        }
        try {
            $this->require($stat !== null && realpath($path) === $path, 'source_path');
        } catch (Throwable) {
            throw new InvalidArgumentException('source_path');
        }

        return $stat;
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
