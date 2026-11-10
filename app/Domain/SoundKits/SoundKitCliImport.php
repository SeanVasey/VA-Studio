<?php

namespace App\Domain\SoundKits;

use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\Environment\TestEnvironment;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * The narrow, explicit console entry for importing a kit ZIP that is already on the host (`vasey:sound-kit-import`).
 *
 * It adds no new intake rules. After the environment, actor and path guards below it copies the operator's file into a
 * private workspace, wraps that copy as the same upload object the Filament form hands over and calls
 * `SoundKitIntake::handle()` unchanged, so the web path keeps refusing stored-file paths. Source retention, revision creation,
 * the `wav-sample-kit-zip-v1` profile, the 200 MiB ceiling, staff authority and MFA (re-read under lock) and the
 * `sound_kit.revision.*` audit events are all the intake's and the processor's own.
 *
 * The operator's file is only read: it is never moved, rewritten or deleted.
 */
final class SoundKitCliImport
{
    /** Directory under the private disk root that an operator may stage a kit in. */
    public const PRIVATE_DIRECTORY = 'kit-imports';

    /** The only other directory a kit may be staged in. Files must be direct children of an allowed directory. */
    public const TEMP_DIRECTORY = '/tmp';

    /**
     * @param  (Closure(): ?int)|null  $effectiveUid  Resolves the effective uid of the running process, or null when it
     *                                                cannot be established. Defaults to `posix_geteuid()`; tests bind a
     *                                                fixed answer because a non-root runner cannot chown a fixture.
     */
    public function __construct(private readonly ?Closure $effectiveUid = null) {}

    /**
     * Local, testing or staging only. Production is refused first, whether it is the configured environment
     * (`config('app.env')`, which a cached configuration keeps) or the running one, and the two must agree because
     * Artisan's `--env` changes only the running environment. These are configuration checks, not proof of host identity.
     */
    public function admit(): void
    {
        $configured = config('app.env');
        if ($configured === 'production' || app()->environment('production') || app()->isProduction()) {
            $this->refuse('The kit CLI import is refused in production. Use the admin upload there.');
        }
        if (! is_string($configured) || $configured !== app()->environment()) {
            $this->refuse('The configured environment (APP_ENV) and the running environment differ, for example through --env; the kit CLI import refuses an ambiguous environment.');
        }
        if (! TestEnvironment::admitsTestCommerce()) {
            $this->refuse('The kit CLI import runs only when APP_ENV is local, testing or staging.');
        }
    }

    /** An existing staff account with catalog authority that satisfies the admin panel's MFA rule as it stands here. */
    public function actor(mixed $email): User
    {
        if (! is_string($email) || $email === '') {
            $this->refuse('Give --actor=<email of an existing staff account>.');
        }
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->refuse('No staff account has that --actor email. Create it first with `php artisan vasey:create-admin`.');
        }
        if (! Gate::forUser($user)->allows('administer-catalog')) {
            $this->refuse('The --actor account lacks catalog authority (an administrator with a verified email).');
        }
        if (! AdminMultiFactor::satisfiedBy($user)) {
            $this->refuse("The --actor account does not satisfy the admin panel's MFA rule here. Sign in at /admin as that account and enroll the authenticator app first.");
        }

