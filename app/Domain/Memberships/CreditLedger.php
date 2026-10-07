<?php

namespace App\Domain\Memberships;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Memberships\Models\MembershipCreditBucket;
use App\Domain\Memberships\Models\MembershipPlanVersion;
use App\Models\User;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Account-scoped synthetic credit movements only; no invoice, payment, license or entitlement writer. */
final class CreditLedger
{
    private const BALANCE_KEYS = ['available', 'reserved', 'consumed', 'expired'];

    public function __construct(private MembershipPolicy $policy, private MembershipEvidence $evidence, private MembershipPlans $plans) {}

    public function grantSynthetic(MembershipPlanVersion $version, CustomerAccount $account, string $sourceEventId, User $operator): array
    {
        $this->policy->standalone();
        $source = hash('sha256', $this->policy->token($sourceEventId, 'source_event', true));
        $accountId = $account->exists ? (int) $account->getKey() : 0;
        $userId = (int) $account->user_id;
        $planId = (int) $version->membership_plan_id;
        $versionId = $version->exists ? (int) $version->getKey() : 0;

        return DB::transaction(function () use ($source, $accountId, $userId, $planId, $versionId, $operator): array {
            $users = $this->evidence->operator($operator, $userId);
            $account = $this->evidence->account($accountId, $userId);
            $parent = $this->evidence->row('membership_plans', $planId);
            $version = $this->plans->retainedVersion($versionId);
            if ((int) $version['membership_plan_id'] !== $planId) {
                $this->unavailable();
            }
            $policy = json_decode($version['policy'], true, 32, JSON_THROW_ON_ERROR);
            $request = CanonicalJson::hash(['account_id' => $accountId, 'plan_version_id' => $versionId, 'source_event_hash' => $source]);
            $existing = DB::table('membership_credit_buckets')->where('source_event_hash', $source)->lockForUpdate()->first();
            if ($existing !== null) {
                $bucket = (array) $existing;
                if ($bucket['source_request_hash'] !== $request || (int) $bucket['customer_account_id'] !== $accountId || (int) $bucket['membership_plan_version_id'] !== $versionId) {
                    $this->policy->reject('source_event', 'This synthetic source was already bound to another award.');
                }
                $events = $this->history($bucket, $policy);
                $cursor = $this->evidence->cursor(MembershipCreditBucket::class, (int) $bucket['id']);
                $this->finish($operator, null, $users, $account, $parent, $version, $bucket, $events, null, $cursor);

                return $this->result($events[0]);
            }
            $at = $this->at();
            $expiry = $policy['validity_seconds'] === null ? null : CarbonImmutable::parse($at, 'UTC')->addSeconds($policy['validity_seconds'])->format('Y-m-d H:i:s');
            $id = DB::table('membership_credit_buckets')->insertGetId(['customer_account_id' => $accountId, 'membership_plan_version_id' => $versionId,
                'source_event_hash' => $source, 'source_request_hash' => $request, 'allowance' => $policy['allowance'], 'expires_at' => $expiry,
                'created_by' => $operator->getKey(), 'created_at' => $at]);
            $bucket = $this->evidence->row('membership_credit_buckets', $id);
            $event = $this->append($bucket, [], 'grant', $policy['allowance'], null, null, null, $source, $request, $operator, $account, $policy, $at);
            $audit = $this->audit($bucket, $event, $operator);
            $this->finish($operator, null, $users, $account, $parent, $version, $bucket, [$event], $audit);

            return $this->result($event);
        });
    }

    public function reserve(int $bucketId, mixed $amount, string $resourceKey, string $key, CustomerPrincipal $principal, User $buyer): array
    {
        $this->policy->standalone();
        $amount = $this->policy->amount($amount);
        $resource = hash('sha256', $this->policy->token($resourceKey, 'resource', true));

        return $this->buyerMovement('reserve', $bucketId, null, $amount, $resource, $key, $principal, $buyer);
    }

