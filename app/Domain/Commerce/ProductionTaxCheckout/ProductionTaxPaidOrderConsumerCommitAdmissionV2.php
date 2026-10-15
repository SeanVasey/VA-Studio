<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use PDO;

/** Internal composition seam only: later Paid252 must mint its own current NEW-write admission. */
interface ProductionTaxPaidOrderConsumerCommitAdmissionV2
{
    public function proveCurrent(PDO $capturedPrimary): void;
}
