<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\Audit\AuditEvent;

class VerifyRightsDeclaration
{
    public function handle(RightsDeclaration $declaration, User $actor): RightsDeclaration
    {
        $boundary = app(RightsDeclarationBoundary::class);

        return $boundary->transaction($actor, function (User $current) use ($declaration, $boundary): RightsDeclaration {
            [$locked] = $boundary->lockIdentity($declaration);

            return $this->verify($locked, $current, $boundary);
        });
    }

    public function review(RightsDeclaration $declaration, User $actor): array
    {
        return app(SaveRightsDeclaration::class)->review($declaration, $actor, 'verify');
    }

    public function verifyReviewed(array $review, User $actor): RightsDeclaration
    {
        $boundary = app(RightsDeclarationBoundary::class);

        return $boundary->transaction($actor, function (User $current) use ($review, $boundary): RightsDeclaration {
            $boundary->validateReview($review, $current, 'verify');
            [$locked, $track] = $boundary->lockDeclaration($review['declaration_id'], $review['track_id']);
            $boundary->compareReview($review, $locked, $track, $current, 'verify');

            return $this->verify($locked, $current, $boundary);
        });
    }

    private function verify(RightsDeclaration $locked, User $actor, RightsDeclarationBoundary $boundary): RightsDeclaration
    {
        $boundary->requireVerificationEvidence($locked);
        $before = $boundary->evidenceHash($locked);
        $locked->update(['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        AuditEvent::record('rights.declaration.verified', $locked, ['schema_version' => 1, 'track_id' => (int) $locked->track_id,
            'changed_fields' => ['status', 'verified_by', 'verified_at'], 'before_hash' => $before,
            'after_hash' => $boundary->evidenceHash($locked)], $actor->id);

        return $locked;
    }
}
