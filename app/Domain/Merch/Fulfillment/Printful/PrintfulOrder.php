<?php

namespace App\Domain\Merch\Fulfillment\Printful;

/** What the store keeps of a Printful order: ids and status only, never the recipient. */
final class PrintfulOrder
{
    public function __construct(
        public readonly string $id,
        public readonly string $externalId,
        public readonly string $status,
    ) {}
}
