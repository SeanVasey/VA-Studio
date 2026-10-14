<?php

namespace App\Domain\Grants\Member;

/** Opaque completion carrier; only the source-owned registration supplies a read baseline. */
final class MemberActivationCompletedReadback implements \JsonSerializable
{
    private function __construct(private readonly MemberGrantActivationResult $result) {}

    public static function value(MemberGrantActivationResult $result): self
    {
        return new self($result);
    }

    public function result(): MemberGrantActivationResult
    {
        return $this->result;
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_completed_readback');
    }

    public function __unserialize(array $data): never
    {
        throw new MemberGrantException('private_completed_readback');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_completed_readback');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_completed_readback');
    }
}
