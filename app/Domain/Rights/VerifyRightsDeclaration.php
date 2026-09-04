<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VerifyRightsDeclaration
{
    public function handle(RightsDeclaration $declaration, User $actor): RightsDeclaration
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($declaration, $actor) {
            $locked = RightsDeclaration::query()->lockForUpdate()->findOrFail($declaration->id);
            if ($locked->status !== 'pending' || ! $locked->provenance_reference || ! $locked->sample_disclosure) {
                throw ValidationException::withMessages(['rights' => 'Verification requires a pending declaration with provenance and sample disclosure.']);
            }
            $locked->update(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
            AuditEvent::record('rights.declaration.verified', $locked, [], $actor->id);

            return $locked;
        });
    }
}