    public function consume(int $reservationEventId, string $key, CustomerPrincipal $principal, User $buyer): array
    {
        $this->policy->standalone();
        $hint = $this->hintEvent($reservationEventId, 'reserve');

        return $this->buyerMovement('consume', (int) $hint['membership_credit_bucket_id'], $reservationEventId, null, null, $key, $principal, $buyer);
    }

    public function release(int $reservationEventId, string $key, CustomerPrincipal $principal, User $buyer): array
    {
        $this->policy->standalone();
        $hint = $this->hintEvent($reservationEventId, 'reserve');

        return $this->buyerMovement('release', (int) $hint['membership_credit_bucket_id'], $reservationEventId, null, null, $key, $principal, $buyer);
    }

    public function reverse(int $consumptionEventId, string $key, User $operator): array
    {
        $this->policy->standalone();
        $hint = $this->hintEvent($consumptionEventId, 'consume');

        return $this->operatorMovement('reverse', (int) $hint['membership_credit_bucket_id'], $consumptionEventId, $key, $operator);
    }

    public function expire(int $bucketId, string $key, User $operator): array
    {
        $this->policy->standalone();

        return $this->operatorMovement('expire', $bucketId, null, $key, $operator);
    }

    public function read(int $bucketId, CustomerPrincipal $principal, User $buyer): array
    {
        return $this->customerRead($bucketId, $principal, $buyer, false);
    }

    /** Retained synthetic benefits only; no subscription, paid period or download assertion. */
    public function customerHistory(int $bucketId, CustomerPrincipal $principal, User $buyer): array
    {
        return $this->customerRead($bucketId, $principal, $buyer, true);
    }

    private function customerRead(int $bucketId, CustomerPrincipal $principal, User $buyer, bool $withHistory): array
    {
        $this->policy->standalone();

        return DB::transaction(function () use ($bucketId, $principal, $buyer, $withHistory): array {
            $authority = $this->evidence->buyer($principal, $buyer);
            [$parent, $version, $bucket, $policy] = $this->scope($bucketId, $principal->accountId);
            $events = $this->history($bucket, $policy);
            $cursor = $this->evidence->cursor(MembershipCreditBucket::class, $bucketId);
            $last = $events[array_key_last($events)];
            $at = $this->at();
            $result = ['bucket_id' => $bucketId, 'plan_version_id' => (int) $version['id'], 'unit' => $policy['unit'], 'expires_at' => $bucket['expires_at'],
                'last_event_id' => (int) $last['id'], 'balance' => $this->balance($last['after_balance']),
                'spendable_credits' => $bucket['expires_at'] !== null && $at >= $bucket['expires_at'] ? 0 : $this->balance($last['after_balance'])['available']];
            if ($withHistory) {
                $result += ['test_only' => true, 'plan' => ['version_id' => (int) $version['id'], 'number' => (int) $version['number'],
                    'title' => $version['title'], 'policy' => $policy],
                    'events' => array_map(fn ($event) => ['id' => (int) $event['id'], 'sequence' => (int) $event['sequence'],
                        'kind' => $event['kind'], 'amount' => (int) $event['amount'], 'created_at' => $event['created_at'],
                        'balance' => $this->balance($event['after_balance'])], $events)];
            }
            // Build projections before callbacks, then retain exact primary rows and current buyer authority.
            $this->finish($buyer, $principal, $authority['users'], $authority['account'], $parent, $version, $bucket, $events, null, $cursor);
            $finalAt = $this->at();
            if ($finalAt < $at || ($bucket['expires_at'] !== null && ($at < $bucket['expires_at']) !== ($finalAt < $bucket['expires_at']))) {
                $this->unavailable();
            }

            return $result;
        });
    }