        return $user;
    }

    public function draft(mixed $publicId): SoundKitDraft
    {
        $draft = is_string($publicId) && preg_match('/\A[0-9a-f-]{36}\z/D', $publicId)
            ? SoundKitDraft::query()->where('public_id', $publicId)->first() : null;
        if ($draft === null) {
            $this->refuse('No sound kit draft has that --draft public id.');
        }

        return $draft;
    }

    /**
     * Validates the operator's file without opening its contents: an absolute, canonical path (no `..`, `//` or symlinked
     * directory), a direct child of an allowed directory, a regular, single-link, non-group/world-writable file owned by this
     * process's user or root, a `.zip` name the web upload would also accept, and a size within the profile ceiling. Nothing is copied here.
     *
     * @return array{path: string, name: string, size: int, device: int, inode: int}
     */
    public function source(mixed $given): array
    {
        if (! is_string($given) || $given === '' || str_contains($given, "\0") || $given[0] !== '/') {
            $this->refuse('Give the absolute path of the kit ZIP, for example /tmp/kit.zip.');
        }
        $directory = realpath(dirname($given));
        $name = basename($given);
        if ($directory === false || $given !== rtrim($directory, '/').'/'.$name) {
            $this->refuse('The path must be canonical: no "..", "//", "." or symbolic-link directory components.');
        }
        if (! in_array($directory, $this->allowedDirectories(), true)) {
            $this->refuse('The kit ZIP must be a direct child of '.self::TEMP_DIRECTORY.' or of the private '.self::PRIVATE_DIRECTORY.' directory on the private disk.');
        }
        $stat = @lstat($given);
        if ($stat === false) {
            $this->refuse('The kit ZIP does not exist.');
        }
        if (($stat['mode'] & 0170000) === 0120000) {
            $this->refuse('The kit ZIP is a symbolic link. Copy the real file instead.');
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            $this->refuse('The kit ZIP is not a regular file.');
        }
        // Fail closed: without POSIX the owner cannot be established, so the file is not admitted.
        $euid = $this->effectiveUid();
        if ($euid === null) {
            $this->refuse('The file owner cannot be verified on this host (the PHP posix extension is unavailable), so the kit CLI import refuses every file.');
        }
        if ($stat['uid'] !== 0 && $stat['uid'] !== $euid) {
            $this->refuse('The kit ZIP must be owned by root or by the user running this command.');
        }
        if ($stat['nlink'] > 1) {
            $this->refuse('The kit ZIP has more than one hard link. Copy the real file to a fresh name instead.');
        }
        if (($stat['mode'] & 0022) !== 0) {
            $this->refuse('The kit ZIP must not be group- or world-writable (chmod 600 or 644).');
        }
        if (! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 180 || ! preg_match('/\.zip\z/iD', $name)
            || preg_match('~[\\\\/\x00-\x1f\x7f]~', $name)) {
            $this->refuse('The file name must be valid UTF-8, at most 180 characters and end in .zip.');
        }
        $limit = app(SoundKitArchiveProfile::class)->current()['source_max_bytes'];
        if ($stat['size'] < 22 || $stat['size'] > $limit) {
            $this->refuse('The kit ZIP must be a complete ZIP of at most '.$limit.' bytes ('.intdiv($limit, 1048576).' MiB).');
        }

        return ['path' => $given, 'name' => $name, 'size' => $stat['size'], 'device' => $stat['dev'], 'inode' => $stat['ino']];
    }

    /** Read-only archive inspection under the current profile, as the intake runs it before retaining anything. */
    public function inspect(array $source): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source['path']);
        app(SoundKitArchive::class)->inspect($source['path'], (string) $mime, app(SoundKitArchiveProfile::class)->current());
    }

    /**
     * Creates the draft (or appends to `$draft`), hands a private copy to the unchanged intake and runs the processor the queued
     * job would run. Always returns the draft, even when the intake or the processor then fails, so the operator can re-run
     * with `--draft`.
     *
     * @param  array{title?: string, description?: string, provenance?: string}  $metadata  Used only when `$draft` is null.
     * @return array{draft: SoundKitDraft, created: bool, revision: SoundKitRevision|null, error: \Throwable|null}
     */
    public function import(User $actor, array $source, ?SoundKitDraft $draft, array $metadata): array
    {
        // Defense in depth: the command admits first, but this entry must not rely on its caller.
        $this->admit();
        $created = false;
        if ($draft === null) {
            $draft = app(SoundKitDrafts::class)->save(null, $metadata, $actor);
            $created = true;
        }
        $files = app(PrivateMediaFiles::class);
        $workspace = null;
        try {
            $workspace = $files->workspace();
            $upload = $this->stage($source, $workspace);
            $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, $draft->version, $actor);
            AuditEvent::record('sound_kit.cli_import', $revision, ['transport' => 'console', 'number' => $revision->number,
                'source_sha256' => $revision->source_sha256, 'size_bytes' => $revision->source_size_bytes, 'draft_created' => $created,
                'environment' => app()->environment()], $actor->id);
            // The same processor the queued job calls; its claim makes a concurrent worker harmless.
            $revision = app(SoundKitProcessor::class)->handle($revision->id);

            return ['draft' => $draft->fresh(), 'created' => $created, 'revision' => $revision, 'error' => null];
        } catch (\Throwable $error) {
            return ['draft' => $draft->fresh(), 'created' => $created, 'revision' => null, 'error' => $error];
        } finally {
            if ($workspace !== null) {
                $files->cleanup($workspace);
            }
        }
    }

    /** Copies the validated file (bounded, same inode as validated) into the private workspace and wraps the copy. */
    private function stage(array $source, string $workspace): UploadedFile
    {
        $input = @fopen($source['path'], 'rb');
        $opened = $input ? fstat($input) : false;
        if (! $opened || ($opened['mode'] & 0170000) !== 0100000 || $opened['ino'] !== $source['inode'] || $opened['dev'] !== $source['device']
            || $opened['size'] !== $source['size']) {
            if (is_resource($input)) {
                fclose($input);
            }
            $this->refuse('The kit ZIP changed after it was checked. Nothing was imported.');
        }
        $copy = $workspace.'/upload.zip';
        $output = @fopen($copy, 'xb');
        try {
            if (! $output || stream_copy_to_stream($input, $output, $source['size'] + 1) !== $source['size'] || ! fflush($output)
                || ! chmod($copy, 0600) || filesize($copy) !== $source['size']) {
                $this->refuse('The kit ZIP changed or could not be copied to private storage. Nothing was imported.');
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }

        // The "test" flag only skips PHP's is_uploaded_file() check, which no console file can satisfy.
        return new UploadedFile($copy, $source['name'], 'application/zip', null, true);
    }

    /** @return list<string> */
    private function allowedDirectories(): array
    {
        $allowed = [];
        foreach ([self::TEMP_DIRECTORY, app(PrivateMediaFiles::class)->root().'/'.self::PRIVATE_DIRECTORY] as $directory) {
            // A symlinked staging directory is never admitted, as the private workspace refuses one.
            if ($directory !== self::TEMP_DIRECTORY && is_link($directory)) {
                continue;
            }
            $real = realpath($directory);
            if ($real !== false && is_dir($real)) {
                $allowed[] = $real;
            }
        }

        return $allowed;
    }

    private function effectiveUid(): ?int
    {
        if ($this->effectiveUid !== null) {
            return ($this->effectiveUid)();
        }

        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    private function refuse(string $message): never
    {
        throw new SoundKitCliImportRefusal($message);
    }
}
