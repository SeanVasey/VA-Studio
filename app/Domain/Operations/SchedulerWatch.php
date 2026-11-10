<?php

namespace App\Domain\Operations;

use App\Domain\Notifications\Mail\TransactionalMailPolicy;
use App\Domain\Notifications\NotificationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use RuntimeException;
use Throwable;

/**
 * The scheduler's dead-man's switch (FP-028). It runs from a host cron job, never from the scheduler it watches: the scheduler
 * records a heartbeat row every minute, and this reads the newest one and, when it is stale, sends one operator email per
 * stale episode and one note when the heartbeat resumes.
 *
 * Default-off: with no `reporting.scheduler_alert.recipient` it only reports staleness and sends nothing. A message goes out
 * only through the reviewed transactional-mail configuration (TransactionalMailPolicy), which refuses in local and testing
 * unless the framework mail fake is installed. The episode state is a small file under private storage (not the database, so
 * dedupe still works while the database is the thing that is down), updated under an exclusive lock so overlapping cron runs
 * cannot send twice. A message whose handoff fails is retried on later runs, at most MAX_ATTEMPTS times per episode.
 */
final class SchedulerWatch
{
    /** Seconds without a heartbeat before the scheduler counts as stopped: three missed cron checks of five minutes. */
    public const DEFAULT_MAX_AGE = 900;

    /** A heartbeat this far in the future is clock skew, not proof the scheduler runs (as in OperationalHealth). */
    public const CLOCK_SKEW_SECONDS = 60;

    public const MAX_ATTEMPTS = 3;

    /** Per network operation, so a stuck relay cannot hold the cron job for long. */
    private const TRANSPORT_TIMEOUT_SECONDS = 10;

    /** @return array{status: 'fresh'|'stale', reason: string, last: ?string, age: ?int} */
    public function assess(int $maxAge, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $last = DB::table('scheduler_heartbeats')->where('task', OperationalSignals::HEARTBEAT_TASK)->value('last_success_at');
        if (! is_string($last) || $last === '') {
            return ['status' => 'stale', 'reason' => 'none_recorded', 'last' => null, 'age' => null];
        }
        $age = $now->timestamp - CarbonImmutable::parse($last, 'UTC')->timestamp;
        if ($age < -self::CLOCK_SKEW_SECONDS) {
            return ['status' => 'stale', 'reason' => 'future_dated', 'last' => $last, 'age' => $age];
        }
        // Exactly the limit is still fresh, like the dashboard's "older than" rule.
        $age = max(0, $age);

        return $age > $maxAge
            ? ['status' => 'stale', 'reason' => 'too_old', 'last' => $last, 'age' => $age]
            : ['status' => 'fresh', 'reason' => 'ok', 'last' => $last, 'age' => $age];
    }

    /**
     * Assesses and, when $notify, advances the episode state and sends at most one message for the transition.
     *
     * @return array{assessment: array, action: 'none'|'disabled'|'alerted'|'alert_failed'|'alert_given_up'|'recovered'|'recovery_failed'}
     *
     * @throws RuntimeException when the episode state cannot be locked or written (nothing is sent then)
     */
    public function run(int $maxAge, bool $notify, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $assessment = $this->assess($maxAge, $now);
        $recipient = self::recipient();
        if (! $notify || $recipient === null) {
            return ['assessment' => $assessment, 'action' => $notify ? 'disabled' : 'none'];
        }

        $handle = $this->openState();
        try {
            $state = $this->readState($handle);
            $action = 'none';
            if ($assessment['status'] === 'stale') {
                if ($state['state'] !== 'stale') {
                    $state = ['state' => 'stale', 'since' => $now->format('Y-m-d H:i:s'), 'alerted' => false, 'attempts' => 0];
                }
                if (! $state['alerted']) {
                    if ($state['attempts'] >= self::MAX_ATTEMPTS) {
                        $action = 'alert_given_up';
                    } else {
                        $state['attempts']++;
                        $this->writeState($handle, $state);  // count the attempt first: a crash mid-send must not loop forever
                        $state['alerted'] = $this->send(new SchedulerWatchMail('stale', self::siteHost(), $assessment['last'], $assessment['age'], $state['since'], ''), $recipient);
                        $action = $state['alerted'] ? 'alerted' : 'alert_failed';
                    }
                }
            } elseif ($state['state'] === 'stale') {
                // Only an episode that was announced gets a recovery note. A failed note is not retried.
                $action = $state['alerted']
                    ? ($this->send(new SchedulerWatchMail('recovered', self::siteHost(), $assessment['last'], $assessment['age'], $state['since'], ''), $recipient) ? 'recovered' : 'recovery_failed')
                    : 'none';
                $state = ['state' => 'ok', 'since' => $now->format('Y-m-d H:i:s'), 'alerted' => false, 'attempts' => 0];
            }
            $this->writeState($handle, $state);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return ['assessment' => $assessment, 'action' => $action];
    }

    /** The configured recipient, or null (alerting disabled) when unset or not a plain lowercase address. */
    public static function recipient(): ?string
    {
        $value = config('reporting.scheduler_alert.recipient');

        return is_string($value) && TransactionalMailPolicy::address($value) ? $value : null;
    }

    private static function siteHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : (string) config('app.env');
    }

