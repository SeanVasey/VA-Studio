<?php

namespace App\Domain\Grants\Member;

use App\Domain\Contracts\RenderedContract;
use App\Domain\Memberships\Production\MembershipValues;
use App\Support\CanonicalJson;
use Throwable;
use UnexpectedValueException;

/** Private, no-overwrite candidate storage. Neither a file nor this adapter grants rights. */
final class MemberOriginalFiles
{
    public const MAX_BYTES = 16777216;

    public function createRendererWorkspace(MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): MemberOriginalWorkspace
    {
        try {
            $root = $this->root(true, $idle, $budget);
            $parent = $this->directories($root, 'contracts/member-render-cache', true, $idle, $budget);

            return MemberOriginalWorkspace::create($parent, $idle, $budget);
        } catch (Throwable) {
            throw new MemberGrantException('storage_failed');
        }
    }

    public function storeOrVerify(MemberOriginalSourceValues $source, RenderedContract $expected,
        MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): MemberOriginalFileObservation
    {
        $output = null;
        $temporary = null;
        $owned = null;
        $started = \hrtime(true);
        try {
            $record = $this->record($source, $expected);
            $this->validateBytes($expected->pdfBytes, $record);
            $root = $this->root(true, $idle, $budget);
            $directory = $this->directories($root, dirname($record['path']), true, $idle, $budget);
            $path = $root.'/'.$record['path'];
            if ($this->io($idle, $budget, fn () => $this->stat($path)) !== false) {
                return $this->observe($root, $record, $expected->pdfBytes, null, $started, $idle, $budget);
            }

            $temporary = $directory.'/.candidate-'.bin2hex(random_bytes(16)).'.tmp';
            $output = $this->io($idle, $budget, static function () use ($temporary) {
                $mask = umask(0077);
                try {
                    return @fopen($temporary, 'x+b');
                } finally {
                    umask($mask);
                }
            });
            if (! is_resource($output)) {
                throw new UnexpectedValueException;
            }
            $this->nativeStream($output, $temporary, $idle, $budget);
            $owned = $this->io($idle, $budget, fn () => fstat($output));
            $this->sameFile($temporary, $owned, $root, false, 1, $idle, $budget);
            for ($offset = 0; $offset < $expected->sizeBytes;) {
                $written = $this->io($idle, $budget, fn () => @fwrite($output, substr($expected->pdfBytes, $offset, 1048576)));
                if (! is_int($written) || $written < 1) {
                    throw new UnexpectedValueException;
                }
                $offset += $written;
            }
            if (! $this->io($idle, $budget, fn () => @fflush($output))
                || ! $this->io($idle, $budget, fn () => @fsync($output))
                || $this->io($idle, $budget, fn () => fseek($output, 0)) !== 0) {
                throw new UnexpectedValueException;
            }
            $bytes = $this->io($idle, $budget, fn () => stream_get_contents($output, self::MAX_BYTES + 1));
            if (! is_string($bytes) || $bytes !== $expected->pdfBytes) {
                throw new UnexpectedValueException;
            }
            $this->sameFile($temporary, $this->io($idle, $budget, fn () => fstat($output)), $root, false, 1, $idle, $budget);
            if (! $this->io($idle, $budget, fn () => @chmod($temporary, 0400))) {
                throw new UnexpectedValueException;
            }
            $this->sameFile($temporary, $this->io($idle, $budget, fn () => fstat($output)), $root, true, 1, $idle, $budget);
            $this->root(false, $idle, $budget);
            $this->directories($root, dirname($record['path']), false, $idle, $budget);

            // link() is atomic and never replaces an existing final name. A crash before
            // our unlink leaves nlink=2 and is deliberately refused by the next invocation.
            $linked = $this->io($idle, $budget, fn () => @link($temporary, $path));
            if ($linked) {
                $this->sameFile($path, $this->io($idle, $budget, fn () => fstat($output)), $root, true, 2, $idle, $budget);
            }
            $this->removeOwnedTemporary($temporary, $owned, $root, $idle, $budget);
            $temporary = null;

            return $this->observe($root, $record, $expected->pdfBytes, null, $started, $idle, $budget);
        } catch (Throwable) {
            throw new MemberGrantException('storage_failed');
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if ($temporary !== null && is_array($owned)) {
                try {
                    $this->removeOwnedTemporary($temporary, $owned, $root, $idle, $budget);
                } catch (Throwable) { /* An expired/changed boundary leaves an owned orphan, never a foreign cleanup. */
                }
            }
        }
    }

