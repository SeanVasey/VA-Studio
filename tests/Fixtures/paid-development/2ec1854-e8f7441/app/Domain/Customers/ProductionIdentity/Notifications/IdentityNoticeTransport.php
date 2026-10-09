<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

/** Root-owned binding; implementations declare an exact provenance and reviewed capability. */
interface IdentityNoticeTransport
{
    public function provenance(): string;

    public function capabilityVersion(): string;

    public function submit(IdentityMail $mail): IdentityAcceptance;
}
