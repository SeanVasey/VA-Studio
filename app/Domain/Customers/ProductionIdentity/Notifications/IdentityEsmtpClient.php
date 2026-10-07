<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use LogicException;
use SensitiveParameter;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Internal fixed Symfony client; never injected with application listeners/loggers/authenticators. */
final class IdentityEsmtpClient extends EsmtpTransport
{
    private bool $dataAdmitted = false;

    private bool $authenticated = false;

    public function executeCommand(#[SensitiveParameter] string $command, array $codes): string
    {
        if ($command === "DATA\r\n" && ! $this->authenticated) {
            throw new IdentityException;
        }
        $reply = parent::executeCommand($command, $codes);
        if (in_array(235, $codes, true)) {
            $this->authenticated = true;
        }
        if ($command === "DATA\r\n") {
            // From this point Symfony may write DATA. Every later error is conservatively unknown.
            $this->dataAdmitted = true;
        }

        return $reply;
    }

    public function dataAdmitted(): bool
    {
        return $this->dataAdmitted;
    }

    public function __debugInfo(): array
    {
        return ['smtp_data_admitted' => $this->dataAdmitted];
    }

    public function __serialize(): never
    {
        throw new LogicException('Private SMTP client must not be serialized.');
    }
}
