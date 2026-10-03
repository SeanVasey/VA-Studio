<?php

namespace App\Domain\Media;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Interactive authorization and retained worker attribution are distinct boundaries. */
final class MediaWriterActor
{
    public function authorize(User $actor): User
    {
        $this->requireTransaction();
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);

        return $current;
    }

    public function requester(int $actorId): void
    {
        $this->requireTransaction();
        // A queued request retains its requester identity, not a live interactive staff session.
        if ($actorId < 1 || User::query()->lockForUpdate()->find($actorId) === null) {
            throw new MediaFailure('requester_missing', 'The retained media requester is unavailable.');
        }
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Media writer actor fences require a transaction.');
        }
    }
}
