<?php

namespace App\Domain\Inquiries\Notifications;

/** A trusted processor binding is separate from this provider-independent outbox. */
interface InquiryAlertTransport
{
    /** Returning means only handoff acceptance. Delivery cannot be inferred from it. */
    public function submit(OperatorInquiryAlert $alert): void;
}
