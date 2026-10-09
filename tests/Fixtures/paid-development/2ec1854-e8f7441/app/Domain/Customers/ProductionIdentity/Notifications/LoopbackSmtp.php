<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Support\CanonicalJson;
use Throwable;

/** Actual SMTP protocol rehearsal. No DNS, authentication, external addresses or live provenance. */
final readonly class LoopbackSmtp implements IdentityNoticeTransport
{
    public const CAPABILITY = 'loopback-smtp-identity-v1';

    public function __construct(private int $port, private string $sender = 'identity@vaseyaudio.test')
    {
        if ($port < 1024 || $port > 65535 || ! preg_match('/\A[a-z0-9._+-]+@[a-z0-9.-]+\.test\z/D', $sender)) {
            throw new IdentityException;
        }
    }

    public function provenance(): string
    {
        return IdentityPolicy::REHEARSAL;
    }

    public function capabilityVersion(): string
    {
        return self::CAPABILITY;
    }

    public function submit(IdentityMail $mail): IdentityAcceptance
    {
        if (! app()->environment('local', 'testing') || $mail->provenance !== IdentityPolicy::REHEARSAL
            || ! preg_match('/\A[a-z0-9._+-]+@[a-z0-9.-]+\.test\z/D', $mail->recipient())) {
            throw new DefinitelyNotSubmitted;
        }
        $message = 'From: '.$this->sender."\r\n".'To: '.$mail->recipient()."\r\n".'Message-ID: <'.$mail->noticeId."@vaseyaudio.test>\r\n"
            .'Subject: '.($mail->purpose === 'enroll' ? 'Complete your VaseyAudio account' : 'Recover your VaseyAudio account')."\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            ."Use this private link within ten minutes. If you did not request it, ignore this message.\r\n".$mail->url()."\r\n";
        $dataStarted = false;
        $socket = false;
        try {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$this->port, $errorCode, $errorText, 5, STREAM_CLIENT_CONNECT);
            if (! is_resource($socket)) {
                throw new DefinitelyNotSubmitted;
            }
            stream_set_timeout($socket, 5);
            $this->reply($socket, 220);
            $this->command($socket, 'EHLO vaseyaudio.test', 250);
            $this->command($socket, 'MAIL FROM:<'.$this->sender.'>', 250);
            $this->command($socket, 'RCPT TO:<'.$mail->recipient().'>', 250);
            $this->command($socket, 'DATA', 354);
            $dataStarted = true; // From the first attempted DATA write, a lost response is ambiguous.
            $wire = preg_replace('/(?m)^\./', '..', $message).".\r\n";
            $this->write($socket, $wire);
            $this->reply($socket, 250);

            return new IdentityAcceptance(CanonicalJson::hash(['capability' => self::CAPABILITY, 'notice_id' => $mail->noticeId, 'message_hash' => hash('sha256', $message), 'submission' => 'smtp_250_accepted']));
        } catch (Throwable) {
            if (! $dataStarted) {
                throw new DefinitelyNotSubmitted;
            }
            // The worker records unknown; private SMTP replies/address/link are not propagated.
            throw new IdentityException('transport_uncertain');
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function command($socket, string $command, int $code): void
    {
        $this->write($socket, $command."\r\n");
        $this->reply($socket, $code);
    }

    private function write($socket, string $bytes): void
    {
        for ($offset = 0; $offset < strlen($bytes);) {
            $count = @fwrite($socket, substr($bytes, $offset));
            if (! is_int($count) || $count < 1) {
                throw new IdentityException;
            }
            $offset += $count;
        }
    }

    private function reply($socket, int $code): void
    {
        for ($line = 0; $line < 32; $line++) {
            $reply = @fgets($socket, 1025);
            if (! is_string($reply) || strlen($reply) > 1024 || ! preg_match('/\A([0-9]{3})([- ])[^\r\n]*\r\n\z/D', $reply, $match) || (int) $match[1] !== $code) {
                throw new IdentityException;
            }
            if ($match[2] === ' ') {
                return;
            }
        }
        throw new IdentityException;
    }
}
