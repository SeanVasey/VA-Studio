<?php

namespace App\Domain\Contracts;

/** The stored trusted version selects a fixed implementation; input cannot name a class or path. */
final class VersionedContractRenderer implements ContractRenderer
{
    public function __construct(private readonly ?string $projectRoot = null) {}

    public function render(array $input, array $profile): RenderedContract
    {
        ContractRenderProfile::validate($profile);
        $renderer = match ($profile['version']) {
            'test-buyer-pdf-v1' => new TcpdfContractRenderer($this->projectRoot),
            'test-buyer-pdf-v2' => new TcpdfV2ContractRenderer($this->projectRoot),
            default => throw new ContractIssuanceException('profile_changed'),
        };

        return $renderer->render($input, $profile);
    }
}
