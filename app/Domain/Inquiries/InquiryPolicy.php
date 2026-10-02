<?php

namespace App\Domain\Inquiries;

use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class InquiryPolicy
{
    /** Only the notice and opaque noticeToken belong in public props. Internal setup values stay private. */
    public function publicSetup(array $verifiedCurrentContent, bool $lockForUpdate = false): ?array
    {
        if ($lockForUpdate && DB::transactionLevel() === 0) {
            return null;
        }
        $notice = config('inquiries.privacy_notice');
        $reference = config('inquiries.retention_policy_reference');
        $operatorId = config('inquiries.operator_user_id');
        if (config('inquiries.enabled') !== true || ! is_array($verifiedCurrentContent['contact'] ?? null)
            || ! $this->plain($notice, 3000) || ! $this->plain($reference, 120)
            || ! is_scalar($operatorId) || ! preg_match('/\A[1-9][0-9]{0,15}\z/D', (string) $operatorId)) {
            return null;
        }
        // The displayed content must belong to this exact selected publication. A caller's old
        // repeatable-read snapshot must never mint a token for a different current release.
        $publication = $lockForUpdate ? SitePublication::query()->lockForUpdate()->find(1) : SitePublication::find(1);
        $release = $publication?->active_release_id === null ? null : SiteRelease::find($publication->active_release_id);
        $key = config('app.key');
        if ($release === null || ! is_string($key) || $key === ''
            || ! hash_equals($release->content_hash, CanonicalJson::hash($verifiedCurrentContent))) {
            return null;
        }
        $operator = $lockForUpdate ? User::query()->lockForUpdate()->find($operatorId) : User::find($operatorId);
        if ($operator === null || ! Gate::forUser($operator)->allows('administer-catalog', $lockForUpdate ? [true] : [])
            || ! AdminMultiFactor::satisfiedBy($operator, lockForUpdate: $lockForUpdate)) {
            return null;
        }

        $privacyHash = hash('sha256', $notice);
        $token = hash_hmac('sha256', CanonicalJson::encode([
            'purpose' => 'vasey-audio-inquiry-notice-v1', 'privacyHash' => $privacyHash,
            'releaseId' => $release->id, 'contentHash' => $release->content_hash, 'revision' => $publication->revision,
            'retentionHash' => hash('sha256', $reference), 'operatorId' => $operator->id,
        ]), $key);

        return ['privacyNotice' => $notice, 'privacyHash' => $privacyHash, 'retentionReference' => $reference,
            'operatorId' => $operator->id, 'noticeToken' => $token];
    }

    private function plain(mixed $value, int $max): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && trim($value) !== '' && mb_strlen($value) <= $max
            && preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 0;
    }
}