    /** True when the message was handed to the transport. Never logs an address or a message body. */
    private function send(SchedulerWatchMail $mail, string $recipient): bool
    {
        try {
            $configuration = app(TransactionalMailPolicy::class)->configuration();
            $mailer = $this->mailer($configuration['mailer']);
            // A global recipient override would send the notice somewhere else: refuse instead.
            if (config('mail.to') !== null) {
                throw new NotificationException('recipient_override_configured');
            }
            $mailer->to($recipient)->send(new SchedulerWatchMail($mail->kind, $mail->siteHost, $mail->lastHeartbeat, $mail->ageSeconds, $mail->episodeStart, $configuration['sender']));

            return true;
        } catch (Throwable $error) {
            Log::warning('Scheduler watch notice was not sent.', ['kind' => $mail->kind, 'exception_class' => $error::class]
                + ($error instanceof NotificationException ? ['reason' => $error->reason] : []));

            return false;
        }
    }

    /** A mailer built for this notice alone from the configured mailer's settings with the timeout capped; the framework fake in tests. */
    private function mailer(string $name): object
    {
        $mail = Mail::getFacadeRoot();
        if ($mail instanceof MailFake) {
            return $mail->mailer($name);
        }
        $settings = config('mail.mailers.'.$name);
        if (! is_array($settings)) {
            throw new NotificationException('mail_not_configured');
        }
        $configured = $settings['timeout'] ?? null;
        $timeout = is_numeric($configured) && $configured > 0 ? min((float) $configured, (float) self::TRANSPORT_TIMEOUT_SECONDS) : (float) self::TRANSPORT_TIMEOUT_SECONDS;

        return $mail->build(array_merge($settings, ['name' => $name.'-scheduler-watch', 'timeout' => $timeout]));
    }

    private function statePath(): string
    {
        $path = config('reporting.scheduler_alert.state_path');

        return is_string($path) && str_starts_with($path, '/') ? $path : storage_path('app/private/ops-state/scheduler-watch.json');
    }

    /** @return resource an exclusively locked handle on the state file */
    private function openState()
    {
        $path = $this->statePath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('state_unavailable');
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('state_unavailable');
        }

        return $handle;
    }

    /** @return array{state: string, since: string, alerted: bool, attempts: int} an unreadable file counts as no episode */
    private function readState($handle): array
    {
        rewind($handle);
        $decoded = json_decode((string) stream_get_contents($handle), true);
        if (! is_array($decoded) || ! in_array($decoded['state'] ?? null, ['ok', 'stale'], true) || ! is_string($decoded['since'] ?? null)
            || ! is_bool($decoded['alerted'] ?? null) || ! is_int($decoded['attempts'] ?? null)) {
            return ['state' => 'ok', 'since' => '', 'alerted' => false, 'attempts' => 0];
        }

        return ['state' => $decoded['state'], 'since' => $decoded['since'], 'alerted' => $decoded['alerted'], 'attempts' => $decoded['attempts']];
    }

    private function writeState($handle, array $state): void
    {
        if (! ftruncate($handle, 0) || fseek($handle, 0) !== 0 || fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) === false || ! fflush($handle)) {
            throw new RuntimeException('state_unavailable');
        }
    }
}
