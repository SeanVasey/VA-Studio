<?php

namespace App\Domain\Inquiries\Notifications;

use RuntimeException;

/** Only an adapter certain that no handoff occurred may report this typed failure. */
final class InquiryAlertNotSubmitted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Inquiry alert was definitely not submitted.');
    }
}
