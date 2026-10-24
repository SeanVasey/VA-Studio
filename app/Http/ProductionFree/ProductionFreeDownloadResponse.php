<?php

namespace App\Http\ProductionFree;

use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The redemption response. The committed before-first-byte proof runs before the first header (`sendHeaders`) and,
 * for a caller that streams content directly, before the first byte (`sendContent`). If it refuses, this response
 * becomes the fixed private error instead (`outcome_unknown`, or `expired` on the original deadline), so no download
 * header and no file byte is ever emitted. The callback never captures the response.
 */
final class ProductionFreeDownloadResponse extends StreamedResponse
{
    private ?ProductionFreeDownload $download;

    public function __construct(ProductionFreeDownload $download, array $headers)
    {
        $this->download = $download;
        try {
            parent::__construct(static function () use ($download): void {
                $download->emit();
            }, 200, $headers);
            ProductionFreePrivacy::protect($this);
        } catch (Throwable $error) {
            $download->close();
            throw $error;
        }
    }

    public function sendHeaders(?int $statusCode = null): static
    {
        $this->settle();

        return parent::sendHeaders($statusCode);
    }

    public function sendContent(): static
    {
        $this->settle();

        return parent::sendContent();
    }

    public function close(): void
    {
        $this->download?->close();
    }

    /** Prove once; on refusal replace status and headers with the fixed error before anything is sent. */
    private function settle(): void
    {
        if ($this->download === null || $this->download->prepare()) {
            return;
        }
        $error = ProductionFreePrivacy::refusal($this->download->failure());
        $this->setStatusCode($error->getStatusCode());
        $this->headers = clone $error->headers;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __clone()
    {
        $this->download = null;
        throw new LogicException('Private Free downloads cannot be cloned.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Private Free downloads cannot be serialized.');
    }
}
