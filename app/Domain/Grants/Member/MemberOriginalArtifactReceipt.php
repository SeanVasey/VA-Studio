<?php

namespace App\Domain\Grants\Member;

/** Private-minted original transaction/source/file proof. Cannot be deserialized or authorize other families. */
interface MemberOriginalArtifactReceipt extends \JsonSerializable
{
    public function binding(): MemberOriginalArtifactManifest;

    public function __serialize(): never;

    public function jsonSerialize(): never;
}
