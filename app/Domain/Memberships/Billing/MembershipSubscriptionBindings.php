<?php

namespace App\Domain\Memberships\Billing;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Read-only lookup of one member's sealed v2 subscription approvals. Every row is verified (seal, authenticated record, own
 * account, mode and approved policy) before it is returned; a row that does not verify is a refusal, never skipped. This confers
 * no paid-invoice authority: it only answers which approved Stripe customer a signed-in member is bound to.
 */
final class MembershipSubscriptionBindings
{
    public const MAX = 20;

    /** @return list<array{row: array, payload: array, expectation: BillingExpectation}> */
    public function forMember(int $accountId, int $userId, array $configuration): array
    {
        BillingException::require($accountId > 0 && $userId > 0, 'invalid_value');
        $pdo = DB::connection()->getPdo();
        $schema = new BillingSchema;
        $schema->assertOwned($pdo);
        $statement = $pdo->prepare('SELECT * FROM '.$schema->table(BillingSchema::TABLES[0]).' WHERE account_id = ? AND user_id = ? AND mode = ? ORDER BY id LIMIT '.(self::MAX + 1));
        $statement->execute([$accountId, $userId, $configuration['mode']]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require(count($rows) <= self::MAX, 'ambiguous_source');

        return array_map(fn (array $row): array => BillingSubscriptionRecord::verify(BillingValues::integers($row, ['account_id', 'user_id', 'identity_origin_id']), $configuration), $rows);
    }
}
