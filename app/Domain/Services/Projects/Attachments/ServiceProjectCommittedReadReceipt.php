<?php

namespace App\Domain\Services\Projects\Attachments;

use App\Domain\SupportAttachments\AttachmentCommittedReadReceipt;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentRows;
use Closure;
use JsonSerializable;
use LogicException;
use Throwable;

/** Read closure over the original captured proof; it carries no lock or mutation authority. */
final readonly class ServiceProjectCommittedReadReceipt implements AttachmentCommittedReadReceipt, JsonSerializable
{
    private function __construct(private Closure $close) {}

    public static function capture(ServiceProjectAttachmentSourceV1 $token, AttachmentRows $rows): self
    {
        return new self($token->captureCommittedRead($rows));
    }

    public function proveClosed(): void
    {
        try {
            ($this->close)();
        } catch (AttachmentException $error) {
            throw $error;
        } catch (Throwable) {
            throw new AttachmentException(503);
        }
    }

    public function __serialize(): never
    {
        throw new LogicException('Committed service read receipts cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Committed service read receipts are internal source proofs.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Committed service read receipts cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'committed_service_read'];
    }

    private function __clone() {}
}
