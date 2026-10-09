<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Support\CanonicalJson;
use JsonSerializable;
use LogicException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** Production-capable required-TLS SMTP. Default binding/activation are separately root-owned. */
final class ConfiguredSmtpIdentityTransport implements IdentityNoticeTransport, JsonSerializable
{
    private IdentityEsmtpClient $client;

    private bool $used = false;

    public function __construct(private readonly SmtpIdentitySettings $settings)
    {
        $this->client = $settings->client();
    }

    public function provenance(): string
    {
        return $this->settings->provenance;
    }

    public function capabilityVersion(): string
    {
        return $this->settings->capabilityVersion();
    }

    public function submit(IdentityMail $mail): IdentityAcceptance
    {
        if ($this->used || ! $this->settings->accepts($mail)) {
            throw new DefinitelyNotSubmitted;
        }
        $this->used = true; // Root's container binding must be transient; one claim gets one client.
        try {
            $sender = new Address($this->settings->sender());
            $recipient = new Address($mail->recipient());
            $message = (new Email)->from($sender)->to($recipient)
                ->subject($mail->purpose === 'enroll' ? 'Complete your VaseyAudio account' : 'Recover your VaseyAudio account')
                ->text("Use this private link within ten minutes. If you did not request it, ignore this message.\n".$mail->url()."\n");
            $message->getHeaders()->addIdHeader('Message-ID', $mail->noticeId.'@'.substr($this->settings->sender(), strpos($this->settings->sender(), '@') + 1));
            $sent = $this->client->send($message, new Envelope($sender, [$recipient]));
            if ($sent === null || ! $this->client->dataAdmitted()) {
                throw new IdentityException;
            }

            return new IdentityAcceptance(CanonicalJson::hash(['capability' => $this->capabilityVersion(), 'notice_id' => $mail->noticeId,
                'message_hash' => hash('sha256', $sent->toString()), 'submission' => 'smtp_250_accepted']));
        } catch (Throwable) {
            // Symfony debug/reply objects may contain private mail/authentication data. Never propagate them.
            if (! $this->client->dataAdmitted()) {
                throw new DefinitelyNotSubmitted;
            }
            throw new IdentityException('transport_uncertain');
        } finally {
            try {
                $this->client->stop();
            } catch (Throwable) {
                // Closing after acceptance cannot relabel submission; no private diagnostic escapes.
            } finally {
                try {
                    $this->client->getStream()->terminate();
                } catch (Throwable) {
                }
            }
        }
    }

    public function __debugInfo(): array
    {
        return ['provenance' => $this->provenance(), 'capability' => $this->capabilityVersion()];
    }

    public function __serialize(): never
    {
        throw new LogicException('Private SMTP transport must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Private SMTP transport is not an HTTP projection.');
    }
}
