<?php

namespace App\Domain\Memberships;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Memberships\Models\MembershipCreditBucket;
use App\Domain\Memberships\Models\MembershipPlan;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Private operational reads. No session switch, credit mutation or invoice adapter. */
final class MembershipAdministration
{
    public function __construct(private MembershipPolicy $policy, private MembershipEvidence $evidence, private CreditLedger $ledger) {}

    public function authorize(?User $actor): User
    {
        $this->policy->standalone();
        if (! $actor?->exists) {
            throw new AuthorizationException;
        }
        DB::transaction(function () use ($actor): void {
            $users = $this->operator($actor);
            $this->evidence->prove($users, null, [], null);
            $this->policy->requireEnabled();
        });

        return $actor;
    }

    public function planHistory(int $id, User $actor): array
    {
        $this->policy->standalone();

        return DB::transaction(function () use ($id, $actor): array {
            $users = $this->operator($actor);
            $plan = $this->evidence->row('membership_plans', $id);
            $versions = $this->rawVersions($id);
            if ($versions === [] || count($versions) > MembershipPolicy::MAX_EVENTS) {
                $this->unavailable();
            }
            $display = [];
            foreach ($versions as $index => $version) {
                $data = $this->policy->plan(['title' => $version['title'], 'policy' => json_decode($version['policy'], true, 32, JSON_THROW_ON_ERROR)]);
                if ((int) $version['number'] !== $index + 1 || $version['manifest_hash'] !== CanonicalJson::hash($data)) {
                    $this->unavailable();
                }
                $display[] = ['version_id' => (int) $version['id'], 'number' => (int) $version['number'],
                    ...$data, 'created_at' => $version['created_at']];
            }
            $cursor = $this->evidence->cursor(MembershipPlan::class, $id);
            $audits = $this->evidence->auditRows(MembershipPlan::class, $id);
            $this->recheck($actor);
            // Direct primary rows/ranges/cursors are the terminal work after callbacks.
            $this->evidence->prove($users, null, [['membership_plans', $id, $plan],
                ...array_map(fn ($row) => ['membership_plan_versions', (int) $row['id'], $row], $versions)], null,
                [['membership_plan_versions', ['membership_plan_id' => $id], 'number', MembershipPolicy::MAX_EVENTS + 1, $versions],
                    ['audit_events', ['subject_type' => MembershipPlan::class, 'subject_id' => $id], 'id', MembershipPolicy::MAX_EVENTS * 2 + 1, $audits]],
                [[MembershipPlan::class, $id, $cursor]]);
            $this->policy->requireEnabled();
            $latest = $versions[array_key_last($versions)];

            return ['plan_id' => $id, 'plan_hash' => CanonicalJson::hash($plan), 'version_hash' => CanonicalJson::hash($latest),
                'history_hash' => CanonicalJson::hash($versions), 'audit_id' => $cursor, 'versions' => $display];
        });
    }

    public function accounts(User $actor): array
    {
        $this->policy->standalone();
        app(CustomerAccessPolicy::class)->requireEnabled();

        return DB::transaction(function () use ($actor): array {
            $users = $this->operator($actor);
            // These IDs are selection hints, not retained customer evidence or impersonation.
            $ids = DB::table('customer_accounts as a')->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.active', true)->where('u.is_admin', false)->whereNotNull('u.email_verified_at')
                ->where('a.access_version', '>=', 1)->orderBy('a.id')->limit(MembershipPolicy::MAX_EVENTS + 1)->pluck('a.id')->all();
            if (count($ids) > MembershipPolicy::MAX_EVENTS) {
                $this->unavailable();
            }
            $this->recheck($actor);
            $this->evidence->prove($users, null, [], null);
            $this->policy->requireEnabled();
            app(CustomerAccessPolicy::class)->requireEnabled();

            return array_combine(array_map(fn ($id) => (string) $id, $ids), array_map(fn ($id) => 'Test account #'.$id, $ids));
        });
    }