    public function verify(MemberOriginalSourceValues $source, MemberOriginalFileObservation $expected,
        MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        try {
            $record = $this->sourceRecord($source);
            if ($record['path'] !== $expected->relativePath || $record['source'] !== $expected->renderSourceHash
                || $record['storage'] !== $expected->storagePolicyHash || $record['profile'] !== $expected->profileHash
                || $expected->observationStartedNs > $expected->observedAtNs || $expected->observedAtNs > \hrtime(true)) {
                throw new UnexpectedValueException;
            }
            $record += ['hash' => $expected->sha256, 'size' => $expected->sizeBytes, 'pages' => $expected->pageCount];
            $this->validateRecord($record);
            $root = $this->root(false, $idle, $budget);
            $this->observe($root, $record, null, $expected, $expected->observationStartedNs, $idle, $budget);
        } catch (Throwable) {
            throw new MemberGrantException('original_unavailable');
        }
    }

    /** Read-only historical adapter entry. It accepts no arbitrary path and has no creation mode. */
    public function readRetained(MemberActivatedOriginal $original, MemberOriginalIoFrame $idle,
        MemberOriginalBudget $budget, ?MemberRetainedOriginalObservation $expected = null): MemberRetainedOriginalObservation
    {
        $started = $expected?->observationStartedNs ?? \hrtime(true);
        try {
            $record = $this->sourceRecord($original->source());
            $artifact = $original->artifact();
            $record += ['hash' => $artifact['sha256'], 'size' => $artifact['bytes']];
            if ($record['path'] !== $artifact['derived_relative_path'] || $record['source'] !== $artifact['render_source_hash']
                || $record['profile'] !== $artifact['profile_hash'] || $record['storage'] !== $artifact['storage_policy_hash']
                || ($expected !== null && ($expected->relativePath !== $record['path'] || $expected->renderSourceHash !== $record['source']
                    || $expected->sha256 !== $record['hash'] || $expected->sizeBytes !== $record['size']
                    || $expected->profileHash !== $record['profile'] || $expected->storagePolicyHash !== $record['storage']
                    || $started > $expected->observedAtNs || $expected->observedAtNs > \hrtime(true)))) {
                throw new UnexpectedValueException;
            }
            $root = $this->root(false, $idle, $budget);
            // A historical read must own the whole private subtree, without correcting permissions or ownership.
            $owner = function_exists('posix_geteuid') ? \posix_geteuid() : \getmyuid();
            $directory = $root;
            foreach (array_merge([''], explode('/', dirname($record['path']))) as $component) {
                $directory .= $component === '' ? '' : '/'.$component;
                $stat = $this->io($idle, $budget, fn () => $this->stat($directory));
                if (! is_array($stat) || $stat['uid'] !== $owner) {
                    throw new UnexpectedValueException;
                }
            }
            $physical = $this->readExisting($root, $record, null, $expected, $idle, $budget);
            if ($physical['opened']['uid'] !== $owner || $physical['closed']['uid'] !== $owner) {
                throw new UnexpectedValueException;
            }

            return new MemberRetainedOriginalObservation($record['path'], $record['source'], $record['hash'], $record['size'],
                $record['profile'], $record['storage'], $physical['opened']['dev'], $physical['opened']['ino'],
                $started, $physical['observed_at']);
        } catch (Throwable) {
            throw new MemberGrantException('original_unavailable');
        }
    }

    private function record(MemberOriginalSourceValues $source, RenderedContract $expected): array
    {
        $record = $this->sourceRecord($source);
        if ($record['profile'] !== $expected->profileHash) {
            throw new UnexpectedValueException;
        }
        $record += ['hash' => $expected->sha256, 'size' => $expected->sizeBytes, 'pages' => $expected->pageCount];
        $this->validateRecord($record);

        return $record;
    }

    private function sourceRecord(MemberOriginalSourceValues $source): array
    {
        $document = $source->document();
        $origin = $document['origin_id'];
        $provenance = $document['provenance'];
        MembershipValues::id($origin);
        MembershipValues::provenance($provenance);
        $hash = $source->hash();
        MembershipValues::hash($hash);
        if ($hash !== CanonicalJson::hash($document) || $origin !== $document['redemption']['id']
            || $document['family'] !== 'production-member-origin-v1'
            || $document['purpose'] !== 'production-member-license-grant-v1') {
            throw new UnexpectedValueException;
        }
        $storage = $document['definition']['document']['storage_policy'];
        if ($storage['schema_version'] !== 'member-original-storage-policy-v1' || $storage['backend'] !== 'private_local'
            || $storage['layout'] !== 'production-member-candidate-v1' || $storage['provenance'] !== $provenance) {
            throw new UnexpectedValueException;
        }

        return ['path' => "contracts/production-member-v1/{$provenance}/{$origin}/{$hash}/original.pdf",
            'source' => $hash, 'storage' => CanonicalJson::hash($storage),
            'profile' => CanonicalJson::hash($document['profile']['document']['render_profile'])];
    }

