<?php

namespace App\Domain\Memberships;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Bounded owned bucket selection hints. Exact benefit evidence remains the ledger's responsibility. */
final class MembershipCustomerHistory
{
    public const MAX_BUCKETS = 100;

    public function __construct(private MembershipPolicy $policy, private MembershipEvidence $evidence) {}

    public function buckets(CustomerPrincipal $principal, User $buyer): array
    {
        $this->policy->standalone();

        return DB::transaction(function () use ($principal, $buyer): array {
            $authority = $this->evidence->buyer($principal, $buyer);
            $rows = DB::table('membership_credit_buckets')->where('customer_account_id', $principal->accountId)
                ->orderBy('id')->limit(self::MAX_BUCKETS + 1)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
            if (count($rows) > self::MAX_BUCKETS) {
                $this->policy->reject('membership', 'Retain this bounded history for separate review.');
            }
            $ids = array_map(fn ($row) => (int) $row['id'], $rows);
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $buyer);
            $this->evidence->prove($authority['users'], $authority['account'],
                array_map(fn ($row) => ['membership_credit_buckets', (int) $row['id'], $row], $rows), null,
                [['membership_credit_buckets', ['customer_account_id' => $principal->accountId], 'id', self::MAX_BUCKETS + 1, $rows]]);
            $this->policy->requireEnabled();
            app(CustomerAccessPolicy::class)->requireEnabled();

            return ['test_only' => true, 'bucket_ids' => $ids];
        });
    }
}
