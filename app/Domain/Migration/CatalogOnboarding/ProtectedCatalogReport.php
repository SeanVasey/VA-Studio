<?php

declare(strict_types=1);

namespace App\Domain\Migration\CatalogOnboarding;

use App\Support\CanonicalJson;
use InvalidArgumentException;
use Throwable;

/** Explicit private review file; never overwrite, expose a source record on stdout or remove a failed write. */
final class ProtectedCatalogReport
{
    public function write(string $path, array $review, string $key): array
    {
        try {
            $files = new PrivateSourceFiles;
            $files->privateDirectory(dirname($path));
            $this->require(basename($path) !== '' && ! preg_match('/[\x00-\x1f\x7f]/', $path)
                && @lstat($path) === false && strlen($key) >= 32);
            $bytes = CanonicalJson::encode(['schema_version' => 1, 'review' => $review,
                'hmac_sha256' => hash_hmac('sha256', CanonicalJson::encode($review), $key)])."\n";
            $this->require(strlen($bytes) <= 33554432);
            $handle = @fopen($path, 'xb');
            $this->require($handle !== false);
            try {
                $this->require(chmod($path, 0600));
                $written = 0;
                while ($written < strlen($bytes)) {
                    $count = fwrite($handle, substr($bytes, $written));
                    $this->require($count !== false && $count > 0);
                    $written += $count;
                }
                $this->require(fflush($handle) && function_exists('fsync') && fsync($handle));
            } finally {
                fclose($handle);
            }
            $proof = $files->protectedFile($path);
            $this->require($proof['bytes'] === $bytes);

            return $proof;
        } catch (Throwable) {
            throw new InvalidArgumentException('catalog_report_invalid');
        }
    }

    public function read(string $path, string $key): array
    {
        try {
            $proof = (new PrivateSourceFiles)->protectedFile($path);
            $envelope = json_decode($proof['bytes'], true, 32, JSON_THROW_ON_ERROR);
            $this->require(is_array($envelope) && array_keys($envelope) === ['hmac_sha256', 'review', 'schema_version']
                && $envelope['schema_version'] === 1 && is_array($envelope['review']) && is_string($envelope['hmac_sha256'])
                && CanonicalJson::encode($envelope)."\n" === $proof['bytes'] && strlen($key) >= 32
                && hash_equals(hash_hmac('sha256', CanonicalJson::encode($envelope['review']), $key), $envelope['hmac_sha256']));

            return ['review' => $envelope['review'], 'proof' => $proof];
        } catch (Throwable) {
            throw new InvalidArgumentException('catalog_report_invalid');
        }
    }

    public function unchanged(array $proof): void
    {
        $this->require((new PrivateSourceFiles)->protectedFile($proof['path']) === $proof);
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('catalog_report_invalid');
        }
    }
}
