<?php

namespace App\Support\Diagnostics;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteImageReferences;
use App\Models\User;
use App\Support\Environment\TestEnvironment;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only installation checks, but for one scratch directory of private storage that the scanner limits check makes and removes
 * again, and makes only for the user that owns private storage. Never connect to payment, mail or storage providers.
 */
final class InstallationReport
{
    /**
     * @param  ?\Closure(): ?int  $effectiveUserId  the user this process runs as, null when that cannot be told; a test gives another
     */
    public function __construct(private readonly ?\Closure $effectiveUserId = null) {}

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
        // Staging is a hosted installation too, so it meets the same URL, debug and cookie baseline as production.
        $hosted = app()->isProduction() || TestEnvironment::refusesProductionOnly();
        $check('production_settings', true, fn () => ! $hosted || (! config('app.debug') && str_starts_with((string) config('app.url'), 'https://') && config('session.secure') === true),
            'Basic environment-specific URL, debug and cookie settings pass.', 'In production and staging use HTTPS, APP_DEBUG=false and SESSION_SECURE_COOKIE=true.');
        if (TestEnvironment::refusesProductionOnly()) {
            $check('staging_test_mode_only', true, $this->stagingTestModeOnly(...),
                'Staging is configured for Stripe test mode only, with no live key, live production-checkout funds or production customer identity.',
                'In staging set STRIPE_MODE=test with an sk_test_ key or none, and leave production checkout live funds, live keys and production customer identity unconfigured.');
        }

