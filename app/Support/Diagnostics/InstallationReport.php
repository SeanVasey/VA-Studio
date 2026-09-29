<?php

namespace App\Support\Diagnostics;

use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only installation checks. Never connect to payment, mail or storage providers. */
final class InstallationReport
{
    public function collect(): array
    {
        $checks = [];
        $check = function (string $id, bool $required, callable $probe, string $pass, string $remedy) use (&$checks): void {
            try {
                $ok = $probe();
            } catch (Throwable) {
                // Connection errors and configuration values may contain credentials or private paths.
                $ok = false;
            }
            $checks[] = ['id' => $id, 'status' => $ok ? 'pass' : ($required ? 'fail' : 'warn'), 'message' => $ok ? $pass : $remedy];
        };

        $check('php_runtime', true, fn () => PHP_VERSION_ID >= 80400
            && array_filter(['pdo', 'pdo_sqlite', 'pdo_mysql', 'mbstring', 'intl', 'bcmath', 'gd', 'fileinfo', 'zip', 'curl'], fn (string $extension) => ! extension_loaded($extension)) === [],
            'PHP 8.4+ and all documented extensions are available.', 'Install the PHP version and extensions documented in README.');
        $check('application_key', true, function () {
            $key = config('app.key');
            if (! is_string($key) || $key === '') {
                return false;
            }
            $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

            return is_string($decoded) && Encrypter::supported($decoded, config('app.cipher'));
        }, 'An encryption key of the expected format is configured.', 'Supply APP_KEY through the host configuration; generate one only for a new installation. Do not rotate an existing key blindly.');
        $check('database', true, fn () => in_array(DB::getDriverName(), ['mysql', 'sqlite'], true) && DB::select('select 1') !== [],
            'The configured database answered a read-only query.', 'Check the configured database driver, access and availability.');
        $check('migrations', true, function () {
            $migrator = app('migrator');

            return $migrator->repositoryExists() && array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()) === [];
        }, 'All repository migrations are recorded as applied.', 'Run php artisan migrate after checking database access and the deployment instructions.');
        $check('operator', true, fn () => User::query()->where('is_admin', true)->whereNotNull('email_verified_at')->exists(),
            'At least one verified operator is provisioned.', 'Run php artisan vasey:create-admin from a trusted interactive console.');
        $check('runtime_directories', true, function () {
            foreach ([app()->bootstrapPath('cache'), storage_path('framework/views'), storage_path('framework/sessions'), storage_path('logs')] as $path) {
                if (! is_dir($path) || ! is_writable($path)) {
                    return false;
                }
            }

            return true;
        }, 'Runtime cache, view, session and log directories are writable.', 'Create the documented runtime directories and grant access to the application user.');
        $check('frontend_build', true, $this->hasFrontendBuild(...),
            'The Vite manifest and its listed files exist.', 'Run npm ci and npm run build; deploy the complete public/build directory.');
        $check('private_storage', true, function () {
            $disk = config('filesystems.disks.local');
            $path = $disk['root'] ?? null;
            $root = is_string($path) ? realpath($path) : false;
            $public = realpath(public_path());

            return ($disk['driver'] ?? null) === 'local' && ! ($disk['serve'] ?? false) && ($disk['visibility'] ?? 'private') !== 'public'
                && $root && $public && ! is_link($path) && is_dir($root) && is_writable($root)
                && $root !== $public && ! str_starts_with($root, $public.DIRECTORY_SEPARATOR);
        }, 'Local media storage is unserved, writable and outside the public directory.', 'Configure a writable private local media directory outside public, without serving or symlinking it.');
        $check('production_settings', true, fn () => ! app()->isProduction() || (! config('app.debug') && str_starts_with((string) config('app.url'), 'https://') && config('session.secure') === true),
            'Basic environment-specific URL, debug and cookie settings pass.', 'In production use HTTPS, APP_DEBUG=false and SESSION_SECURE_COOKIE=true.');

        $check('media_tools', false, fn () => $this->executable('media.ffmpeg') && $this->executable('media.ffprobe') && $this->executable('media.prlimit'),
            'Media executables exist; processing and worker isolation still need acceptance.', 'Install and configure ffmpeg, ffprobe and prlimit before media processing.');
        $check('media_scanner', false, fn () => $this->executable('media.clamscan'),
            'The scanner executable exists; signatures and detection are unverified.', 'Install ClamAV and complete signature/detection acceptance before media promotion.');
        $check('seller_tag', false, fn () => is_string(config('media.tag_path')) && preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_./-]*\z~D', config('media.tag_path'))
            && ! str_contains(config('media.tag_path'), '..') && ! str_contains(config('media.tag_path'), '//')
            && is_string(config('media.tag_sha256')) && preg_match('/\A[a-f0-9]{64}\z/D', config('media.tag_sha256')),
            'Seller tag settings are present; file content and audible approval are unverified.', 'Configure the approved private seller tag and its SHA-256 using Media operations.');
        $check('media_queue', false, fn () => in_array(config('queue.default'), ['database', 'redis', 'sqs', 'beanstalkd'], true),
            'An asynchronous queue is selected; worker liveness and recovery are unverified.', 'Select and run the documented asynchronous media queue before processing real uploads.');
        $check('mail_transport', false, fn () => in_array(config('mail.default'), ['smtp', 'ses', 'postmark', 'resend', 'mailgun'], true),
            'An outbound mail transport is selected; delivery is unverified.', 'Configure and test outbound transactional mail before customer email workflows.');
        // A pending schedule more than two minutes past its time means the every-minute scheduler is not running.
        $check('scheduled_publication', false, fn () => ! DB::table('site_publication_schedules')->where('state', 'pending')
            ->where('publish_at', '<', now()->subMinutes(2))->exists(),
            'No scheduled site publication is overdue.', 'A scheduled site publication is overdue. Run `php artisan schedule:run` every minute from cron; it expires unpublished 60 minutes after its time.');

        return ['schema_version' => 1, 'scope' => 'installation', 'foundation_ready' => ! in_array('fail', array_column($checks, 'status'), true), 'checks' => $checks];
    }

    private function executable(string $key): bool
    {
        $path = config($key);

        return is_string($path) && is_file($path) && is_executable($path);
    }

    private function hasFrontendBuild(): bool
    {
        $directory = public_path('build');
        $path = $directory.'/manifest.json';
        if (! is_file($path) || filesize($path) > 2097152) {
            return false;
        }
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! isset($manifest['resources/js/app.tsx'])) {
            return false;
        }
        $root = realpath($directory);
        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! is_string($entry['file'] ?? null) || ! is_array($entry['css'] ?? [])) {
                return false;
            }
            foreach ([$entry['file'], ...($entry['css'] ?? [])] as $file) {
                if (! is_string($file) || str_contains($file, '..') || ! preg_match('~\A[a-zA-Z0-9_./-]+\z~D', $file)) {
                    return false;
                }
                $real = realpath($directory.'/'.$file);
                if (! $real || ! is_file($real) || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                    return false;
                }
            }
        }

        return true;
    }
}