    public function creditHistory(int $accountId, int $bucketId, User $operator): array
    {
        $this->policy->standalone();
        app(CustomerAccessPolicy::class)->requireEnabled();
        // Hints establish only the required lock identities. No baseline read precedes the fences.
        $customerId = DB::table('customer_accounts')->where('id', $accountId)->value('user_id');
        $bucketHint = DB::table('membership_credit_buckets')->where('id', $bucketId)->first();
        $versionHint = $bucketHint === null ? null : DB::table('membership_plan_versions')->where('id', $bucketHint->membership_plan_version_id)->first();
        if ($customerId === null || $versionHint === null) {
            $this->unavailable();
        }
        $planId = (int) $versionHint->membership_plan_id;
        $versionId = (int) $versionHint->id;
        $snapshot = DB::transaction(function () use ($accountId, $bucketId, $operator, $customerId, $planId, $versionId): array {
            $users = $this->operator($operator, (int) $customerId);
            $account = $this->evidence->account($accountId, (int) $customerId);
            $scope = $this->scope($accountId, $bucketId, $planId, $versionId);
            $customer = User::query()->lockForUpdate()->findOrFail($customerId);
            $principal = app(CustomerAccess::class)->principal($customer);
            if ($principal->accountId !== $accountId) {
                throw new AuthorizationException;
            }
            app(CustomerAccess::class)->current($principal);
            $this->recheck($operator);
            $this->proveScope($users, $account, $scope);
            $this->policy->requireEnabled();
            app(CustomerAccessPolicy::class)->requireEnabled();

            return compact('users', 'account', 'scope', 'customer', 'principal');
        });
        $started = $this->at();
        // The unchanged ledger owns its own transaction and every balance/expiry rule.
        // A current internal customer principal is delegated only to this read; auth/session never changes.
        $read = $this->ledger->read($bucketId, $snapshot['principal'], $snapshot['customer']);

        return DB::transaction(function () use ($accountId, $bucketId, $operator, $customerId, $planId, $versionId, $snapshot, $started, $read): array {
            $this->operator($operator, (int) $customerId);
            $this->evidence->account($accountId, (int) $customerId);
            $current = $this->scope($accountId, $bucketId, $planId, $versionId);
            app(CustomerAccess::class)->current($snapshot['principal']);
            $this->recheck($operator);
            $expiry = $snapshot['scope']['bucket']['expires_at'];
            if ($current !== $snapshot['scope']) {
                $this->unavailable();
            }
            $events = $current['events'];
            $last = $events === [] ? null : $events[array_key_last($events)];
            if ($last === null || ($read['bucket_id'] ?? null) !== $bucketId
                || ($read['plan_version_id'] ?? null) !== (int) $current['version']['id']
                || ($read['last_event_id'] ?? null) !== (int) $last['id']
                || ($read['expires_at'] ?? null) !== $expiry
                || CanonicalJson::hash($read['balance'] ?? null) !== CanonicalJson::hash(json_decode($last['after_balance'], true, 32, JSON_THROW_ON_ERROR))) {
                $this->unavailable();
            }
            // Full raw baseline and history/audit equality is the last work after all callbacks.
            $this->proveScope($snapshot['users'], $snapshot['account'], $snapshot['scope']);
            $this->policy->requireEnabled();
            app(CustomerAccessPolicy::class)->requireEnabled();
            $final = $this->at();
            if ($final < $started || ($expiry !== null && ($started < $expiry) !== ($final < $expiry))) {
                $this->unavailable();
            }

            return ['account_id' => $accountId, 'bucket_id' => $bucketId, 'unit' => $read['unit'],
                'expires_at' => $read['expires_at'], 'last_event_id' => $read['last_event_id'],
                'balance' => $read['balance'], 'spendable_credits' => $read['spendable_credits'], 'checked_at' => $final,
                'events' => array_map(fn ($event) => ['id' => (int) $event['id'], 'kind' => $event['kind'],
                    'amount' => (int) $event['amount'], 'created_at' => $event['created_at']], $events)];
        });
    }

    private function scope(int $accountId, int $bucketId, int $planId, int $versionId): array
    {
        $plan = $this->evidence->row('membership_plans', $planId);
        $version = $this->evidence->row('membership_plan_versions', $versionId);
        $bucket = $this->evidence->row('membership_credit_buckets', $bucketId);
        if ((int) $bucket['customer_account_id'] !== $accountId || (int) $bucket['membership_plan_version_id'] !== (int) $version['id']
            || (int) $version['membership_plan_id'] !== (int) $plan['id']) {
            throw new AuthorizationException;
        }
        $events = $this->rawEvents($bucketId);
        if (count($events) > MembershipPolicy::MAX_EVENTS) {
            $this->unavailable();
        }
        $audits = $this->rawAudits((int) $plan['id'], $bucketId);
        if (count($audits) > MembershipPolicy::MAX_EVENTS * 2) {
            $this->unavailable();
        }

        return compact('plan', 'version', 'bucket', 'events', 'audits');
    }

