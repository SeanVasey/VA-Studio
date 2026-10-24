<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use PDO;

/**
 * Plain, non-locking Free reads inside an `IdentityCommittedFrame`. Nothing is decrypted or re-sealed: each row must
 * equal, column for column (seal and ciphertext included), the row the locked redemption transaction verified and
 * committed. A session TEMPORARY table shadowing an owned or parent table is refused by the schema proof first.
 */
final class ProductionFreeGrantCommittedRows
{
    private const TABLES = ['origin' => 'production_free_origins', 'authorization' => 'production_free_authorizations',
        'redemption' => 'production_free_redemptions', 'original' => 'production_free_originals'];

    public function __construct(private readonly IdentityCommittedFrame $frame) {}

    /** @param  array<string, array<string, ?string>>  $expected */
    public function prove(array $expected): void
    {
        ProductionFreeGrantException::require(array_keys($expected) === array_keys(self::TABLES), 'outcome_unknown');
        $primary = $this->frame->reader()->identityPrimary();
        // F-4: permanent owned tables and parents, including a session TEMPORARY shadow of any of them.
        (new ProductionFreeGrantSchema)->assertOwned($primary);
        foreach (self::TABLES as $key => $logical) {
            $row = $expected[$key];
            ProductionFreeGrantException::require(is_string($row['id'] ?? null), 'outcome_unknown');
            $actual = $this->rows($logical, 'id = ?', [$row['id']], 2);
            ProductionFreeGrantException::require(count($actual) === 1 && ProductionFreeGrantRecords::strings($actual[0]) === ProductionFreeGrantRecords::strings($row), 'outcome_unknown');
        }
        ProductionFreeGrantException::require($expected['authorization']['origin_id'] === $expected['origin']['id']
            && $expected['redemption']['authorization_id'] === $expected['authorization']['id']
            && $expected['original']['origin_id'] === $expected['origin']['id'], 'outcome_unknown');
        ProductionFreeGrantException::require($this->rows('production_free_revocations', 'origin_id = ?', [$expected['origin']['id']], 1) === [], 'outcome_unknown');
        $this->frame->assertActive();
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $logical, string $where, array $bindings, int $limit): array
    {
        $this->frame->assertActive();
        $primary = $this->frame->reader()->identityPrimary();
        $statement = $primary->prepare('SELECT * FROM '.(new ProductionFreeGrantSchema)->table($logical).' WHERE '.$where.' ORDER BY id LIMIT '.$limit);
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->frame->assertActive();

        return $rows;
    }
}
