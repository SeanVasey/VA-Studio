<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MemberGrantBinding;
use App\Domain\Memberships\Production\MemberGrantReceipt;

/** Value carrier only. Its exact issuer registry is indispensable. */
final readonly class MemberActivationReadyToken implements MemberGrantReceipt
{
    private function __construct(private MemberGrantBinding $value) {}

    public static function value(MemberGrantBinding $value): self
    {
        return new self($value);
    }

    public function binding(): MemberGrantBinding
    {
        return $this->value;
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_grant_receipt');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_grant_receipt');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_grant_receipt');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_grant_receipt');
    }
}
