<?php

namespace App\Domain\Inquiries\Notifications;

/** No inquiry body or recipient address crosses this processor boundary. */
final readonly class OperatorInquiryAlert
{
    public function __construct(public int $operatorId, public string $receipt) {}

    public function subject(): string
    {
        return 'A new inquiry is saved in your private inbox';
    }

    public function path(): string
    {
        return '/admin/customer-inquiries/'.$this->receipt;
    }
}
