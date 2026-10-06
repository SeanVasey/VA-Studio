<?php

namespace App\Domain\Notifications;

use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Storage;
use SensitiveParameter;

/** Exclusive private local files only. No notification, mail, network or queue channel is invoked. */
class PrivateNotificationCapture
{
    /** A known refusal is returned only before opening or writing a message. Other failures are ambiguous. */
    public function store(string $notificationId, #[SensitiveParameter] string $capture): array
    {
        $this->validate($notificationId, $capture);
        TransactionalNotificationPolicy::outsideTransactions();
        app(TransactionalNotificationPolicy::class)->requireEnabled();
        try {
            $root = $this->root(create: true);
        } catch (NotificationException $error) {
            if ($error->reason !== 'private_storage_refused') {
                throw $error;
            }

            return ['status' => 'not_accepted', 'reason' => 'private_storage_refused'];
        }
        $path = $root.'/'.$notificationId.'.json';
        if (@lstat($path) !== false) {
            return ['status' => 'accepted', 'receiptHash' => $this->read($root, $path, $capture)];
        }
        // x-mode never replaces an existing canonical capture, including a partial crash file.
        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            if (@lstat($path) !== false) {
                return ['status' => 'accepted', 'receiptHash' => $this->read($root, $path, $capture)];
            }
            throw new NotificationException('capture_unknown');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new NotificationException('capture_unknown');
            }
            $this->file($root, $path, fstat($stream));
            $offset = 0;
            while ($offset < strlen($capture)) {
                $written = fwrite($stream, substr($capture, $offset));
                if ($written === false || $written === 0) {
                    throw new NotificationException('capture_unknown');
                }
                $offset += $written;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new NotificationException('capture_unknown');
            }
            $this->file($root, $path, fstat($stream));
        } finally {
            fclose($stream);
        }

        return ['status' => 'accepted', 'receiptHash' => $this->read($root, $path, $capture)];
    }

    /** Positive inspection is the only uncertain recovery. Absence never proves a send did not occur. */
    public function inspect(string $notificationId, #[SensitiveParameter] string $capture): ?string
    {
        $this->validate($notificationId, $capture);
        TransactionalNotificationPolicy::outsideTransactions();
        app(TransactionalNotificationPolicy::class)->requireEnabled();
        $root = $this->root(create: false);
        if ($root === null) {
            return null;
        }
        $path = $root.'/'.$notificationId.'.json';
        if (@lstat($path) === false) {
            return null;
        }

        return $this->read($root, $path, $capture);
    }

    private function validate(string $id, #[SensitiveParameter] string $capture): void
    {
        if (! OrderRequest::uuid($id) || strlen($capture) > TransactionalNotificationPolicy::MAX_CAPTURE_BYTES) {
            throw new NotificationException('capture_unknown');
        }
        try {
            $value = json_decode($capture, true, 8, JSON_THROW_ON_ERROR);
            $keys = is_array($value) ? array_keys($value) : [];
            sort($keys);
            $payload = $value['payload'] ?? null;
            $payloadKeys = is_array($payload) ? array_keys($payload) : [];
            sort($payloadKeys);
            if ($keys !== ['notification_id', 'payload', 'purpose', 'recipient', 'schema_version']
                || ($value['schema_version'] ?? null) !== 1 || ($value['notification_id'] ?? null) !== $id
                || ($value['purpose'] ?? null) !== 'test_transactional_notification_capture'
                || ! is_array($value['recipient'] ?? null) || array_keys($value['recipient']) !== ['email']
                || ! is_string($value['recipient']['email'] ?? null)
                || CustomerIdentityPolicy::email($value['recipient']['email']) !== $value['recipient']['email']
                || $payloadKeys !== ['account_id', 'activation_id', 'order_id', 'schema_version', 'template_version', 'test_only', 'type']
                || ($payload['schema_version'] ?? null) !== 1 || ($payload['type'] ?? null) !== 'test_order_ready'
                || ($payload['test_only'] ?? null) !== true || ($payload['template_version'] ?? null) !== TransactionalNotificationPolicy::TEMPLATE
                || ! OrderRequest::uuid($payload['account_id'] ?? '') || ! OrderRequest::uuid($payload['activation_id'] ?? '')
                || ! OrderRequest::uuid($payload['order_id'] ?? '') || CanonicalJson::encode($value) !== $capture) {
                throw new NotificationException('capture_unknown');
            }
        } catch (\Throwable) {
            throw new NotificationException('capture_unknown');
        }
    }

    private function root(bool $create): ?string
    {
        if (config('filesystems.disks.local.driver') !== 'local'
            || config('filesystems.disks.local.visibility', 'private') !== 'private') {
            throw new NotificationException('private_storage_refused');
        }
        $base = rtrim(Storage::disk('local')->path(''), '/');
        if ($base === '' || $base[0] !== '/' || realpath($base) !== $base) {
            throw new NotificationException('private_storage_refused');
        }
        $cursor = '';
        foreach (explode('/', ltrim($base, '/')) as $component) {
            $cursor .= '/'.$component;
            if ($component === '' || is_link($cursor) || ! is_dir($cursor)) {
                throw new NotificationException('private_storage_refused');
            }
        }
        $root = $base.'/transactional-notification-capture';
        if (@lstat($root) === false) {
            if (! $create) {
                return null;
            }
            if (! mkdir($root, 0700) || ! chmod($root, 0700)) {
                throw new NotificationException('private_storage_refused');
            }
        }
        $stat = @lstat($root);
        if ($stat === false || is_link($root) || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0777) !== 0700 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || realpath($root) !== $root) {
            throw new NotificationException('private_storage_refused');
        }

        return $root;
    }

    private function read(string $root, string $path, #[SensitiveParameter] string $expected): string
    {
        $before = @lstat($path);
        $this->file($root, $path, $before);
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new NotificationException('capture_unknown');
        }
        try {
            $opened = fstat($stream);
            $this->file($root, $path, $opened);
            if ($opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) {
                throw new NotificationException('capture_unknown');
            }
            $actual = stream_get_contents($stream, TransactionalNotificationPolicy::MAX_CAPTURE_BYTES + 1);
            $this->file($root, $path, fstat($stream));
            if (! is_string($actual) || ! hash_equals($expected, $actual)) {
                throw new NotificationException('capture_unknown');
            }
        } finally {
            fclose($stream);
        }

        return hash('sha256', $actual);
    }

    private function file(string $root, string $path, array|false $stat): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if ($stat === false || $current === false || is_link($path) || realpath($path) !== $path
            || dirname($path) !== $root || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            || $stat['nlink'] !== 1 || $stat['size'] > TransactionalNotificationPolicy::MAX_CAPTURE_BYTES
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || $current['dev'] !== $stat['dev'] || $current['ino'] !== $stat['ino']
            || $current['mode'] !== $stat['mode'] || $current['nlink'] !== 1) {
            throw new NotificationException('capture_unknown');
        }
    }
}
