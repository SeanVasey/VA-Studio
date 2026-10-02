<?php

namespace App\Domain\Inquiries;

use RuntimeException;

final class InquiryException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct('Inquiry request failed.');
    }
}
