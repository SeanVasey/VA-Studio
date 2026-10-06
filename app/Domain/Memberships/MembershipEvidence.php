<?php

namespace App\Domain\Memberships;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Internal current authority and raw evidence; no owner keys or credentials leave the module. */
final class MembershipEvidence
{
    public function operator(User $actor, ?int $customerUserId = null): array
    {
        $ids = array_values(array_unique([$actor->exists ? (int) $actor->getKey() : 0, ...($customerUserId === null ? [] : [$customerUserId])]));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if ($id < 1 || User::query()->lockForUpdate()->find($id) === null) {
                throw new AuthorizationException;
            }
        }
        $rows = $this->users($ids);
        $this->recheckOperator($actor);
        $this->sameUsers($rows);

        return $rows;
    }

    public function buyer(CustomerPrincipal $principal, User $actor): array
    {
        app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
        $users = $this->users([$principal->userId]);
        $account = $this->account($principal->accountId);
        $user = $users[$principal->userId];
        $stamp = hash_hmac('sha256', "customer-credential-v1\0".$user['password'], (string) config('app.key'));
        if ((bool) $user['is_admin'] || $user['email_verified_at'] === null || ! (bool) $account['active']
            || (int) $account['user_id'] !== $principal->userId || (int) $account['access_version'] !== $principal->accessVersion
            || ! hash_equals($account['owner_key'], $principal->ownerKey) || ! hash_equals($stamp, $principal->credentialStamp)) {
            throw new AuthorizationException;
        }

        return ['users' => $users, 'account' => $account];
    }

    public function account(int $id, ?int $userId = null): array
    {
        $row = $this->row('customer_accounts', $id);
        $user = $this->row('users', (int) $row['user_id']);
        if (($userId !== null && (int) $row['user_id'] !== $userId) || ! (bool) $row['active'] || (int) $row['access_version'] < 1
            || (bool) $user['is_admin'] || $user['email_verified_at'] === null) {
            throw new AuthorizationException;
        }

        return $row;
    }

    public function recheckOperator(User $actor): void
    {
        app(MembershipPolicy::class)->requireEnabled();
        $current = User::query()->lockForUpdate()->find($actor->getKey());
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
    }

    public function users(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->row('users', $id);
        }

        return $rows;
    }

    public function sameUsers(array $rows): void
    {
        foreach ($rows as $id => $row) {
            $this->same('users', (int) $id, $row);
        }
    }

    public function row(string $table, int $id): array
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            app(MembershipPolicy::class)->reject('membership', 'Retained membership evidence is unavailable.');
        }

        return (array) $row;
    }

    public function same(string $table, int $id, array $expected): void
    {
        if ($this->row($table, $id) !== $expected) {
            app(MembershipPolicy::class)->reject('membership', 'Membership evidence changed during the command.');
        }
    }

    public function cursor(string $type, int $id): ?int
    {
        $value = DB::table('audit_events')->where('subject_type', $type)->where('subject_id', $id)->orderByDesc('id')->lockForUpdate()->value('id');

        return $value === null ? null : (int) $value;
    }

    public function audit(string $action, string $type, int $id, array $context, int $actorId): array
    {
        $at = now()->utc()->startOfSecond()->format('Y-m-d H:i:s');
        $event = AuditEvent::create(['actor_id' => $actorId, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id,
            'context' => $context, 'created_at' => $at]);
        $row = $this->row('audit_events', (int) $event->getKey());
        if ((int) $row['actor_id'] !== $actorId || $row['action'] !== $action || $row['subject_type'] !== $type || (int) $row['subject_id'] !== $id
            || CanonicalJson::hash(json_decode($row['context'], true, 32, JSON_THROW_ON_ERROR)) !== CanonicalJson::hash($context)
            || $row['created_at'] !== $at
            || $this->cursor($type, $id) !== (int) $event->getKey()) {
            app(MembershipPolicy::class)->reject('membership', 'The exact membership audit was not retained.');
        }

        return $row;
    }

    public function prove(array $users, ?array $account, array $rows, ?array $audit): void
    {
        // All Eloquent authority/audit callbacks have completed. Only raw locking reads follow.
        $this->sameUsers($users);
        if ($account !== null) {
            $this->same('customer_accounts', (int) $account['id'], $account);
        }
        foreach ($rows as [$table, $id, $expected]) {
            $this->same($table, $id, $expected);
        }
        if ($audit !== null) {
            $this->same('audit_events', (int) $audit['id'], $audit);
            if ($this->cursor($audit['subject_type'], (int) $audit['subject_id']) !== (int) $audit['id']) {
                app(MembershipPolicy::class)->reject('membership', 'Unexpected extra membership audit evidence.');
            }
        }
    }
}
