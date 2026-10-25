<?php

namespace App\Domain\Notifications\Mail;

use App\Domain\Notifications\NotificationException;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use JsonException;

/**
 * Refuses unless an operator has configured a sender and an SMTP mailer that match facts which passed the existing
 * read-only email preflight (scripts/ops/email-operations-preflight.py, operator mode). The binding is declared, not
 * observed: nothing here proves DNS, TLS, credentials, provider account scope or delivery.
 */
final class TransactionalMailPolicy
{
    public const TEMPLATE = 'order-ready-mail-v1';

    public const MAX_FILE_BYTES = 32768;

    /** The operator-mode checks the existing preflight reports, in its own order. */
    public const PREFLIGHT_CHECKS = ['closed_versioned_schema', 'candidate_shape', 'planned_https_origin', 'literal_disabled_unbound_baseline',
        'planned_identity_smtp_scope', 'planned_suppression_separate_scope', 'queued_wakeup_configuration', 'bounded_identity_recovery',
        'declared_supervision_and_schedule_references', 'append_only_no_purge', 'declared_retention_references'];

    /** @return array{mailer: string, sender: string, capability: string} */
    public function configuration(): array
    {
        $refuse = fn (): never => throw new NotificationException('mail_not_configured');
        if (config('transactional-mail.enabled') !== true) {
            $refuse();
        }
        if (app()->environment('local', 'testing') && ! Mail::getFacadeRoot() instanceof MailFake) {
            // Local and test runs never reach a real transport: only the framework's fake records messages.
            throw new NotificationException('mail_not_faked');
        }
        $name = config('transactional-mail.mailer');
        $mailer = is_string($name) && preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $name) === 1 ? config('mail.mailers.'.$name) : null;
        $sender = config('transactional-mail.sender');
        if (! is_array($mailer) || ($mailer['transport'] ?? null) !== 'smtp' || ! empty($mailer['url'])
            || ! is_string($mailer['host'] ?? null) || ! self::domain($mailer['host']) || preg_match('/\.(?:test|invalid|localhost)\z/D', $mailer['host']) === 1
            || ! is_int($port = filter_var($mailer['port'] ?? null, FILTER_VALIDATE_INT)) || $port < 1 || $port > 65535
            || ! in_array($mailer['scheme'] ?? null, [null, '', 'smtp', 'smtps'], true)
            || ! is_string($sender) || ! self::address($sender) || preg_match('/\.(?:test|invalid|localhost)\z/D', $sender) === 1) {
            $refuse();
        }
        $preflight = config('transactional-mail.preflight');
        if (! is_array($preflight)) {
            $refuse();
        }
        $facts = $this->read($preflight['facts_path'] ?? null, $preflight['facts_sha256'] ?? null);
        $receipt = $this->read($preflight['receipt_path'] ?? null, $preflight['receipt_sha256'] ?? null);
        $checks = is_array($receipt['checks'] ?? null) ? $receipt['checks'] : [];
        if (($receipt['schema_version'] ?? null) !== 1 || ($receipt['scope'] ?? null) !== 'operator_declared_configuration_facts'
            || ($receipt['result'] ?? null) !== 'DECLARED_FACTS_VALID' || ($receipt['declared_values_are_not_observations'] ?? null) !== true
            || array_column($checks, 'id') !== self::PREFLIGHT_CHECKS || array_unique(array_column($checks, 'status')) !== ['pass']) {
            $refuse();
        }
        $planned = is_array($facts['planned_identity_smtp'] ?? null) ? $facts['planned_identity_smtp'] : [];
        $security = ($mailer['scheme'] ?? null) === 'smtps' ? 'implicit_tls' : 'starttls';
        if (($facts['schema_version'] ?? null) !== 1 || ($planned['sender'] ?? null) !== $sender || ($planned['host'] ?? null) !== $mailer['host']
            || ($planned['port'] ?? null) !== $port || ($planned['security'] ?? null) !== $security) {
            $refuse();
        }

        return ['mailer' => $name, 'sender' => $sender, 'capability' => CanonicalJson::hash([
            'template' => self::TEMPLATE, 'mailer' => $name, 'host' => $mailer['host'], 'port' => $port, 'security' => $security,
            'sender' => $sender, 'facts_sha256' => $preflight['facts_sha256'], 'receipt_sha256' => $preflight['receipt_sha256'],
        ])];
    }

    /** One bounded regular file, never a link, whose bytes match the pinned hash; returns its decoded JSON object. */
    private function read(mixed $path, mixed $hash): array
    {
        $refuse = fn (): never => throw new NotificationException('mail_not_configured');
        if (! is_string($path) || ! str_starts_with($path, '/') || ! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            $refuse();
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 2 || $stat['size'] > self::MAX_FILE_BYTES) {
            $refuse();
        }
        $bytes = @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);
        if (! is_string($bytes) || strlen($bytes) > self::MAX_FILE_BYTES || ! hash_equals($hash, hash('sha256', $bytes))) {
            $refuse();
        }
        try {
            $decoded = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $refuse();
        }

        return is_array($decoded) ? $decoded : $refuse();
    }

    public static function address(string $value): bool
    {
        return strlen($value) <= 254 && preg_match('/\A[a-z0-9._+-]+@([^@]+)\z/D', $value, $match) === 1 && self::domain($match[1]);
    }

    private static function domain(string $value): bool
    {
        return strlen($value) <= 253 && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $value) === 1;
    }
}
