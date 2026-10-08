<?php

namespace App\Domain\Grants\Member;

interface MemberGrantFactsProof extends \JsonSerializable
{
    public function binding(): MemberGrantFactsBinding;

    public function __serialize(): never;

    public function jsonSerialize(): never;
}
