<?php

namespace App\Domain\Commerce\ProductionCheckout;

use PDO;

/**
 * Consumer-owned, server-minted capsule for NEW writes in this original source frame.
 * Proof uses captured raw authority only: no callbacks, locks, transaction or marker renewal.
 */
interface ProductionPaidOrderConsumerCommitAdmissionV1
{
    public function proveCurrent(PDO $capturedPrimary): void;
}