    private function buyerMovement(string $kind, int $bucketId, ?int $reservationId, ?int $amount, ?string $resource, string $key, CustomerPrincipal $principal, User $buyer): array
    {
        $keyHash = hash('sha256', $this->policy->token($key, 'key'));

        return DB::transaction(function () use ($kind, $bucketId, $reservationId, $amount, $resource, $keyHash, $principal, $buyer): array {
            $authority = $this->evidence->buyer($principal, $buyer);
            [$parent, $version, $bucket, $policy] = $this->scope($bucketId, $principal->accountId);
            $events = $this->history($bucket, $policy);
            if ($reservationId !== null) {
                $reservation = $this->findEvent($events, $reservationId, 'reserve');
                $amount = (int) $reservation['amount'];
                $resource = $reservation['resource_hash'];
            }
            $request = $this->request($kind, $bucketId, $amount, $resource, $reservationId, null, $buyer, $authority['account']);
            $replay = $this->replay($events, $keyHash, $request);
            $cursor = $this->evidence->cursor(MembershipCreditBucket::class, $bucketId);
            $audit = null;
            if ($replay === null) {
                $replay = $this->append($bucket, $events, $kind, $amount, $reservationId, null, $resource, $keyHash, $request, $buyer, $authority['account'], $policy, $this->at());
                $events[] = $replay;
                $audit = $this->audit($bucket, $replay, $buyer);
            }
            $this->finish($buyer, $principal, $authority['users'], $authority['account'], $parent, $version, $bucket, $events, $audit, $cursor);

            return $this->result($replay);
        });
    }

    private function operatorMovement(string $kind, int $bucketId, ?int $sourceEventId, string $key, User $operator): array
    {
        $keyHash = hash('sha256', $this->policy->token($key, 'key'));
        $hint = DB::table('membership_credit_buckets')->where('id', $bucketId)->first();
        $accountHint = $hint === null ? null : DB::table('customer_accounts')->where('id', $hint->customer_account_id)->first();
        if ($accountHint === null) {
            $this->unavailable();
        }

        return DB::transaction(function () use ($kind, $bucketId, $sourceEventId, $keyHash, $operator, $accountHint): array {
            $users = $this->evidence->operator($operator, (int) $accountHint->user_id);
            $account = $this->evidence->account((int) $accountHint->id, (int) $accountHint->user_id);
            [$parent, $version, $bucket, $policy] = $this->scope($bucketId, (int) $account['id']);
            $events = $this->history($bucket, $policy);
            $last = $events[array_key_last($events)];
            $reservationId = null;
            $resource = null;
            if ($kind === 'reverse') {
                $source = $this->findEvent($events, $sourceEventId, 'consume');
                $amount = (int) $source['amount'];
                $reservationId = (int) $source['reservation_event_id'];
                $resource = $source['resource_hash'];
            } else {
                $amount = $this->balance($last['after_balance'])['available'];
            }
            $request = $this->request($kind, $bucketId, $amount, $resource, $reservationId, $sourceEventId, $operator, $account);
            $replay = $this->replay($events, $keyHash, $request);
            $cursor = $this->evidence->cursor(MembershipCreditBucket::class, $bucketId);
            $audit = null;
            if ($replay === null) {
                if ($kind === 'expire' && $amount === 0) {
                    if ($bucket['expires_at'] === null || $this->at() < $bucket['expires_at']) {
                        $this->unavailable();
                    }
                    $this->finish($operator, null, $users, $account, $parent, $version, $bucket, $events, null, $cursor);

                    return $this->result($last);
                }
                $replay = $this->append($bucket, $events, $kind, $amount, $reservationId, $sourceEventId, $resource, $keyHash, $request, $operator, $account, $policy, $this->at());
                $events[] = $replay;
                $audit = $this->audit($bucket, $replay, $operator);
            }
            $this->finish($operator, null, $users, $account, $parent, $version, $bucket, $events, $audit, $cursor);

            return $this->result($replay);
        });
    }

