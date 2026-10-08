<?php

namespace Tests\Support;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;

/** In-memory synthetic capability. Real composition binds catalog/rights/scanner/media proofs here instead. */
final class FakeProductionFreeSources implements ProductionFreeGrantSources
{
    public array $bytes;

    public bool $ready = true;

    public int $proofs = 0;

    public function __construct()
    {
        $this->bytes = ['synthetic-master_wav' => "RIFF\x24\x00\x00\x00WAVEsynthetic rehearsal master, not audio",
            'synthetic-download_mp3' => 'ID3synthetic rehearsal mp3, not audio',
            'synthetic-stems_zip' => "PK\x03\x04synthetic rehearsal stems, not audio"];
    }

    public function prove(array $sourceManifest, array $assets): string
    {
        $this->proofs++;
        if (! $this->ready) {
            throw new \RuntimeException('synthetic source withdrawn');
        }

        return hash('sha256', json_encode([$sourceManifest, $assets], JSON_THROW_ON_ERROR));
    }

    public function open(array $asset)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->bytes[$asset['source_id']] ?? '');
        rewind($stream);

        return $stream;
    }
}
