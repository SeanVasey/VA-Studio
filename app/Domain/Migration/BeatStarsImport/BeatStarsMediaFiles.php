<?php

namespace App\Domain\Migration\BeatStarsImport;

/**
 * Resolves the absolute paths the BeatStars export lists to files under one import root, where the operator has copied
 * them with their relative path preserved (`<root><listed path>`). Resolution is an exact match and never searches: the
 * listed path must be absolute and lexically clean, carry the extension its role allows, and name a regular, non-empty file
 * whose every component is a real directory entry (no symlink) inside the root. Anything else is a typed outcome, never a guess.
 */
final class BeatStarsMediaFiles
{
    /** Extensions each listed column may carry, lower case. */
    public const EXTENSIONS = [
        'master_wav' => ['wav'],
        'stems_zip' => ['zip'],
        'preview_mp3' => ['mp3'],
        'artwork' => ['png', 'jpg', 'jpeg'],
        'artwork_high_res' => ['png', 'jpg', 'jpeg'],
    ];

    /** Intake caps (IngestMediaUpload::MAX_BYTES): WAV 200 MiB, stems ZIP 1 GiB, artwork 20 MiB; the MP3 preview keeps 200 MiB. */
    private const MAX_BYTES = ['master_wav' => 209715200, 'stems_zip' => 1073741824, 'artwork' => 20971520, 'artwork_high_res' => 20971520, 'preview_mp3' => 209715200];

    private function __construct(private readonly string $root) {}

    /** The root must be a canonical absolute directory owned by the running user and writable by nobody else. */
    public static function open(string $root): self
    {
        $stat = str_starts_with($root, '/') && $root !== '/' && ! str_ends_with($root, '/') && ! preg_match('/[\x00-\x1f\x7f]/', $root)
            ? @lstat($root) : false;
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || realpath($root) !== $root
            || ! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0022) !== 0
            || ! is_readable($root)) {
            throw new BeatStarsImportRefusal('The media root must be a canonical absolute directory (no symlink) owned by the running user, not writable by group or others, and readable.');
        }

        return new self($root);
    }

    /**
     * @return array{state: 'ok', path: string, bytes: int, identity: array}|array{state: 'not_listed'}|array{state: 'missing'}|array{state: 'refused', code: string}
     */
    public function resolve(string $role, string $listed): array
    {
        if (! isset(self::EXTENSIONS[$role])) {
            return ['state' => 'refused', 'code' => 'unknown_role'];
        }
        if (trim($listed) === '') {
            return ['state' => 'not_listed'];
        }
        if ($listed !== trim($listed) || preg_match('/[\x00-\x1f\x7f\\\\]/', $listed) === 1 || ! mb_check_encoding($listed, 'UTF-8')) {
            return ['state' => 'refused', 'code' => 'path_invalid'];
        }
        if (! str_starts_with($listed, '/')) {
            return ['state' => 'refused', 'code' => 'path_not_absolute'];
        }
        $components = explode('/', substr($listed, 1));
        foreach ($components as $component) {
            if (in_array($component, ['', '.', '..'], true)) {
                return ['state' => 'refused', 'code' => 'path_traversal'];
            }
        }
        if (! in_array(strtolower(pathinfo($listed, PATHINFO_EXTENSION)), self::EXTENSIONS[$role], true)) {
            return ['state' => 'refused', 'code' => 'wrong_extension'];
        }
        $cursor = $this->root;
        $last = count($components) - 1;
        foreach ($components as $index => $component) {
            $cursor .= '/'.$component;
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            if ($stat === false) {
                return ['state' => 'missing'];
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0120000) {
                return ['state' => 'refused', 'code' => 'symlink'];
            }
            if ($index < $last && $type !== 0040000) {
                return ['state' => 'missing'];
            }
            if ($index === $last && $type !== 0100000) {
                return ['state' => 'refused', 'code' => 'not_regular_file'];
            }
        }
        if (realpath($cursor) !== $cursor || ! str_starts_with($cursor, $this->root.'/')) {
            return ['state' => 'refused', 'code' => 'outside_root'];
        }
        if ($stat['size'] < 1) {
            return ['state' => 'refused', 'code' => 'empty_file'];
        }
        if ($stat['nlink'] !== 1) {
            return ['state' => 'refused', 'code' => 'hardlink'];
        }
        if ($stat['size'] > self::MAX_BYTES[$role]) {
            return ['state' => 'refused', 'code' => 'too_large'];
        }
        if (! is_readable($cursor)) {
            return ['state' => 'refused', 'code' => 'unreadable'];
        }

        return ['state' => 'ok', 'path' => $cursor, 'bytes' => $stat['size'], 'identity' => $this->identity($stat)];
    }

    /** True while the file still has the identity it was resolved with. */
    public function unchanged(array $resolved): bool
    {
        clearstatcache(true, $resolved['path']);
        $stat = @lstat($resolved['path']);

        return $stat !== false && $this->identity($stat) === $resolved['identity'];
    }

    private function identity(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }
}