    /** Immutable references are only hints; current locking reads establish every retained baseline. */
    private function scope(int $id, int $accountId): array
    {
        $hint = DB::table('membership_credit_buckets')->where('id', $id)->first();
        $versionHint = $hint === null ? null : DB::table('membership_plan_versions')->where('id', $hint->membership_plan_version_id)->first();
        if ($versionHint === null) {
            $this->unavailable();
        }
        $parent = $this->evidence->row('membership_plans', (int) $versionHint->membership_plan_id);
        $version = $this->plans->retainedVersion((int) $versionHint->id);
        $bucket = $this->evidence->row('membership_credit_buckets', $id);
        $policy = json_decode($version['policy'], true, 32, JSON_THROW_ON_ERROR);
        if ((int) $bucket['customer_account_id'] !== $accountId || (int) $bucket['membership_plan_version_id'] !== (int) $version['id']
            || (int) $version['membership_plan_id'] !== (int) $parent['id'] || (int) $bucket['allowance'] !== $policy['allowance']) {
            throw new AuthorizationException;
        }
        if ($bucket['source_request_hash'] !== CanonicalJson::hash(['account_id' => $accountId, 'plan_version_id' => (int) $version['id'], 'source_event_hash' => $bucket['source_event_hash']])) {
            $this->unavailable();
        }
        $expiry = $policy['validity_seconds'] === null ? null : CarbonImmutable::parse($bucket['created_at'], 'UTC')->addSeconds($policy['validity_seconds'])->format('Y-m-d H:i:s');
        if ($bucket['expires_at'] !== $expiry) {
            $this->unavailable();
        }

        return [$parent, $version, $bucket, $policy];
    }

    private function history(array $bucket, array $policy): array
    {
        $events = $this->rawHistory((int) $bucket['id']);
        if ($events === [] || count($events) > MembershipPolicy::MAX_EVENTS) {
            $this->unavailable();
        }
        $before = array_fill_keys(self::BALANCE_KEYS, 0);
        $verified = [];
        foreach ($events as $index => $event) {
            $last = $verified === [] ? null : $verified[array_key_last($verified)];
            if ((int) $event['sequence'] !== $index + 1 || $event['previous_event_id'] !== ($last === null ? null : $last['id'])
                || $event['previous_hash'] !== ($last['event_hash'] ?? null) || $event['event_hash'] !== CanonicalJson::hash($this->eventManifest($event))
                || $this->balance($event['before_balance']) !== $before || ($last !== null && $event['created_at'] < $last['created_at'])) {
                $this->unavailable();
            }
            $after = $this->transition($bucket, $verified, $event['kind'], (int) $event['amount'],
                $event['reservation_event_id'] === null ? null : (int) $event['reservation_event_id'],
                $event['source_event_id'] === null ? null : (int) $event['source_event_id'], $event['resource_hash'], $policy, $event['created_at']);
            if ($this->balance($event['after_balance']) !== $after) {
                $this->unavailable();
            }
            $before = $after;
            $verified[] = $event;
        }
        $this->auditHistory($bucket, $events);

        return $events;
    }

    private function rawHistory(int $id): array
    {
        return DB::table('membership_credit_events')->where('membership_credit_bucket_id', $id)->orderBy('sequence')->limit(MembershipPolicy::MAX_EVENTS + 1)
            ->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
    }

