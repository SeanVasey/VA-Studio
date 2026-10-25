<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Synthetic operator facts checked by the real read-only email preflight (scripts/ops/email-operations-preflight.py).
 * Every value is invented; no provider, sender domain or credential is real, and no message leaves the mail fake.
 */
final class TransactionalMailFixtures
{
    public const MAILER = 'cx_smtp';

    public const SENDER = 'orders@example.com';

    public const HOST = 'smtp.example.com';

    public static function facts(array $planned = []): array
    {
        $facts = json_decode((string) file_get_contents(base_path('ops/email-operations/facts.example.json')), true, 16, JSON_THROW_ON_ERROR);
        $facts['candidate_sha'] = str_repeat('a', 40);
        $facts['public_origin'] = 'https://store.example.com';
        $facts['planned_identity_smtp'] = $planned + ['provider_reference' => 'provider.synthetic', 'account_scope_reference' => 'account.synthetic',
            'host' => self::HOST, 'port' => 587, 'security' => 'starttls', 'sender' => self::SENDER, 'hello_domain' => 'store.example.com',
            'revision' => '0f7c2b1e-4d3a-4b5c-9d8e-7f6a5b4c3d2e', 'host_secret_reference' => 'secret.synthetic', 'sender_review_reference' => 'review.synthetic'];
        foreach ($facts['planned_suppression'] as $key => $value) {
            $facts['planned_suppression'][$key] = 'suppression.'.str_replace('_', '-', $key);
        }
        foreach (['worker_supervision_reference', 'identity_recovery_schedule_reference', 'scheduler_observation_reference'] as $key) {
            $facts['operations'][$key] = 'operations.'.str_replace('_', '-', $key);
        }
        $facts['retention']['policy_reference'] = 'retention.synthetic';
        $facts['retention']['log_policy_reference'] = 'retention.logs-synthetic';

        return $facts;
    }

    /** Writes the facts (mode 0600), runs the real preflight on them, writes its report, and configures the adapter. */
    public static function configure(array $planned = [], array $mailer = []): array
    {
        $directory = storage_path('framework/testing/mail-preflight-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $directory = realpath($directory);
        $factsPath = $directory.'/facts.json';
        file_put_contents($factsPath, json_encode(self::facts($planned), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($factsPath, 0600);
        $process = new Process(['python3', base_path('scripts/ops/email-operations-preflight.py'), '--facts', $factsPath], base_path(), null, null, 30);
        $process->run();
        $report = $process->getOutput();
        $decoded = json_decode($report, true, 16, JSON_THROW_ON_ERROR);
        if ($process->getExitCode() !== 0 || ($decoded['result'] ?? null) !== 'DECLARED_FACTS_VALID') {
            throw new RuntimeException('Synthetic email facts did not pass the preflight: '.$report.$process->getErrorOutput());
        }
        $receiptPath = $directory.'/preflight.json';
        file_put_contents($receiptPath, $report);
        chmod($receiptPath, 0600);
        config([
            'mail.mailers.'.self::MAILER => $mailer + ['transport' => 'smtp', 'scheme' => null, 'url' => null, 'host' => self::HOST, 'port' => 587,
                'username' => 'synthetic', 'password' => 'synthetic-not-a-secret', 'timeout' => 5, 'local_domain' => 'store.example.com'],
            'transactional-mail.enabled' => true, 'transactional-mail.mailer' => self::MAILER, 'transactional-mail.sender' => self::SENDER,
            'transactional-mail.preflight' => ['facts_path' => $factsPath, 'facts_sha256' => hash_file('sha256', $factsPath),
                'receipt_path' => $receiptPath, 'receipt_sha256' => hash_file('sha256', $receiptPath)],
        ]);

        return ['directory' => $directory, 'facts' => $factsPath, 'receipt' => $receiptPath, 'report' => $decoded];
    }
}
