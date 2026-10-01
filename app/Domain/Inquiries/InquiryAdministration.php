<?php

namespace App\Domain\Inquiries;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use LogicException;

final class InquiryAdministration
{
    public function actor(?User $actor, bool $lockForUpdate = false): User
    {
        request()->attributes->set('_inquiry_private_admin', true);
        if ($lockForUpdate && DB::transactionLevel() === 0) {
            throw new AuthorizationException;
        }
        $query = User::query();
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $current = $actor?->exists ? $query->find($actor->getKey()) : null;
        if ($current === null || ! Gate::forUser($current)->allows('administer-catalog', $lockForUpdate ? [true] : [])
            || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: $lockForUpdate)) {
            throw new AuthorizationException;
        }

        return $current;
    }

    /** Execute trusted eager page reads, including cached records, while current authority remains locked. */
    public function authorizedRead(?User $actor, callable $eagerRead): mixed
    {
        return DB::transaction(function () use ($actor, $eagerRead): mixed {
            $this->actor($actor, lockForUpdate: true);
            $result = $eagerRead();
            if ($result instanceof Builder || $result instanceof QueryBuilder || $result instanceof Relation
                || $result instanceof LazyCollection || $result instanceof Generator) {
                throw new LogicException('Private inquiry reads must complete before the authority lock is released.');
            }

            return $result;
        });
    }

    public function view(int $id, User $actor): CustomerInquiry
    {
        return DB::transaction(function () use ($id, $actor): CustomerInquiry {
            $actor = $this->actor($actor, lockForUpdate: true);
            $inquiry = CustomerInquiry::findOrFail($id);
            // An audit failure prevents the private body from being returned to the caller.
            AuditEvent::record('inquiry.viewed', $inquiry, ['receipt' => $inquiry->public_id, 'state' => $inquiry->state], $actor->id);

            return $inquiry;
        });
    }

    public function openInbox(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $actor = $this->actor($actor, lockForUpdate: true);
            AuditEvent::record('inquiry.inbox_viewed', $actor, ['scope' => 'retained inquiries'], $actor->id);
        });
    }

    public function transition(int $id, string $target, int $expectedVersion, User $actor): CustomerInquiry
    {
        return DB::transaction(function () use ($id, $target, $expectedVersion, $actor): CustomerInquiry {
            $actor = $this->actor($actor, lockForUpdate: true);
            $inquiry = CustomerInquiry::lockForUpdate()->findOrFail($id);
            if (! in_array($target, ['read', 'archived'], true) || $expectedVersion < 0 || $expectedVersion !== $inquiry->version
                || $inquiry->version >= 2147483646 || ($inquiry->state === 'archived' && $target !== 'archived')) {
                throw ValidationException::withMessages(['inquiry' => 'This inquiry changed. Refresh the inbox before applying an action.']);
            }
            if ($inquiry->state === $target) {
                return $inquiry;
            }
            $previous = $inquiry->state;
            $inquiry->update(['state' => $target, 'version' => $inquiry->version + 1, 'updated_at' => now()]);
            AuditEvent::record('inquiry.'.$target, $inquiry, ['receipt' => $inquiry->public_id, 'before' => $previous, 'version' => $inquiry->version], $actor->id);

            return $inquiry;
        });
    }
}
