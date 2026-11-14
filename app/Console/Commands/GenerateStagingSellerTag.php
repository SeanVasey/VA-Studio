<?php

namespace App\Console\Commands;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\StagingSellerTag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates and installs the synthetic STAGING seller tag in private storage and prints the two environment lines that
 * select it. Local, testing and staging only. It never replaces an installed tag, never edits the environment and needs
 * no input: run it as the user that owns private storage. Production refuses the command, the path and the file.
 */
final class GenerateStagingSellerTag extends Command
{
    protected $signature = 'vasey:staging-seller-tag {--check : Generate and verify in a scratch directory, report, and install nothing}';

    protected $description = 'Generate the synthetic STAGING seller tag in private storage (local, testing or staging only).';

    public function handle(PrivateMediaFiles $files, StagingSellerTag $tag): int
    {
        if (! StagingSellerTag::admitted()) {
            return $this->refuse('The synthetic staging seller tag is generated only when APP_ENV is local, testing or staging and matches the running environment. Production uses the approved seller tag.');
        }
        foreach (['ffmpeg', 'ffprobe', 'prlimit'] as $tool) {
            $binary = config('media.'.$tool);
            if (! is_string($binary) || ! is_executable($binary)) {
                return $this->refuse("The media tool {$tool} is unavailable (config media.{$tool}).");
            }
        }
        $workspace = null;
        try {
            $configured = config('media.tag_path');
            if (is_string($configured) && $configured !== StagingSellerTag::PATH && $this->installed($files, $configured)) {
                return $this->refuse('Another seller tag is installed and configured (MEDIA_TAG_PATH). This command never replaces or overrides a seller tag.');
            }
            $workspace = $files->workspace();
            $candidate = $tag->generate($workspace);
            $tag->assertUsable($candidate);
            $sha256 = (string) hash_file('sha256', $candidate);
            $target = $files->root().'/'.StagingSellerTag::PATH;
            $directory = dirname($target);
            if (is_link($directory) || is_link($target) || (file_exists($target) && ! is_file($target))) {
                return $this->refuse('The staging tag location in private storage is a link or not a regular file; fix the storage layout first.');
            }
            if (is_file($target)) {
                if (! hash_equals($sha256, (string) hash_file('sha256', $target))) {
                    return $this->refuse('A different file is already at '.StagingSellerTag::PATH.'. A changed tag is a changed processing profile: remove that file deliberately, then re-run.');
                }
                $this->line('Already installed and unchanged.');
            } elseif ($this->option('check')) {
                $this->line('Check only: the tag can be generated here; nothing was installed.');
            } else {
                $this->install($candidate, $directory, $target, $sha256);
                Log::notice('Staging synthetic seller tag installed.', ['sha256' => $sha256]);
                $this->line('Installed.');
            }
        } catch (MediaFailure $failure) {
            return $this->refuse('The staging seller tag could not be generated ('.$failure->failureCode.').');
        } catch (Throwable $error) {
            Log::warning('Staging seller tag generation failed.', ['exception_class' => $error::class]);

            return $this->refuse('The staging seller tag could not be generated or installed.');
        } finally {
            if ($workspace !== null) {
                $files->cleanup($workspace);
            }
        }
        $this->warn('STAGING ONLY: a synthetic chime, not the approved seller tag. Production refuses this file. Replace it before launch (docs/ops/staging-placeholders.md).');
        $this->line('Set these two lines in the site environment, then deploy so the workers reload:');
        $this->line('MEDIA_TAG_PATH='.StagingSellerTag::PATH);
        $this->line('MEDIA_TAG_SHA256='.$sha256);
        $matches = $configured === StagingSellerTag::PATH && is_string(config('media.tag_sha256')) && hash_equals($sha256, config('media.tag_sha256'));
        $this->line($matches ? 'The environment already selects this tag.' : 'The environment does not select this tag yet.');

        return self::SUCCESS;
    }

    private function installed(PrivateMediaFiles $files, string $relative): bool
    {
        try {
            $files->resolve($relative);

            return true;
        } catch (MediaFailure $failure) {
            // A placeholder or absent path is not an installed tag; an unsafe storage layout is not this command's to judge around.
            if (in_array($failure->failureCode, ['unsafe_path', 'missing_source'], true) && ! $this->pathIsLink($files, $relative)) {
                return false;
            }
            throw $failure;
        }
    }

    private function pathIsLink(PrivateMediaFiles $files, string $relative): bool
    {
        return preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_./-]*\z~D', $relative) === 1 && ! str_contains($relative, '..')
            && is_link($files->root().'/'.$relative);
    }

    /** Copy beside the target under an unguessable name, owner-only, then rename: the tag appears whole or not at all. */
    private function install(string $candidate, string $directory, string $target, string $sha256): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700)) {
            throw new MediaFailure('storage_failed', 'The staging tag directory could not be created.');
        }
        $temporary = $directory.'/.staging-tag-'.Str::uuid().'.tmp';
        try {
            $handle = fopen($temporary, 'xb');
            if ($handle === false || ! chmod($temporary, 0600) || fwrite($handle, (string) file_get_contents($candidate)) !== filesize($candidate)
                || ! fflush($handle) || ! fclose($handle)) {
                throw new MediaFailure('storage_failed', 'The staging tag could not be written.');
            }
            if (! hash_equals($sha256, (string) hash_file('sha256', $temporary)) || file_exists($target) || ! rename($temporary, $target)) {
                throw new MediaFailure('storage_failed', 'The staging tag could not be installed.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function refuse(string $message): int
    {
        $this->error('Refused: '.$message);

        return self::FAILURE;
    }
}
