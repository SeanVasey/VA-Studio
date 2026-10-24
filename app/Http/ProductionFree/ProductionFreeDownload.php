<?php

namespace App\Http\ProductionFree;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRequestIdentity;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use LogicException;
use Throwable;

/**
 * Plain owner of one redeemed transfer and its original request capsule; no response or callback back-reference.
 * `prepare()` runs once, before the first header or byte: the literal mount, the same HTTP capsule, then the
 * transfer's committed before-first-byte proof. Its failure is final for this response and closes the snapshot.
 *
 * A6-2, mount part. The transfer enforces a minimum progress rate between chunks, but PHP cannot interrupt an `echo`
 * that blocks on a client that stopped reading. Each accepted chunk is therefore written in 64 KiB pieces, flushed, and
 * a detected client abort stops the stream at once; the remaining bound is the web server's send timeout, which the
 * deployment must set (see `docs/ops/production-free-http-runtime.md`). The snapshot's spool slot is released when
 * the transfer closes, or by the operating system when the worker ends.
 */
final class ProductionFreeDownload
{
    private const PIECE = 65536;

    private ?ProductionFreeGrantTransfer $transfer;

    private ?bool $admitted = null;

    private ?ProductionFreeGrantException $failure = null;

    private bool $emitted = false;

    public function __construct(ProductionFreeGrantTransfer $transfer, private readonly ProductionFreeGrantRequestIdentity $identity)
    {
        $this->transfer = $transfer;
    }

    public function prepare(): bool
    {
        if ($this->admitted !== null) {
            return $this->admitted;
        }
        try {
            ProductionFreeGrantException::require($this->transfer !== null && ProductionFreeHttpMount::admitted(), 'outcome_unknown');
            $this->identity->proveCurrent();
            $this->transfer->begin();

            return $this->admitted = true;
        } catch (Throwable $error) {
            // The redemption has committed: any refusal here is an unknown outcome, except an honest original-deadline expiry.
            $this->failure = $error instanceof ProductionFreeGrantException && $error->reason === 'expired'
                ? $error : new ProductionFreeGrantException('outcome_unknown');
            $this->close();

            return $this->admitted = false;
        }
    }

    public function failure(): ?ProductionFreeGrantException
    {
        return $this->failure;
    }

    public function emit(): void
    {
        if ($this->emitted) {
            return;
        }
        $this->emitted = true;
        if (! $this->prepare()) {
            echo ProductionFreePrivacy::refusal($this->failure)->getContent();

            return;
        }
        $abort = ignore_user_abort(true);
        try {
            $this->transfer?->writeTo(static function (string $bytes): void {
                for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += self::PIECE) {
                    echo substr($bytes, $offset, self::PIECE);
                    flush();
                    if (connection_status() !== CONNECTION_NORMAL) {
                        throw new ProductionFreeGrantException('client_gone');
                    }
                }
            });
        } catch (Throwable $error) {
            if (! $error instanceof ProductionFreeGrantException) {
                ProductionFreePrivacy::report($error);
            }
        } finally {
            $this->close();
            ignore_user_abort($abort);
        }
    }

    public function close(): void
    {
        $transfer = $this->transfer;
        $this->transfer = null;
        try {
            $transfer?->close();
        } catch (Throwable) {
            // Closing an already-closed snapshot is not an error worth surfacing.
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __serialize(): never
    {
        throw new LogicException('Private Free downloads cannot be serialized.');
    }

    private function __clone() {}
}
