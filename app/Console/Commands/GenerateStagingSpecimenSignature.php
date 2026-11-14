<?php

namespace App\Console\Commands;

use App\Domain\Contracts\Production\StagingSpecimenSignature;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Draws the STAGING specimen signature image (DD-27, ledger PCL-06) into the private local disk and prints the two
 * environment lines that select it. Local, testing and staging only. It never replaces an installed file, never edits
 * the environment and needs no input: run it as the user that owns private storage. Production refuses the command.
 */
final class GenerateStagingSpecimenSignature extends Command
{
    protected $signature = 'vasey:staging-specimen-signature {--check : Generate and verify in memory, report, and install nothing}';

    protected $description = 'Draw the STAGING SPECIMEN signature image in private storage (local, testing or staging only).';

    public function handle(): int
    {
        if (! StagingSpecimenSignature::admitted()) {
            return $this->refuse('The staging specimen signature is generated only when APP_ENV is local, testing or staging and matches the running environment. Production uses Sean\'s signature.');
        }
        try {
            $configured = config('contracts.vasey_license_v1.signature_path');
            $disk = Storage::disk('local')->path('');
            $root = realpath($disk);
            $public = realpath(public_path());
            if ($root === false || $root === '/' || ($public !== false && ($root === $public || str_starts_with($root.'/', $public.'/')))) {
                return $this->refuse('Private storage is missing or is under public/.');
            }
            if (is_string($configured) && $configured !== '' && $configured !== StagingSpecimenSignature::PATH
                && file_exists($root.'/'.$configured)) {
                return $this->refuse('Another signature image is installed and configured (VASEY_LICENSE_SIGNATURE_PATH). This command never replaces or overrides a signature.');
            }
            $png = StagingSpecimenSignature::png();
            $sha256 = hash('sha256', $png);
            $info = @getimagesizefromstring($png);
            if (! is_array($info) || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1
                || $info[0] > VaseyLicenseSignature::MAX_PIXELS || $info[1] > VaseyLicenseSignature::MAX_PIXELS
                || strlen($png) > VaseyLicenseSignature::MAX_BYTES) {
                return $this->refuse('The generated image does not satisfy the signature loader\'s limits.');
            }
            $target = $root.'/'.StagingSpecimenSignature::PATH;
            $directory = dirname($target);
            if (is_link($directory) || is_link($target) || (file_exists($target) && ! is_file($target))) {
                return $this->refuse('The specimen location in private storage is a link or not a regular file; fix the storage layout first.');
            }
            if (is_file($target)) {
                if (! hash_equals($sha256, (string) hash_file('sha256', $target))) {
                    return $this->refuse('A different file is already at '.StagingSpecimenSignature::PATH.'. A changed signature changes the licence profile pin: remove that file deliberately, then re-run.');
                }
                $this->line('Already installed and unchanged.');
            } elseif ($this->option('check')) {
                $this->line('Check only: the specimen can be generated here; nothing was installed.');
            } else {
                $this->install($png, $directory, $target, $sha256);
                Log::notice('Staging specimen signature installed.', ['sha256' => $sha256]);
                $this->line('Installed.');
            }
        } catch (Throwable $error) {
            Log::warning('Staging specimen signature generation failed.', ['exception_class' => $error::class]);

            return $this->refuse('The staging specimen signature could not be generated or installed.');
        }
        $this->warn('STAGING ONLY: a labelled specimen, not a signature. Production refuses it (vasey:cutover-check PCL-06). Sean installs his own PNG on the production host.');
        $this->line('Set these two lines in the site environment, then deploy so the workers reload:');
        $this->line('VASEY_LICENSE_SIGNATURE_PATH='.StagingSpecimenSignature::PATH);
        $this->line('VASEY_LICENSE_SIGNATURE_SHA256='.$sha256);
        $matches = $configured === StagingSpecimenSignature::PATH && is_string(config('contracts.vasey_license_v1.signature_sha256'))
            && hash_equals($sha256, config('contracts.vasey_license_v1.signature_sha256'));
        $this->line($matches ? 'The environment already selects this specimen.' : 'The environment does not select this specimen yet.');

        return self::SUCCESS;
    }

    /** Write beside the target under an unguessable name, owner-only, then rename: the file appears whole or not at all. */
    private function install(string $png, string $directory, string $target, string $sha256): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700)) {
            throw new \RuntimeException('directory');
        }
        $temporary = $directory.'/.staging-specimen-'.Str::uuid().'.tmp';
        try {
            $handle = fopen($temporary, 'xb');
            if ($handle === false || ! chmod($temporary, 0600) || fwrite($handle, $png) !== strlen($png) || ! fflush($handle) || ! fclose($handle)) {
                throw new \RuntimeException('write');
            }
            if (! hash_equals($sha256, (string) hash_file('sha256', $temporary)) || file_exists($target) || ! rename($temporary, $target)) {
                throw new \RuntimeException('install');
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
