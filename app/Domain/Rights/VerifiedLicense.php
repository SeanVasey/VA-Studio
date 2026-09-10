<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseReviewEvidence;
use App\Domain\Rights\Models\LicenseVersion;
use App\Support\CanonicalJson;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

final class VerifiedLicense
{
    public function available(LicenseVersion $version, ?CarbonInterface $at = null): bool
    {
        try {
            $version = $version->fresh();
            if (! $version || $version->status !== 'published' || ! $version->published_at) {
                return false;
            }
            $this->assertReviewed($version);
            $at ??= now();

            return (! $version->effective_from || $at->greaterThanOrEqualTo($version->effective_from))
                && (! $version->effective_until || $at->lessThan($version->effective_until));
        } catch (Throwable) {
            return false;
        }
    }

    public function assertSubmitted(LicenseVersion $version): void
    {
        $payload = app(LicenseReviewPayload::class)->build($version);
        $authors = $version->content_author_ids;
        if (! is_array($authors) || ! array_is_list($authors) || $authors === [] || count(array_filter($authors, is_int(...))) !== count($authors) || count(array_unique($authors)) !== count($authors) || ! in_array($version->author_id, $authors, true)) {
            $this->fail('Submitted content requires its complete server-recorded contributor list.');
        }
        if ($version->canonicalization_version !== CanonicalJson::VERSION || $version->terms_schema_version !== $payload['structured_terms']['schema_version']
            || ! $version->submitted_at || ! $version->submitted_by
            || $version->submission_hash !== CanonicalJson::hash($payload)
            || CanonicalJson::hash($version->submission_payload) !== $version->submission_hash
            || $version->source_hash !== $payload['source_hash'] || $version->model_hash !== $payload['model_hash']
            || $version->renderer_version !== $payload['renderer_version'] || $version->render_fixture_hash !== $payload['render_fixture_hash']) {
            $this->fail('The submitted content or preview evidence is missing or inconsistent. Create a reviewed successor for historical versions.');
        }
    }

    public function assertReviewed(LicenseVersion $version): void
    {
        $this->assertSubmitted($version);
        $evidence = $version->reviewEvidence()->first();
        if (! $evidence || ! $version->approved_by || ! $version->approved_at || in_array($version->approved_by, $version->content_author_ids, true)
            || $evidence->reviewer_id !== $version->approved_by || $evidence->submission_hash !== $version->submission_hash
            || $evidence->approval_reference !== $version->approval_reference || ! $evidence->summary_consistency_confirmed
            || $evidence->reviewed_at->format('Y-m-d H:i:s') !== $version->approved_at->format('Y-m-d H:i:s')
            || $evidence->evidence_hash !== CanonicalJson::hash(self::evidencePayload($evidence))) {
            $this->fail('Approval must bind a separate authorized reviewer to this exact submission and preview.');
        }
    }

    public static function evidencePayload(LicenseReviewEvidence $evidence): array
    {
        return [
            'license_version_id' => $evidence->license_version_id,
            'submission_hash' => $evidence->submission_hash,
            'reviewer_id' => $evidence->reviewer_id,
            'approval_reference' => $evidence->approval_reference,
            'summary_consistency_confirmed' => $evidence->summary_consistency_confirmed,
            'reviewed_at' => $evidence->reviewed_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'canonicalization_version' => CanonicalJson::VERSION,
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['license' => $message]);
    }
}
