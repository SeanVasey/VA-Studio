<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use PDO;

/**
 * Internal PURE captured-PDO current admission. Called before source work and again after the last
 * decryption callback. No resolver/decryptor/provider/renderer/frame renewal; later Paid252 must mint it.
 */
interface ProductionTaxPaidOrderConsumerCommitAdmissionV2
{
    public function proveCurrent(PDO $capturedPrimary): void;
}
