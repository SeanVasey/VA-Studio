<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseReviewEvidence;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReviewLicense
{
    public function submit(LicenseVersion $version, User $actor): LicenseVersion
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($version, $actor) {
            // Template identity is part of the review and must not race a template edit.
            LicenseTemplate::query()->lockForUpdate()->findOrFail($version->license_template_id);
            $locked = LicenseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['license' => 'Only a draft may be submitted for review.']);
            }
            $payload = app(LicenseReviewPayload::class)->build($locked);
            $locked->update([
                'status' => 'legal_review', 'submission_payload' => $payload, 'submission_hash' => CanonicalJson::hash($payload),
                'canonicalization_version' => CanonicalJson::VERSION, 'terms_schema_version' => LicenseTerms::SCHEMA_VERSION,
                'submitted_by' => $actor->id, 'submitted_at' => now()->startOfSecond(),
                'source_hash' => $payload['source_hash'], 'model_hash' => $payload['model_hash'],
                'renderer_version' => $payload['renderer_version'], 'render_fixture_hash' => $payload['render_fixture_hash'],
            ]);
            AuditEvent::record('rights.license.review_requested', $locked, ['submission_hash' => $locked->submission_hash], $actor->id);

            return $locked;
        });
    }

    public function approve(LicenseVersion $version, User $actor, array $evidence): LicenseVersion
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        $evidence = Validator::make($evidence, [
            'approval_reference' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'review_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'summary_consistency_confirmed' => ['required', 'accepted'],
        ])->validate();

        return DB::transaction(function () use ($version, $actor, $evidence) {
            $locked = LicenseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'legal_review' || in_array($actor->id, $locked->content_author_ids ?? [$locked->author_id], true)) {
                throw ValidationException::withMessages(['license' => 'Approval needs a version in legal review and a authorized reviewer who has not authored or edited this version.']);
            }
            app(VerifiedLicense::class)->assertSubmitted($locked);
            if (! hash_equals($locked->submission_hash, $evidence['review_hash'])) {
                throw ValidationException::withMessages(['review_hash' => 'Review evidence does not match this submitted content. Reopen the exact preview.']);
            }
            $review = new LicenseReviewEvidence([
                'license_version_id' => $locked->id, 'submission_hash' => $locked->submission_hash,
                'reviewer_id' => $actor->id, 'approval_reference' => trim($evidence['approval_reference']),
                'summary_consistency_confirmed' => true, 'reviewed_at' => now()->startOfSecond(),
            ]);
            $review->evidence_hash = CanonicalJson::hash(VerifiedLicense::evidencePayload($review));
            $review->save();
            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $review->reviewed_at, 'approval_reference' => $review->approval_reference]);
            AuditEvent::record('rights.license.approved', $locked, ['approval_reference' => $review->approval_reference, 'evidence_hash' => $review->evidence_hash], $actor->id);

            return $locked;
        });
    }
}
