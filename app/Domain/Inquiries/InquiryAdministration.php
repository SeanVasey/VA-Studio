<?php

namespace App\Domain\Inquiries;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class InquiryAdministration
{
    public function actor(?User $actor): User
    {
        request()->attributes->set('_inquiry_private_admin', true);
        $current = $actor?->exists ? User::find($actor->getKey()) : null;
        if ($current === null || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current)) {
            throw new AuthorizationException;
        }

        return $current;
    }

    public function view(int $id, User $actor): CustomerInquiry
    {
        return DB::transaction(function () use ($id, $actor): CustomerInquiry {
            $actor = $this->actor($actor);
            $inquiry = CustomerInquiry::findOrFail($id);
            // An audit failure prevents the private body from being returned to the caller.
            AuditEvent::record('inquiry.viewed', $inquiry, ['receipt' => $inquiry->public_id, 'state' => $inquiry->state], $actor->id);

            return $inquiry;
        });
    }

    public function openInbox(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $actor = $this->actor($actor);
            AuditEvent::record('inquiry.inbox_viewed', $actor, ['scope' => 'retained inquiries'], $actor->id);
        });
    }

    public function transition(int $id, string $target, int $expectedVersion, User $actor): CustomerInquiry
    {
        return DB::transaction(function () use ($id, $target, $expectedVersion, $actor): CustomerInquiry {
            $actor = $this->actor($actor);
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