    private function transition(array $bucket, array $events, string $kind, int $amount, ?int $reservationId, ?int $sourceId, ?string $resource, array $policy, string $at): array
    {
        $this->policy->amount($amount);
        $before = $events === [] ? array_fill_keys(self::BALANCE_KEYS, 0) : $this->balance($events[array_key_last($events)]['after_balance']);
        $after = $before;
        $expired = $bucket['expires_at'] !== null && $at >= $bucket['expires_at'];
        if ($events === []) {
            if ($kind !== 'grant' || $amount !== (int) $bucket['allowance'] || $reservationId !== null || $sourceId !== null || $resource !== null || $at !== $bucket['created_at']) {
                $this->unavailable();
            }
            $after['available'] = $amount;
        } elseif ($kind === 'reserve') {
            if ($expired || $before['available'] < $amount || $reservationId !== null || $sourceId !== null || $resource === null
                || array_filter($events, fn ($event) => $event['kind'] === 'reserve' && $event['resource_hash'] === $resource)) {
                $this->unavailable();
            }
            $after['available'] -= $amount;
            $after['reserved'] += $amount;
        } elseif (in_array($kind, ['consume', 'release', 'reverse'], true)) {
            $reservation = $this->findEvent($events, $reservationId, 'reserve');
            if ((int) $reservation['amount'] !== $amount || $reservation['resource_hash'] !== $resource) {
                $this->unavailable();
            }
            $terminal = array_values(array_filter($events, fn ($event) => (int) ($event['reservation_event_id'] ?? 0) === $reservationId && in_array($event['kind'], ['consume', 'release'], true)));
            if ($kind === 'reverse') {
                $source = $this->findEvent($events, $sourceId, 'consume');
                if (! $policy['reversal_allowed'] || count($terminal) !== 1 || (int) $terminal[0]['id'] !== $sourceId
                    || (int) $source['reservation_event_id'] !== $reservationId
                    || array_filter($events, fn ($event) => $event['kind'] === 'reverse' && (int) $event['source_event_id'] === $sourceId)) {
                    $this->unavailable();
                }
                $after['consumed'] -= $amount;
                $after[$expired ? 'expired' : 'available'] += $amount;
            } else {
                if ($terminal !== [] || $sourceId !== null || ($kind === 'consume' && $expired)) {
                    $this->unavailable();
                }
                $after['reserved'] -= $amount;
                $after[$kind === 'consume' ? 'consumed' : ($expired ? 'expired' : 'available')] += $amount;
            }
        } elseif ($kind === 'expire') {
            if (! $expired || $amount !== $before['available'] || $reservationId !== null || $sourceId !== null || $resource !== null) {
                $this->unavailable();
            }
            $after['available'] = 0;
            $after['expired'] += $amount;
        } else {
            $this->unavailable();
        }
        if (min($after) < 0 || array_sum($after) !== (int) $bucket['allowance']) {
            $this->unavailable();
        }

        return $after;
    }

    private function append(array $bucket, array $events, string $kind, int $amount, ?int $reservationId, ?int $sourceId, ?string $resource, string $keyHash, string $request, User $actor, array $account, array $policy, string $at): array
    {
        if (count($events) >= MembershipPolicy::MAX_EVENTS) {
            $this->policy->reject('credits', 'This retained bucket reached its explicit event bound; preserve it for investigation.');
        }
        $last = $events === [] ? null : $events[array_key_last($events)];
        if ($last !== null && $at < $last['created_at']) {
            $this->unavailable();
        }
        $before = $last === null ? array_fill_keys(self::BALANCE_KEYS, 0) : $this->balance($last['after_balance']);
        $after = $this->transition($bucket, $events, $kind, $amount, $reservationId, $sourceId, $resource, $policy, $at);
        $row = ['membership_credit_bucket_id' => (int) $bucket['id'], 'sequence' => count($events) + 1, 'kind' => $kind, 'amount' => $amount,
            'reservation_event_id' => $reservationId, 'source_event_id' => $sourceId, 'resource_hash' => $resource, 'key_hash' => $keyHash, 'request_hash' => $request,
            'previous_event_id' => $last === null ? null : (int) $last['id'], 'previous_hash' => $last['event_hash'] ?? null,
            'before_balance' => CanonicalJson::encode($before), 'after_balance' => CanonicalJson::encode($after),
            'actor_id' => (int) $actor->getKey(), 'account_access_version' => (int) $account['access_version'], 'created_at' => $at];
        $row['event_hash'] = CanonicalJson::hash($this->eventManifest($row));
        $id = DB::table('membership_credit_events')->insertGetId($row);

        return $this->evidence->row('membership_credit_events', $id);
    }

