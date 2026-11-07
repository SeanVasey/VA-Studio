<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractIssuanceException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sean's signature image for the Producer/Licensor line of `vasey-license-pdf-v1`. It never lives in Git or under a
 * public path: production reads it from the private local disk (`contracts.vasey_license_v1.signature_path`, relative
 * to that disk) and checks it against the configured SHA-256 pin. The bytes are read and hashed once, at render time,
 * and only those checked bytes are drawn.
 *
 * Refusals are typed {@see ContractIssuanceException} reasons: `signature_unavailable` (no pin, no file, a link or an
 * unusable path), `signature_changed` (the bytes differ from the pin) and `signature_invalid` (not a bounded PNG).
 */
final readonly class VaseyLicenseSignature
{
    public const MAX_BYTES = 2097152;

    public const MAX_PIXELS = 4096;

    public function __construct(public string $path, public string $sha256)
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1 || $path === '' || ! str_starts_with($path, '/')) {
            throw new ContractIssuanceException('signature_unavailable');
        }
    }

    /**
     * The configured production signature. The path is relative to the private local disk; absolute paths, `..`
     * segments, hidden segments and anything that resolves outside that disk are refused.
     */
    public static function fromConfig(): self
    {
        $relative = config('contracts.vasey_license_v1.signature_path');
        $sha256 = config('contracts.vasey_license_v1.signature_sha256');
        if (! is_string($relative) || ! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1
            || preg_match('#\A(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.png\z#', $relative) !== 1
            || preg_match('#(?:\A|/)\.\.?(?:/|\z)#', $relative) === 1) {
            throw new ContractIssuanceException('signature_unavailable');
        }
        try {
            $disk = realpath(Storage::disk('local')->path(''));
        } catch (Throwable) {
            throw new ContractIssuanceException('signature_unavailable');
        }
        $public = realpath(public_path());
        if (! is_string($disk) || $disk === '/' || ($public !== false && ($disk === $public || str_starts_with($disk.'/', $public.'/')))) {
            throw new ContractIssuanceException('signature_unavailable');
        }
        $path = $disk.'/'.$relative;
        $resolved = realpath($path);
        if ($resolved === false || $resolved !== $path) {
            throw new ContractIssuanceException('signature_unavailable');
        }

        return new self($path, $sha256);
    }

    /** The checked PNG bytes and their pixel size. */
    public function read(): array
    {
        if (! is_file($this->path) || is_link($this->path)) {
            throw new ContractIssuanceException('signature_unavailable');
        }
        $size = filesize($this->path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new ContractIssuanceException('signature_invalid');
        }
        $bytes = file_get_contents($this->path, false, null, 0, self::MAX_BYTES + 1);
        if (! is_string($bytes)) {
            throw new ContractIssuanceException('signature_unavailable');
        }
        if (! hash_equals($this->sha256, hash('sha256', $bytes))) {
            throw new ContractIssuanceException('signature_changed');
        }
        $info = str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? @getimagesizefromstring($bytes) : false;
        if (! is_array($info) || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1
            || $info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
            throw new ContractIssuanceException('signature_invalid');
        }

        return ['bytes' => $bytes, 'width' => $info[0], 'height' => $info[1]];
    }
}