    private function validateRecord(array $record): void
    {
        MembershipValues::hash($record['hash']);
        MembershipValues::hash($record['profile']);
        if (! is_int($record['size']) || $record['size'] < 32 || $record['size'] > self::MAX_BYTES
            || ! is_int($record['pages']) || $record['pages'] < 1 || $record['pages'] > 100) {
            throw new UnexpectedValueException;
        }
    }

    private function validateBytes(string $bytes, array $record): void
    {
        if (strlen($bytes) !== $record['size'] || ! hash_equals($record['hash'], hash('sha256', $bytes))
            || ! preg_match('/\A%PDF-1\.[0-7](?:\r\n|\n|\r)/D', $bytes)
            || ! str_ends_with(rtrim($bytes, "\r\n\t "), '%%EOF')) {
            throw new UnexpectedValueException;
        }
    }

    private function observe(string $root, array $record, ?string $trustedBytes, ?MemberOriginalFileObservation $expected,
        int $started, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): MemberOriginalFileObservation
    {
        $physical = $this->readExisting($root, $record, $trustedBytes, $expected, $idle, $budget);

        return new MemberOriginalFileObservation($record['path'], $record['source'], $record['hash'], $record['size'],
            $record['pages'], $record['profile'], $record['storage'], $physical['opened']['dev'], $physical['opened']['ino'],
            $started, $physical['observed_at']);
    }

