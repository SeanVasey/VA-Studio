<?php

namespace App\Domain\Inquiries;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Notifications\InquiryNotificationWork;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubmitInquiry
{
    public function handle(array $body, string $ownerHash): array
    {
        $body = InquiryInput::validate($body);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $ownerHash) !== 1) {
            throw new InquiryException(422);
        }
        $payload = array_diff_key($body, ['requestKey' => true]);
        $hash = CanonicalJson::hash($payload);
        try {
            return DB::transaction(function () use ($body, $payload, $hash, $ownerHash): array {
                // Publication withdrawal serializes with admission; drafts cannot activate this intake.
                $publication = SitePublication::lockForUpdate()->find(1);
                $setup = app(InquiryPolicy::class)->publicSetup(app(SiteContent::class)->current());
                if ($setup === null || $publication?->active_release_id === null) {
                    throw new InquiryException(404);
                }
                $existing = CustomerInquiry::where('request_key', $body['requestKey'])->first();
                if ($existing !== null) {
                    return $this->replay($existing, $hash, $ownerHash);
                }
                $release = SiteRelease::findOrFail($publication->active_release_id);
                $inquiry = CustomerInquiry::create([
                    'public_id' => (string) Str::uuid(), 'owner_hash' => $ownerHash, 'request_key' => $body['requestKey'], 'payload_hash' => $hash,
                    'payload' => $payload, 'privacy_notice' => $setup['privacyNotice'], 'privacy_notice_hash' => $setup['privacyHash'],
                    'retention_policy_reference' => $setup['retentionReference'], 'operator_user_id' => $setup['operatorId'],
                    'site_release_id' => $release->id, 'site_content_hash' => $release->content_hash,
                    'state' => 'new', 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                AuditEvent::record('inquiry.received', $inquiry, ['receipt' => $inquiry->public_id, 'site_release_id' => $release->id, 'privacy_notice_hash' => $setup['privacyHash']]);
                app(InquiryNotificationWork::class)->retain($inquiry);

                return ['state' => 'saved', 'receipt' => $inquiry->public_id, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $error) {
            $existing = CustomerInquiry::where('request_key', $body['requestKey'])->first();
            if ($existing === null) {
                throw $error;
            }

            return $this->replay($existing, $hash, $ownerHash);
        }
    }

    private function replay(CustomerInquiry $existing, string $hash, string $ownerHash): array
    {
        if (! hash_equals($existing->owner_hash, $ownerHash) || ! hash_equals($existing->payload_hash, $hash)) {
            throw new InquiryException(409);
        }

        return ['state' => 'saved', 'receipt' => $existing->public_id, 'replayed' => true];
    }
}