    private function replay(array $events, string $keyHash, string $request): ?array
    {
        foreach ($events as $event) {
            if ($event['key_hash'] === $keyHash) {
                if ($event['request_hash'] !== $request) {
                    $this->policy->reject('key', 'This key already identifies another exact credit movement.');
                }

                return $event;
            }
        }

        return null;
    }

    private function request(string $kind, int $bucketId, int $amount, ?string $resource, ?int $reservationId, ?int $sourceId, User $actor, array $account): string
    {
        // Expiry amount is derived at execution, so a later replay pins intent rather than a changed balance.
        return CanonicalJson::hash(['kind' => $kind, 'bucket_id' => $bucketId, 'amount' => $kind === 'expire' ? null : $amount, 'resource_hash' => $resource,
            'reservation_event_id' => $reservationId, 'source_event_id' => $sourceId, 'actor_id' => (int) $actor->getKey(), 'account_access_version' => (int) $account['access_version']]);
    }

    private function eventManifest(array $row): array
    {
        return ['schema_version' => 1, 'bucket_id' => (int) $row['membership_credit_bucket_id'], 'sequence' => (int) $row['sequence'], 'kind' => $row['kind'], 'amount' => (int) $row['amount'],
            'reservation_event_id' => $row['reservation_event_id'] === null ? null : (int) $row['reservation_event_id'],
            'source_event_id' => $row['source_event_id'] === null ? null : (int) $row['source_event_id'], 'resource_hash' => $row['resource_hash'], 'key_hash' => $row['key_hash'], 'request_hash' => $row['request_hash'],
            'previous_event_id' => $row['previous_event_id'] === null ? null : (int) $row['previous_event_id'], 'previous_hash' => $row['previous_hash'],
            'before_balance' => $this->balance($row['before_balance']), 'after_balance' => $this->balance($row['after_balance']),
            'actor_id' => (int) $row['actor_id'], 'account_access_version' => (int) $row['account_access_version'], 'created_at' => $row['created_at']];
    }

    private function balance(string $value): array
    {
        $balance = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($balance)) {
            $this->unavailable();
        }
        $this->policy->keys($balance, self::BALANCE_KEYS, 'credits');
        $ordered = [];
        foreach (self::BALANCE_KEYS as $key) {
            if (! is_int($balance[$key]) || $balance[$key] < 0 || $balance[$key] > MembershipPolicy::MAX_ALLOWANCE) {
                $this->unavailable();
            }
            $ordered[$key] = $balance[$key];
        }