    private function proveScope(array $users, array $account, array $scope): void
    {
        $rows = [['membership_plans', (int) $scope['plan']['id'], $scope['plan']],
            ['membership_plan_versions', (int) $scope['version']['id'], $scope['version']],
            ['membership_credit_buckets', (int) $scope['bucket']['id'], $scope['bucket']],
            ...array_map(fn ($row) => ['membership_credit_events', (int) $row['id'], $row], $scope['events']),
            ...array_map(fn ($row) => ['audit_events', (int) $row['id'], $row], $scope['audits'])];
        $planId = (int) $scope['plan']['id'];
        $bucketId = (int) $scope['bucket']['id'];
        $planAudits = array_values(array_filter($scope['audits'], fn ($row) => $row['subject_type'] === MembershipPlan::class && (int) $row['subject_id'] === $planId));
        $bucketAudits = array_values(array_filter($scope['audits'], fn ($row) => $row['subject_type'] === MembershipCreditBucket::class && (int) $row['subject_id'] === $bucketId));
        $this->evidence->prove($users, $account, $rows, null,
            [['membership_credit_events', ['membership_credit_bucket_id' => $bucketId], 'sequence', MembershipPolicy::MAX_EVENTS + 1, $scope['events']],
                ['audit_events', ['subject_type' => MembershipPlan::class, 'subject_id' => $planId], 'id', MembershipPolicy::MAX_EVENTS * 2 + 1, $planAudits],
                ['audit_events', ['subject_type' => MembershipCreditBucket::class, 'subject_id' => $bucketId], 'id', MembershipPolicy::MAX_EVENTS * 2 + 1, $bucketAudits]],
            [[MembershipPlan::class, $planId, $planAudits === [] ? null : (int) $planAudits[array_key_last($planAudits)]['id']],
                [MembershipCreditBucket::class, $bucketId, $bucketAudits === [] ? null : (int) $bucketAudits[array_key_last($bucketAudits)]['id']]]);
    }

    private function operator(User $actor, ?int $customerId = null): array
    {
        $users = $this->evidence->operator($actor, $customerId);
        $this->requireMfa($actor);

        return $users;
    }

    private function recheck(User $actor): void
    {
        $this->evidence->recheckOperator($actor);
        $this->requireMfa($actor);
    }

    private function requireMfa(User $actor): void
    {
        $panel = Filament::getPanel('admin');
        if ($panel === null) {
            throw new AuthorizationException;
        }
        // Private authoring/inspection requires enrolled MFA even in this local test panel.
        // Clone the panel instead of mutating the shared production or fixture configuration.
        $required = clone $panel;
        $required->multiFactorAuthentication($required->getMultiFactorAuthenticationProviders(), isRequired: true);
        if (! AdminMultiFactor::satisfiedBy($actor, $required, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
    }

    private function rawVersions(int $id): array
    {
        return DB::table('membership_plan_versions')->where('membership_plan_id', $id)->orderBy('number')
            ->limit(MembershipPolicy::MAX_EVENTS + 1)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
    }

    private function rawEvents(int $id): array
    {
        return DB::table('membership_credit_events')->where('membership_credit_bucket_id', $id)->orderBy('sequence')
            ->limit(MembershipPolicy::MAX_EVENTS + 1)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
    }

    private function rawAudits(int $planId, int $bucketId): array
    {
        return DB::table('audit_events')->where(fn ($query) => $query
            ->where(fn ($query) => $query->where('subject_type', MembershipPlan::class)->where('subject_id', $planId))
            ->orWhere(fn ($query) => $query->where('subject_type', MembershipCreditBucket::class)->where('subject_id', $bucketId)))
            ->orderBy('id')->limit(MembershipPolicy::MAX_EVENTS * 2 + 1)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
    }

    private function at(): string
    {
        return now()->utc()->startOfSecond()->format('Y-m-d H:i:s');
    }

    private function unavailable(): never
    {
        $this->policy->reject('membership', 'This private membership read is unavailable or changed. Reopen it to inspect current evidence.');
    }
}
