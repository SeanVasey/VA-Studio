<?php

namespace App\Domain\Inquiries\Notifications;

use App\Domain\Notifications\Mail\TransactionalMailPolicy;
use App\Domain\Notifications\NotificationException;
use App\Support\Environment\TestEnvironment;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

/**
 * Mail-backed operator alert for the durable inquiry intent outbox (FP-013). Default-off: it is bound only while
 * CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED is true, an inquiry operator is configured and the environment is admitted,
 * and it hands a message to a transport only when the reviewed order-ready mail configuration (TransactionalMailPolicy) is
 * admitted as well.
 *
 * The outbox is the delivery ledger: it claims an intent once, retries only after InquiryAlertNotSubmitted and never resends
 * an uncertain or expired handoff. So this class reports InquiryAlertNotSubmitted only for a refusal that happens before any
 * transport call; an error raised by the send itself propagates and the outbox records the intent as unknown.
 */
final class MailInquiryAlertTransport implements InquiryAlertTransport
{
    /** Per network operation, kept well below NotifyInquiryOperatorJob's 30-second timeout and the 120-second claim lease. */
    public const TRANSPORT_TIMEOUT_SECONDS = 10;

    /**
     * Environment admission, checked at binding and again at every send. Local, testing and staging are admitted; production
     * only with the code literal `inquiries.operator_alert_mail_production_admitted` set to an exact `true`, which happens only
     * in the reviewed change that lifts the production pre-launch hold. Every other environment refuses.
     */
    public static function admitted(): bool
    {
        return TestEnvironment::admitsTestCommerce()
            || (app()->environment() === 'production' && config('inquiries.operator_alert_mail_production_admitted') === true);
    }

    /** The only binding of the transport. Returns whether it was bound; nothing is bound by default. */
    public static function bindWhenConfigured(Application $app): bool
    {
        $config = $app->make('config');
        $operator = $config->get('inquiries.operator_user_id');
        // No configured operator means no recipient: bind nothing rather than fall back to any other address.
        if ($config->get('inquiries.operator_notifications_enabled') !== true || ! self::admitted()
            || ! is_scalar($operator) || preg_match('/\A[1-9][0-9]{0,15}\z/D', (string) $operator) !== 1) {
            return false;
        }
        $app->bind(InquiryAlertTransport::class, self::class);

        return true;
    }

    public function submit(OperatorInquiryAlert $alert): void
    {
        // A value that is not a receipt is never written to a log.
        $receipt = preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $alert->receipt) === 1;
        try {
            if (config('inquiries.operator_notifications_enabled') !== true || ! self::admitted() || ! $receipt) {
                throw new NotificationException('alert_not_admitted');
            }
            $configuration = app(TransactionalMailPolicy::class)->configuration();
            $recipient = $this->recipient($alert->operatorId);
            $mailer = Mail::mailer($configuration['mailer']);
            // A global recipient override would send the alert, and its inquiry reference, somewhere else: refuse instead.
            if (config('mail.to') !== null || $this->redirected($mailer)) {
                throw new NotificationException('recipient_override_configured');
            }
            $message = new InquiryAlertMail($alert->receipt, $alert->subject(), $configuration['sender']);
        } catch (Throwable $error) {
            // Nothing reached a transport. The log names the inquiry reference and the refusal only, never an address.
            Log::warning('Inquiry alert was not submitted.', ($receipt ? ['inquiry' => $alert->receipt] : []) + ['exception_class' => $error::class]
                + ($error instanceof NotificationException ? ['reason' => $error->reason] : []));

            throw new InquiryAlertNotSubmitted;
        }
        $stream = $mailer instanceof Mailer && $mailer->getSymfonyTransport() instanceof SmtpTransport
            ? $mailer->getSymfonyTransport()->getStream() : null;
        $timeout = $stream instanceof SocketStream ? $stream->getTimeout() : null;
        try {
            // The mailer is shared with order-ready mail, so the shorter timeout applies to this send only.
            if ($timeout !== null) {
                $stream->setTimeout(min($timeout, (float) self::TRANSPORT_TIMEOUT_SECONDS));
            }
            $mailer->to($recipient)->send($message);
        } catch (Throwable $error) {
            // A transport error after the attempt began cannot prove the provider did not accept the message.
            Log::warning('Inquiry alert outcome is uncertain.', ['inquiry' => $alert->receipt, 'exception_class' => $error::class]);

            throw $error;
        } finally {
            if ($timeout !== null) {
                $stream->setTimeout($timeout);
            }
        }
    }

    /** The verified email of the staff account the outbox proved authority for; there is no other recipient. */
    private function recipient(int $operatorId): string
    {
        $operator = DB::table('users')->where('id', $operatorId)->first(['email', 'email_verified_at']);
        $email = $operator?->email;
        if ($operator === null || $operator->email_verified_at === null || ! is_string($email) || strlen($email) > 254
            || preg_match('/[\x00-\x20\x7f,;<>"]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new NotificationException('operator_address_missing');
        }

        return $email;
    }

    /** True when the resolved mailer carries a global recipient (Mailer::alwaysTo), however it was set. */
    private function redirected(object $mailer): bool
    {
        return $mailer instanceof Mailer && ! empty((new ReflectionProperty(Mailer::class, 'to'))->getValue($mailer));
    }
}