        return $ordered;
    }

    private function hintEvent(int $id, string $kind): array
    {
        $row = DB::table('membership_credit_events')->where('id', $id)->first();
        if ($row === null || $row->kind !== $kind) {
            $this->unavailable();
        }

        return (array) $row;
    }

    private function findEvent(array $events, ?int $id, string $kind): array
    {
        foreach ($events as $event) {
            if ((int) $event['id'] === $id && $event['kind'] === $kind) {
                return $event;
            }
        }
        $this->unavailable();
    }

    private function audit(array $bucket, array $event, User $actor): array
    {
        return $this->evidence->audit('membership.test_credit.'.$event['kind'], MembershipCreditBucket::class, (int) $bucket['id'],
            ['schema_version' => 1, 'event_id' => (int) $event['id'], 'event_hash' => $event['event_hash'],
                'account_id' => (int) $bucket['customer_account_id'], 'plan_version_id' => (int) $bucket['membership_plan_version_id'], 'test_only' => true], (int) $actor->getKey());
    }

    private function finish(User $actor, ?CustomerPrincipal $principal, array $users, array $account, array $parent, array $version, array $bucket, array $events, ?array $audit, ?int $cursor = null): void
    {
        $auditRows = $this->auditHistory($bucket, $events);
        if ($principal === null) {
            $this->evidence->recheckOperator($actor);
        } else {
            $this->policy->requireEnabled();
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
        }
        $this->proveDeadline($bucket, $events, $audit);
        if ($this->rawHistory((int) $bucket['id']) !== $events || $this->auditHistory($bucket, $events) !== $auditRows
            || ($audit === null && $this->evidence->cursor(MembershipCreditBucket::class, (int) $bucket['id']) !== $cursor)) {
            $this->unavailable();
        }
        $finalAt = $this->at();
        $this->evidence->prove($users, $account, [['membership_plans', (int) $parent['id'], $parent], ['membership_plan_versions', (int) $version['id'], $version],
            ['membership_credit_buckets', (int) $bucket['id'], $bucket], ...array_map(fn ($event) => ['membership_credit_events', (int) $event['id'], $event], $events),
            ...array_map(fn ($row) => ['audit_events', (int) $row['id'], $row], $auditRows)], $audit,
            [['membership_credit_events', ['membership_credit_bucket_id' => (int) $bucket['id']], 'sequence', MembershipPolicy::MAX_EVENTS + 1, $events],
                ['audit_events', ['subject_type' => MembershipCreditBucket::class, 'subject_id' => (int) $bucket['id']], 'id', MembershipPolicy::MAX_EVENTS + 1, $auditRows]],
            [[MembershipCreditBucket::class, (int) $bucket['id'], $audit === null ? $cursor : (int) $audit['id']]]);
        $this->policy->requireEnabled();
        if ($principal !== null) {
            app(CustomerAccessPolicy::class)->requireEnabled();
        }
        $this->proveDeadline($bucket, $events, $audit, $finalAt);
    }

    private function proveDeadline(array $bucket, array $events, ?array $audit, ?string $at = null): void
    {
        if ($audit === null) {
            return; // Exact replays and reads never repeat the original movement.
        }
        $last = $events[array_key_last($events)];
        $at ??= $this->at();
        if ($at < $last['created_at'] || ($bucket['expires_at'] !== null
            && (($last['created_at'] >= $bucket['expires_at']) !== ($at >= $bucket['expires_at'])))) {
            $this->unavailable();
        }
    }

    private function auditHistory(array $bucket, array $events): array
    {
        $rows = DB::table('audit_events')->where('subject_type', MembershipCreditBucket::class)->where('subject_id', $bucket['id'])
            ->orderBy('id')->limit(MembershipPolicy::MAX_EVENTS + 1)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
        if (count($rows) !== count($events)) {
            $this->unavailable();
        }
        foreach ($rows as $index => $row) {
            $event = $events[$index];
            $expected = ['schema_version' => 1, 'event_id' => (int) $event['id'], 'event_hash' => $event['event_hash'],
                'account_id' => (int) $bucket['customer_account_id'], 'plan_version_id' => (int) $bucket['membership_plan_version_id'], 'test_only' => true];
            if ($row['action'] !== 'membership.test_credit.'.$event['kind'] || (int) $row['actor_id'] !== (int) $event['actor_id']
                || CanonicalJson::hash(json_decode($row['context'], true, 32, JSON_THROW_ON_ERROR)) !== CanonicalJson::hash($expected)) {
                $this->unavailable();
            }
        }

        return $rows;
    }

    private function result(array $event): array
    {
        return ['bucket_id' => (int) $event['membership_credit_bucket_id'], 'event_id' => (int) $event['id'], 'kind' => $event['kind'], 'amount' => (int) $event['amount'],
            'reservation_event_id' => $event['reservation_event_id'] === null ? null : (int) $event['reservation_event_id'], 'balance' => $this->balance($event['after_balance'])];
    }

    private function at(): string
    {
        return now()->utc()->startOfSecond()->format('Y-m-d H:i:s');
    }

    private function unavailable(): never
    {
        $this->policy->reject('credits', 'Credit evidence is unavailable or this exact movement is no longer eligible.');
    }
}
