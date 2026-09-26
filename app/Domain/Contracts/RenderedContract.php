<?php

namespace App\Domain\Contracts;

final readonly class RenderedContract
{
    public function __construct(
        public string $pdfBytes,
        public string $sha256,
        public int $sizeBytes,
        public int $pageCount,
        public string $textDigest,
        public string $profileHash,
    ) {}
}
