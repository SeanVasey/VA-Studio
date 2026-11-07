<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

/** Exact technical revision reference, never a price, license, asset list or purchase authorization. */
final readonly class FamilyRevisionSelection
{
    public const WAV_KIT = 'wav_sample_kit';

    private function __construct(public string $family, public string $revisionId, public string $manifestHash) {}

    public static function fromArray(array $input): self
    {
        if (count($input) !== 3 || array_diff(array_keys($input), ['family', 'revisionId', 'manifestHash']) !== []
            || ($input['family'] ?? null) !== self::WAV_KIT
            || ! is_string($input['revisionId'] ?? null)
            || preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $input['revisionId']) !== 1
            || ! is_string($input['manifestHash'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $input['manifestHash']) !== 1) {
            throw new InvalidArgumentException('An exact supported family revision reference is required.');
        }

        return new self($input['family'], $input['revisionId'], $input['manifestHash']);
    }
}
