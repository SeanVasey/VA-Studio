<?php

namespace Tests\Feature\ProductionTaxCheckout;

use PHPUnit\Framework\TestCase;

/**
 * Tax255 must never change the frozen V1 purpose/line source. Each SHA-256 below equals the composed, independently
 * reviewed record in docs/verification/checkout-composition-20261007/independent-review/source-sha256.txt.
 * HostedCheckout.php and the V1 write-admission files are deliberately absent: lane A1b changes them under review.
 *
 * REVIEWED_SUCCESSORS names the only frozen files that later changed, each with its exact successor hash and the reviewed
 * change that produced it. FROZEN and the composition record stay as reviewed; a file may match its successor only.
 */
class ProductionTaxCheckoutFrozenV1Test extends TestCase
{
    public const FROZEN = [
        'app/Domain/Commerce/ProductionCheckout/ProductionPaidOrderSourceV1.php' => 'ca7ce0c8f6fd1d90b6ec299770947fe9117a30d268457db706adfae3eeff2983',
        'app/Domain/Commerce/ProductionCheckout/ProductionPaidOrderLocatorV1.php' => 'c881ad5238322ebe3c14f161c225ae518ad6c4255ab978cb60f551c8747fff11',
        'app/Domain/Commerce/ProductionCheckout/ProductionPaidOrderCommittedReadReceiptV1.php' => '190aa5e356bdcd2f719e85277632c87911d99b0f7e4eb9eb8977f539d85c75e0',
        'app/Domain/Commerce/ProductionCheckout/ProductionPaidOrderConsumerCommitAdmissionV1.php' => '0a1b219af99bf60d0b77f4570711a37e186c1e737d1fa721e886c607983c9608',
        'app/Domain/Commerce/ProductionCheckout/OrderEvidence.php' => '4b7864a88b3f0bd4fcdc21169f8d36fc5243b484f3eb88cb2b8bb270ede2a8b0',
        'app/Domain/Commerce/ProductionCheckout/HostedEvidence.php' => '13eb973ddd2ea872c788f18d7258aff30b1b73c21923d1ad2a2d05e4272e2024',
        'app/Domain/Commerce/ProductionCheckout/CheckoutSchema.php' => 'f5ae038aac1aab1aa30e56fada96fdb6855c5bc585829745a16e3cac18afa770',
        'app/Domain/Commerce/ProductionCheckout/CheckoutSchemaInstaller.php' => 'c04dbc3b448272290ec9e818ccc4e8b87139d9d20db1cf5aeb0932da015389d8',
        'database/migrations/2026_10_07_246000_production_checkout.php' => 'e834a67c3ffeeb26a9dc11a5701eb5e4d30f332421395b3e682e773996dec615',
    ];

    /**
     * REP reporting period indexes (abc9ecba, merged in #196). The independent review of exact abc9ecba covered these
     * bytes; its two P2s (REP-R1, REP-R2) were in the migration only, and the repair left both files unchanged
     * (docs/verification/rep-indexes-20261103/README.md, "Single source repair", and its verification tables).
     * The change adds two nonunique single-column indexes plus a migration-only upgrade preflight
     * (assertReportingUpgrade); strict installer admission is unchanged apart from now requiring the two indexes.
     * No column, uniqueness, foreign key, trigger or payment path changed.
     */
    public const REVIEWED_SUCCESSORS = [
        'app/Domain/Commerce/ProductionCheckout/CheckoutSchema.php' => 'ecbb168a283213666380d21a4d89430c289fb95e4be9fd77e6cf78de6e3f2357',
        'app/Domain/Commerce/ProductionCheckout/CheckoutSchemaInstaller.php' => '6d770ecb9b2b9ff2d38c3bf04a8f1f81221e2fed9ea54ec6a9ad5af071b52ef8',
    ];

    public function test_frozen_v1_purpose_and_line_source_bytes_are_unchanged(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertSame([], array_diff_key(self::REVIEWED_SUCCESSORS, self::FROZEN), 'A successor must name a frozen file.');
        foreach (self::FROZEN as $path => $sha256) {
            $this->assertFileExists($root.'/'.$path);
            $this->assertSame(self::REVIEWED_SUCCESSORS[$path] ?? $sha256, hash_file('sha256', $root.'/'.$path), $path);
        }
    }

    public function test_the_frozen_list_matches_the_independent_composition_record(): void
    {
        $record = [];
        foreach (file(dirname(__DIR__, 3).'/docs/verification/checkout-composition-20261007/independent-review/source-sha256.txt', FILE_IGNORE_NEW_LINES) as $line) {
            [$hash, $path] = preg_split('/\s+/', trim($line), 2);
            $record[$path] = $hash;
        }
        foreach (self::FROZEN as $path => $sha256) {
            $this->assertSame($record[$path] ?? null, $sha256, $path);
        }
    }
}
