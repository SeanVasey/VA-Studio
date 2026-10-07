<?php

namespace App\Domain\Catalog\DiscoverySitemap;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;

/** Closed scalar request from authenticated private producer state; it grants no writer authority. */
final readonly class CandidateBuildRequest
{
    private function __construct(private string $generation, private int $ordinal, private int $after,
        private int $epoch, private string $configuration) {}

    public static function forState(string $generation, int $ordinal, int $after, int $epoch, string $configuration): self
    {
        SitemapException::require(preg_match('/\A[a-f0-9]{32}\z/D', $generation) === 1 && $ordinal >= 1
            && $ordinal <= SitemapConfiguration::SLOTS && $after >= 0 && $epoch >= 0 && $epoch < DiscoveryEpoch::MAX
            && preg_match('/\A[a-f0-9]{64}\z/D', $configuration) === 1, 'invalid_request');

        return new self($generation, $ordinal, $after, $epoch, $configuration);
    }

    public function generationId(): string
    {
        return $this->generation;
    }

    public function ordinal(): int
    {
        return $this->ordinal;
    }

    public function after(): int
    {
        return $this->after;
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    public function configurationHash(): string
    {
        return $this->configuration;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Internal candidate request cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'candidate_capture'];
    }
}
