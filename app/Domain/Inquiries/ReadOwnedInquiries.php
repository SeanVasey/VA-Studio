<?php

namespace App\Domain\Inquiries;

use App\Domain\Inquiries\Models\CustomerInquiry;
use SensitiveParameter;

/** Original-session locators only. Neither a receipt nor an account recovers another inquiry owner. */
final class ReadOwnedInquiries
{
    public const LIMIT = 20;

    public function handle(#[SensitiveParameter] string $ownerHash, ?string $before = null): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $ownerHash) !== 1) {
            throw new InquiryException(404);
        }
        $query = CustomerInquiry::where('owner_hash', $ownerHash);
        if ($before !== null) {
            if (! $this->receipt($before)) {
                throw new InquiryException(422);
            }
            $anchor = (clone $query)->where('public_id', $before)->first(['id', 'public_id', 'owner_hash']);
            if (! $anchor || ! hash_equals($anchor->public_id, $before) || ! hash_equals($anchor->owner_hash, $ownerHash)) {
                throw new InquiryException(422);
            }
            $query->where('id', '<', $anchor->id);
        }
        // Never load messages or decrypt the sentinel. The immutable insertion identity supplies a stable cursor.
        $rows = $query->orderByDesc('id')->limit(self::LIMIT + 1)
            ->get(['id', 'public_id', 'owner_hash', 'payload', 'state', 'created_at']);
        $inquiries = [];
        foreach ($rows as $row) {
            if (! hash_equals($row->owner_hash, $ownerHash) || ! $this->receipt($row->public_id)) {
                throw new InquiryException(503);
            }
            if (count($inquiries) === self::LIMIT) {
                break;
            }
            $subject = $row->payload['subject'] ?? null;
            $createdAt = $row->created_at?->utc()->toIso8601ZuluString();
            if (! is_string($subject) || ! mb_check_encoding($subject, 'UTF-8') || trim($subject) === ''
                || mb_strlen($subject) > 160 || preg_match('/[\x00-\x1F\x7F]/u', $subject) !== 0
                || ! in_array($row->state, ['new', 'read', 'archived'], true)
                || ! is_string($createdAt) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $createdAt) !== 1) {
                throw new InquiryException(503);
            }
            $inquiries[] = ['receipt' => $row->public_id, 'subject' => $subject, 'state' => $row->state, 'createdAt' => $createdAt];
        }

        return ['inquiryHistorySchema' => 1, 'inquiries' => $inquiries, 'limit' => self::LIMIT,
            'nextCursor' => $rows->count() > self::LIMIT ? $inquiries[self::LIMIT - 1]['receipt'] : null];
    }

    private function receipt(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) === 1;
    }
}