    /** Shared native read only. Writer/adoption checks remain in their existing callers. */
    private function readExisting(string $root, array $record, ?string $trustedBytes,
        MemberOriginalFileObservation|MemberRetainedOriginalObservation|null $expected,
        MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): array
    {
        $input = null;
        try {
            $this->directories($root, dirname($record['path']), false, $idle, $budget);
            $path = $root.'/'.$record['path'];
            $before = $this->io($idle, $budget, fn () => $this->stat($path));
            $this->sameFile($path, $before, $root, true, 1, $idle, $budget);
            $input = $this->io($idle, $budget, fn () => @fopen($path, 'rb'));
            if (! is_resource($input)) {
                throw new UnexpectedValueException;
            }
            $this->nativeStream($input, $path, $idle, $budget);
            $opened = $this->io($idle, $budget, fn () => fstat($input));
            if ($before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev'] || $opened['size'] !== $record['size']
                || ($expected !== null && ($opened['ino'] !== $expected->inode || $opened['dev'] !== $expected->device))) {
                throw new UnexpectedValueException;
            }
            $this->sameFile($path, $opened, $root, true, 1, $idle, $budget);
            $bytes = $this->io($idle, $budget, fn () => stream_get_contents($input, self::MAX_BYTES + 1));
            if (! is_string($bytes) || ($trustedBytes !== null && $bytes !== $trustedBytes)) {
                throw new UnexpectedValueException;
            }
            $this->validateBytes($bytes, $record);
            $closed = $this->io($idle, $budget, fn () => fstat($input));
            $this->sameFile($path, $closed, $root, true, 1, $idle, $budget);
            $this->root(false, $idle, $budget);
            $this->directories($root, dirname($record['path']), false, $idle, $budget);

            return ['opened' => $opened, 'closed' => $closed, 'observed_at' => \hrtime(true)];
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    private function root(bool $create, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): string
    {
        $idle->assertStillIdle();
        $budget->assertCurrent();
        // Open a known existing native file before any path/stat work. A one-shot
        // substituted wrapper must not run a stat callback, restore itself, and
        // then pass the later candidate-stream check. No probe bytes are trusted.
        $this->nativeFilesystem($idle, $budget);
        $disk = config('filesystems.disks.local');
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local' || ($disk['serve'] ?? false) !== false
            || ($disk['visibility'] ?? 'private') !== 'private' || ($disk['prefix'] ?? '') !== ''
            || ! is_string($disk['root'] ?? null)) {
            throw new UnexpectedValueException;
        }
        $path = rtrim($disk['root'], '/');
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '//') || str_contains($path, "\0")) {
            throw new UnexpectedValueException;
        }
        // Refuse public overlap before creating the private root or any candidate directory.
        $publicRoots = [public_path(), storage_path('app/public')];
        foreach ((array) config('filesystems.disks', []) as $other) {
            if (is_array($other) && ($other['driver'] ?? null) === 'local'
                && (($other['serve'] ?? false) === true || ($other['visibility'] ?? null) === 'public')) {
                $publicRoots[] = $other['root'] ?? '';
            }
        }
        $publicRoots = array_values(array_unique(array_map(fn ($root) => $this->potentialPath($root, $idle, $budget), $publicRoots)));
        $links = (array) config('filesystems.links', []);
        for ($pass = 0; $pass <= count($links); $pass++) {
            $before = count($publicRoots);
            foreach ($links as $link => $target) {
                if (! is_string($link) || ! str_starts_with($link, '/') || str_contains($link, "\0")) {
                    throw new UnexpectedValueException;
                }
                $location = rtrim($this->potentialPath(dirname($link), $idle, $budget), '/').'/'.basename($link);
                foreach ($publicRoots as $public) {
                    if ($location === $public || str_starts_with($location, $public.'/')) {
                        $publicRoots[] = $this->potentialPath($target, $idle, $budget);
                        break;
                    }
                }
            }
            $publicRoots = array_values(array_unique($publicRoots));
            if (count($publicRoots) === $before) {
                break;
            }
        }
        foreach ($publicRoots as $public) {
            if ($public === '/' || $path === $public || str_starts_with($path, $public.'/') || str_starts_with($public, $path.'/')) {
                throw new UnexpectedValueException;
            }
        }
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new UnexpectedValueException;
            }
            $current .= '/'.$component;
            $this->directory($current, $create, $current === $path, $idle, $budget);
        }
        if ($this->io($idle, $budget, fn () => realpath($path)) !== $path) {
            throw new UnexpectedValueException;
        }

        return $path;
    }

    private function potentialPath(mixed $path, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new UnexpectedValueException;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new UnexpectedValueException;
            }
            $parts[] = $part;
        }
        $path = '/'.implode('/', $parts);
        $tail = [];
        while (($real = $this->io($idle, $budget, fn () => realpath($path))) === false) {
            if ($path === '/' || $this->io($idle, $budget, fn () => is_link($path))) {
                throw new UnexpectedValueException;
            }
            array_unshift($tail, basename($path));
            $path = dirname($path);
        }

        return $tail === [] ? $real : rtrim($real, '/').'/'.implode('/', $tail);
    }

    private function directories(string $root, string $relative, bool $create, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): string
    {
        $directory = $root;
        foreach (explode('/', $relative) as $component) {
            $directory .= '/'.$component;
            $this->directory($directory, $create, true, $idle, $budget);
        }

        return $directory;
    }

    private function directory(string $path, bool $create, bool $private, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        $stat = $this->io($idle, $budget, fn () => $this->stat($path));
        if ($stat === false && $create) {
            $this->io($idle, $budget, fn () => @mkdir($path, 0700));
            $stat = $this->io($idle, $budget, fn () => $this->stat($path));
        }
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($private && ($stat['mode'] & 0777) !== 0700)
            || $this->io($idle, $budget, fn () => realpath($path)) !== $path) {
            throw new UnexpectedValueException;
        }
    }

    private function sameFile(string $path, array|false $opened, string $root, bool $sealed, int $links,
        MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        $this->nativeFilesystem($idle, $budget);
        $current = $this->io($idle, $budget, fn () => $this->stat($path));
        if (! is_array($opened) || ! is_array($current) || ($opened['mode'] & 0170000) !== 0100000
            || ($current['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== $links || $current['nlink'] !== $links
            || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
            || $this->io($idle, $budget, fn () => realpath($path)) !== $path || ! str_starts_with($path, $root.'/')
            || ($sealed && (($current['mode'] & 0777) !== 0400 || ($opened['mode'] & 0777) !== 0400))) {
            throw new UnexpectedValueException;
        }
    }

    private function removeOwnedTemporary(string $path, array $owned, string $root, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        $this->root(false, $idle, $budget);
        $this->directories($root, substr(dirname($path), strlen($root) + 1), false, $idle, $budget);
        $stat = $this->io($idle, $budget, fn () => $this->stat($path));
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['dev'] !== $owned['dev'] || $stat['ino'] !== $owned['ino']
            || ! preg_match('/\A\.candidate-[a-f0-9]{32}\.tmp\z/D', basename($path))) {
            throw new UnexpectedValueException;
        }
        if (! $this->io($idle, $budget, fn () => @unlink($path))) {
            throw new UnexpectedValueException;
        }
    }

    private function stat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    private function nativeFilesystem(MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        $probe = null;
        try {
            $probe = $this->io($idle, $budget, static fn () => @fopen(__FILE__, 'rb'));
            $this->nativeStream($probe, __FILE__, $idle, $budget);
        } finally {
            if (is_resource($probe)) {
                fclose($probe);
            }
        }
    }

    private function nativeStream(mixed $stream, string $path, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new UnexpectedValueException;
        }
        $metadata = $this->io($idle, $budget, static fn () => stream_get_meta_data($stream));
        if (($metadata['wrapper_type'] ?? null) !== 'plainfile' || ($metadata['stream_type'] ?? null) !== 'STDIO'
            || ($metadata['uri'] ?? null) !== $path) {
            throw new UnexpectedValueException;
        }
    }

    private function io(MemberOriginalIoFrame $idle, MemberOriginalBudget $budget, callable $operation): mixed
    {
        $idle->assertStillIdle();
        $budget->assertCurrent();
        $result = $operation();
        $idle->assertStillIdle();
        $budget->assertCurrent();

        return $result;
    }
}
