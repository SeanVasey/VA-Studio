<?php

namespace App\Domain\Grants\Member;

/** Literal request values only. Neither an affirmation nor a display hash is authority. */
final readonly class MemberLicenseConfirmationInput
{
    private function __construct(public string $displayHash) {}

    public static function from(array $input): self
    {
        MemberGrantDocumentValues::keys($input, ['display_hash', 'affirmed']);
        MemberGrantDocumentValues::hash($input['display_hash']);
        MemberGrantException::require($input['affirmed'] === true, 'confirmation_required');

        return new self($input['display_hash']);
    }

    public function value(): array
    {
        return ['display_hash' => $this->displayHash, 'affirmed' => true];
    }
}
