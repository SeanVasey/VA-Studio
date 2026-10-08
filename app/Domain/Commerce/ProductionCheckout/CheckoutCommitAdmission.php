<?php

namespace App\Domain\Commerce\ProductionCheckout;

/**
 * A sealed, server-authenticated capsule that admits one NEW checkout write at physical commit.
 *
 * The single frame observer calls proveCurrent() and then proveFresh() after ordinary committing
 * delegates and before the original physical anchor. Implementations compare only fixed raw plans
 * and pure retained configuration: no resolver, decryptor, renderer, clock factory, identity
 * verifier or provider runs at commit.
 */
interface CheckoutCommitAdmission
{
    public function belongsTo(CheckoutCommandFrame $frame): bool;

    public function proveCurrent(): void;

    public function proveFresh(): void;
}