        $check('media_tools', false, fn () => $this->executable('media.ffmpeg') && $this->executable('media.ffprobe') && $this->executable('media.prlimit'),
            'Media executables exist; processing and worker isolation still need acceptance.', 'Install and configure ffmpeg, ffprobe and prlimit before media processing.');
        $check('media_encoders', false, $this->hasMediaEncoders(...),
            'FFmpeg advertises the required audio and image encoders; actual processing still needs acceptance.',
            'Install and configure FFmpeg with libmp3lame, pcm_s16le, png, mjpeg and libwebp, plus prlimit; rerun vasey:doctor as the worker user before media processing.');
        $check('media_scanner', false, fn () => $this->executable('media.clamscan'),
            'The scanner executable exists; signatures and detection are unverified.', 'Install ClamAV and complete signature/detection acceptance before media promotion.');
        // clamscan gets its size limits from the application; a resident clamd has to alert on a file over its own. The canary is made
        // where every scan makes its own, in a workspace of private storage: a temporary directory is often tmpfs, which keeps holes in a
        // file where the private disk may not.
        $check('media_scanner_limits', false, function () {
            $root = config('filesystems.disks.local.root');
            // Nothing is made where private storage does not exist, which the private_storage check reports, or for a user who does not
            // own it. A run that a signal ends (SIGINT, SIGKILL) skips the cleanup below, and what it leaves, processing/, a workspace and
            // the canary, belongs to whoever ran it. For root that is a processing/ in which a worker of another user cannot make its own
            // workspace, and every media run then fails until someone deletes it. The owner of private storage is the worker's user.
            if (! is_string($root) || ! is_dir($root) || @fileowner($root) !== $this->currentUser()) {
                return false;
            }
            $files = app(PrivateMediaFiles::class);
            $base = $files->root().'/processing';
            $existed = is_dir($base);
            $workspace = null;
            try {
                $workspace = $files->workspace();
                app(MalwareScanner::class)->confirmLimits($workspace);
            } finally {
                // A workspace that could not be made is not there to remove, and the directory made for it must not stay behind.
                if ($workspace !== null) {
                    $files->cleanup($workspace);
                }
                if (! $existed) {
                    // This run made processing/, and leaves private storage as it found it.
                    @rmdir($base);
                }
            }

            return true;
        }, 'The scanner refuses a file over its size limits.',
            'With clamdscan, set MaxFileSize and MaxScanSize to '.MalwareScanner::limitMebibytes().'M and AlertExceedsMax yes in clamd.conf and restart clamd; otherwise install ClamAV and prlimit first. The check makes a sparse file of 4 GiB in a scratch directory of private storage, so that storage must keep holes in a file, the worker may write a file that large, and the PHP posix extension must be there to tell. Run vasey:doctor as the user that owns private storage, the worker\'s.');
        $check('seller_tag', false, fn () => is_string(config('media.tag_path')) && preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_./-]*\z~D', config('media.tag_path'))
            && ! str_contains(config('media.tag_path'), '..') && ! str_contains(config('media.tag_path'), '//')
            && is_string(config('media.tag_sha256')) && preg_match('/\A[a-f0-9]{64}\z/D', config('media.tag_sha256')),
            'Seller tag settings are present; file content and audible approval are unverified.', 'Configure the approved private seller tag and its SHA-256 using Media operations.');
        $check('media_queue', false, fn () => in_array(config('queue.default'), ['database', 'redis', 'sqs', 'beanstalkd'], true),
            'An asynchronous queue is selected; worker liveness and recovery are unverified.', 'Select and run the documented asynchronous media queue before processing real uploads.');
        $check('mail_transport', false, fn () => in_array(config('mail.default'), ['smtp', 'ses', 'postmark', 'resend', 'mailgun'], true),
            'An outbound mail transport is selected; delivery is unverified.', 'Configure and test outbound transactional mail before customer email workflows.');
        // A pending schedule more than two minutes past its time means the every-minute scheduler is not running or keeps failing.
        $check('scheduled_publication', false, fn () => ! DB::table('site_publication_schedules')->where('state', 'pending')
            ->where('publish_at', '<', now()->subMinutes(2))->exists(),
            'No scheduled site publication is overdue.', 'A scheduled site publication is overdue. Run `php artisan schedule:run` every minute from cron and check the application log; a run more than 60 minutes after its time records it as expired, unpublished.');

        // A damaged stored file of an image in the active release is already missing from the live site; each file is hashed.
        $check('site_images', false, function (): bool {
            $release = SiteRelease::find(DB::table('site_publications')->where('id', 1)->value('active_release_id'));
            if (in_array($release?->schema_version, [3, 4], true) && is_array($release->content)) {
                app(SiteImageReferences::class)->verifyFiles($release->content);
            }

            return true;
        }, 'The stored files of the active site release’s images match their recorded hashes.',
            'A stored file of an image in the active site release failed its integrity check, so visitors see its description instead. Restore `storage/app/private/site-images/` from the same backup as the database, or publish a release that uses another image.');

        $profile = match (true) {
            app()->isProduction() => 'production',
            TestEnvironment::refusesProductionOnly() => TestEnvironment::STAGING,
            default => 'development',
        };

        return ['schema_version' => 1, 'scope' => 'installation', 'profile' => $profile, 'foundation_ready' => ! in_array('fail', array_column($checks, 'status'), true), 'checks' => $checks];
    }

    /** Configuration shape only: no key is used, logged or reported, and no provider is contacted. */
    private function stagingTestModeOnly(): bool
    {
        $secret = config('payments.stripe.secret_key');
        $productionSecret = config('production_checkout.secret_key');

        return config('payments.stripe.mode') === 'test'
            && ($secret === null || $secret === '' || (is_string($secret) && preg_match('/\Ask_test_[A-Za-z0-9]{8,200}\z/D', $secret) === 1))
            && config('production_checkout.funds_mode') !== 'live'
            && ! (is_string($productionSecret) && preg_match('/\A(?:sk|rk)_live_/', $productionSecret) === 1)
            && config('production-customer-identity.provenance') !== IdentityPolicy::PRODUCTION;
    }

    /** The user this process runs as; null without the PHP posix extension, which is never the owner of anything. */
    private function currentUser(): ?int
    {
        if ($this->effectiveUserId !== null) {
            return ($this->effectiveUserId)();
        }

        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    private function executable(string $key): bool
    {
        $path = config($key);

        return is_string($path) && is_file($path) && is_executable($path);
    }

    private function hasMediaEncoders(): bool
    {
        if (! $this->executable('media.ffmpeg') || ! $this->executable('media.prlimit')) {
            return false;
        }

        $listing = app(BoundedMediaProcess::class)->run([config('media.ffmpeg'), '-hide_banner', '-encoders'], base_path(), 15);
        // Read encoder identifiers from FFmpeg's six-flag rows, never names mentioned in descriptions or the legend.
        preg_match_all('/^[ \t]*[AVS][F.][S.][X.][B.][D.][ \t]+([a-zA-Z0-9_-]+)(?:[ \t]|$)/m', $listing, $matches);

        return array_diff(['libmp3lame', 'pcm_s16le', 'png', 'mjpeg', 'libwebp'], $matches[1]) === [];
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
