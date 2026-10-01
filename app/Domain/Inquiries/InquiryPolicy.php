<?php

namespace App\Domain\Inquiries;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class InquiryPolicy
{
    /** Only privacyNotice belongs in public page props. Internal setup values stay private. */
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
        $operator = $lockForUpdate ? User::query()->lockForUpdate()->find($operatorId) : User::find($operatorId);
        if ($operator === null || ! Gate::forUser($operator)->allows('administer-catalog', $lockForUpdate ? [true] : [])
            || ! AdminMultiFactor::satisfiedBy($operator, lockForUpdate: $lockForUpdate)) {
            return null;
        }

        return ['privacyNotice' => $notice, 'privacyHash' => hash('sha256', $notice), 'retentionReference' => $reference, 'operatorId' => $operator->id];
    }

    private function plain(mixed $value, int $max): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && trim($value) !== '' && mb_strlen($value) <= $max
            && preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 0;
    }
}
