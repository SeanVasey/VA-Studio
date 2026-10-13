<?php

namespace App\Domain\Grants\Member;

/** Application entry separates pure retained lookup from transaction-free physical verification. */
final class MemberGrantRenderProfile
{
    public static function released(string $provenance): array
    {
        return MemberGrantRenderProfileRegistry::released($provenance);
    }

    public static function current(string $provenance, MemberOriginalIoFrame $idle): array
    {
        return MemberGrantRenderProfileRegistry::current($provenance, $idle);
    }

    public static function validateStored(array $descriptor): array
    {
        return MemberGrantRenderProfileRegistry::validateStored($descriptor);
    }

    public static function verifyRuntime(array $descriptor, MemberOriginalIoFrame $idle): void
    {
        MemberGrantRenderProfileRegistry::verifyRuntime($descriptor, $idle);
    }
}
