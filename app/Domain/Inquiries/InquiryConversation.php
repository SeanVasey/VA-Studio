<?php

namespace App\Domain\Inquiries;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryMessage;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class InquiryConversation
{
    public const MAX_MESSAGES = 100;

    public function owner(string $receipt, string $ownerHash): array
    {
        return DB::transaction(fn (): array => $this->snapshot($this->owned($receipt, $ownerHash)));
    }

    public function followUp(string $receipt, string $ownerHash, array $body): array
    {
        $body = self::validate($body);

        return DB::transaction(fn (): array => $this->append($this->owned($receipt, $ownerHash), $body, null));
    }

    public function staff(int $inquiryId, User $actor): array
    {
        return DB::transaction(function () use ($inquiryId, $actor): array {
            $actor = app(InquiryAdministration::class)->actor($actor, lockForUpdate: true);
            $inquiry = CustomerInquiry::lockForUpdate()->findOrFail($inquiryId);
            $snapshot = $this->snapshot($inquiry);
            AuditEvent::record('inquiry.conversation_viewed', $inquiry, ['message_count' => count($snapshot['messages'])], $actor->id);

            return $snapshot;
        });
    }

    public function reply(int $inquiryId, array $body, User $actor): array
    {
        $body = self::validate($body);

        return DB::transaction(function () use ($inquiryId, $body, $actor): array {
            $actor = app(InquiryAdministration::class)->actor($actor, lockForUpdate: true);

            return $this->append(CustomerInquiry::lockForUpdate()->findOrFail($inquiryId), $body, $actor);
        });
    }

    public static function validate(array $body): array
    {
        if (count($body) !== 2 || array_diff(array_keys($body), ['message', 'requestKey']) !== []
            || ! is_string($body['message'] ?? null) || ! is_string($body['requestKey'] ?? null)
            || ! mb_check_encoding($body['message'], 'UTF-8') || trim($body['message']) === ''
            || mb_strlen($body['message']) > 4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body['message']) !== 0
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $body['requestKey']) !== 1) {
            throw new InquiryException(422, ['message' => ['Enter a plain-text message of at most 4000 characters.']]);
        }

        return $body;
    }

    private function owned(string $receipt, string $ownerHash): CustomerInquiry
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $receipt) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $ownerHash) !== 1) {
            throw new InquiryException(404);
        }
        $inquiry = CustomerInquiry::where('public_id', $receipt)->lockForUpdate()->first();
        if ($inquiry === null || ! hash_equals($inquiry->public_id, $receipt) || ! hash_equals($inquiry->owner_hash, $ownerHash)) {
            throw new InquiryException(404);
        }

        return $inquiry;
    }

    private function append(CustomerInquiry $inquiry, array $body, ?User $actor): array
    {
        $sender = $actor === null ? 'owner' : 'staff';
        $hash = CanonicalJson::hash(['message' => $body['message']]);
        // Current reads also work when a caller already established an older MySQL snapshot.
        $existing = InquiryMessage::where('inquiry_id', $inquiry->id)->where('sender_kind', $sender)
            ->where('request_key', $body['requestKey'])->lockForUpdate()->first();
        if ($existing !== null) {
            if (! hash_equals($existing->request_key, $body['requestKey']) || ! hash_equals($existing->payload_hash, $hash)
                || $existing->actor_user_id !== $actor?->id || ! hash_equals($existing->body, $body['message'])) {
                throw new InquiryException(409);
            }

            return ['state' => 'saved', 'messageId' => $existing->id, 'replayed' => true];
        }
        $count = InquiryMessage::where('inquiry_id', $inquiry->id)->lockForUpdate()->get(['id'])->count();
        if (config('inquiries.enabled') !== true || $inquiry->state === 'archived' || $count >= self::MAX_MESSAGES) {
            throw new InquiryException(409);
        }
        $message = InquiryMessage::create([
            'inquiry_id' => $inquiry->id, 'sender_kind' => $sender, 'actor_user_id' => $actor?->id,
            'request_key' => $body['requestKey'], 'payload_hash' => $hash, 'body' => $body['message'], 'created_at' => now(),
        ]);
        AuditEvent::recordAttributed('inquiry.message_saved', $inquiry, ['message_id' => $message->id, 'sender_kind' => $sender, 'payload_hash' => $hash], $actor?->id);

        return ['state' => 'saved', 'messageId' => $message->id, 'replayed' => false];
    }

    private function snapshot(CustomerInquiry $inquiry): array
    {
        $messages = InquiryMessage::where('inquiry_id', $inquiry->id)->orderBy('id')->limit(self::MAX_MESSAGES + 1)->lockForUpdate()->get();
        if ($messages->count() > self::MAX_MESSAGES) {
            throw new InquiryException(503);
        }
        $rows = [];
        foreach ($messages as $message) {
            $text = $message->body;
            self::validate(['message' => $text, 'requestKey' => $message->request_key]);
            if (! in_array($message->sender_kind, ['owner', 'staff'], true)
                || ($message->sender_kind === 'owner') !== ($message->actor_user_id === null)
                || ! hash_equals($message->payload_hash, CanonicalJson::hash(['message' => $text]))) {
                throw new InquiryException(503);
            }
            $rows[] = ['id' => $message->id, 'sender' => $message->sender_kind === 'owner' ? 'you' : 'staff',
                'message' => $text, 'createdAt' => $message->created_at->toISOString()];
        }

        return ['receipt' => $inquiry->public_id, 'state' => $inquiry->state, 'subject' => $inquiry->payload['subject'],
            'original' => ['message' => $inquiry->payload['message'], 'createdAt' => $inquiry->created_at->toISOString()],
            'messages' => $rows, 'canReply' => config('inquiries.enabled') === true && $inquiry->state !== 'archived' && count($rows) < self::MAX_MESSAGES];
    }
}
