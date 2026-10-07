<?php

namespace App\Domain\SupportAttachments;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use WeakReference;

/** A forged instance has no entry in the issuing authority's private WeakMap. */
final class InquiryCommittedReadReceipt implements AttachmentCommittedReadReceipt
{
    private bool $ended = false;

    private bool $committed = false;

    private function __construct(private readonly InquiryAttachmentAuthority $issuer)
    {
        $weak = WeakReference::create($this);
        $connection = DB::connection();
        foreach ([TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            Event::listen($event, static function ($event) use ($weak, $connection): void {
                if ($event->connection === $connection && $connection->transactionLevel() === 0 && ($receipt = $weak->get()) && ! $receipt->ended) {
                    $receipt->ended = true;
                    $receipt->committed = $event instanceof TransactionCommitted;
                }
            });
        }
    }

    public function assertCommitted(): void
    {
        AttachmentException::require($this->ended && $this->committed);
    }

    public static function issued(InquiryAttachmentAuthority $issuer): self
    {
        return new self($issuer);
    }

    public function proveClosed(): void
    {
        $this->issuer->proveCommittedRead($this);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Committed source receipt cannot be serialized.');
    }

    private function __clone() {}

    public function __debugInfo(): array
    {
        return ['purpose' => 'committed_inquiry_read'];
    }
}
