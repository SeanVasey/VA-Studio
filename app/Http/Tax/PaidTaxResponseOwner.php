<?php

namespace App\Http\Tax;

use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxTransfer;
use Throwable;

/** Plain owner of original handles only; no request, response or callback back-reference. */
final class PaidTaxResponseOwner
{
    private ?PaidTaxProjectionRead $read = null;

    private ?PaidTaxTransfer $transfer = null;

    private bool $closed = false;

    private bool $used = false;

    public function read(): PaidTaxProjectionRead
    {
        PaidTaxException::require(! $this->closed && $this->transfer === null);

        return $this->read ??= PaidTaxProjectionRead::begin();
    }

    public function own(PaidTaxTransfer $transfer): void
    {
        PaidTaxException::require(! $this->closed && $this->transfer === null && $this->read === null);
        $this->transfer = $transfer;
    }

    public function writeJson(string $encoded): void
    {
        PaidTaxException::require(! $this->closed && ! $this->used && $this->read !== null);
        $this->used = true;
        $this->read->proveBeforeBytes();
        echo $encoded;
    }

    public function writeTransfer(): void
    {
        PaidTaxException::require(! $this->closed && ! $this->used && $this->transfer !== null);
        $this->used = true;
        $this->transfer->writeTo(static function (string $bytes): void {
            echo $bytes;
        });
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $read = $this->read;
        $transfer = $this->transfer;
        $this->read = null;
        $this->transfer = null;
        try {
            $read?->abandon();
        } catch (Throwable) {
        }
        try {
            $transfer?->close();
        } catch (Throwable) {
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function __clone() {}
}
