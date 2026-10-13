<?php

namespace App\Domain\Grants\Member;

use App\Support\CanonicalJson;

/** Detached proved values only; ticket registration belongs exclusively to the confirmation issuer. */
final readonly class MemberLicenseConfirmationCapture
{
    private string $canonical;

    public function __construct(public MemberOriginalSourceValues $source, array $authorization,
        public MemberActivationConfiguration $configuration)
    {
        MemberGrantDocumentValues::bounded($authorization);
        $this->canonical = CanonicalJson::encode($authorization);
    }

    public function authorization(): array
    {
        return json_decode($this->canonical, true, 32, JSON_THROW_ON_ERROR);
    }
}
