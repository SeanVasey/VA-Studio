<?php

namespace App\Domain\Reporting;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\Preferences\LocalConsentRuntime;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, fail-closed marketing consent for staff segmentation. Unknown stays unknown: a purchase, an import or an
 * account never implies consent. `granted` is returned only when the latest recorded choice is an affirmative grant that
 * is still bound to the currently configured notice, was captured for the account's current recipient, the grant runtime
 * is enabled and no suppression record exists. Any other grant is `granted_unverified` and is excluded from marketing
 * segments, exactly as the customer-facing read downgrades it to unknown.
 */
final class CustomerConsentStatus
{
    public const LABELS = [
        'unknown' => 'Unknown (no recorded choice)',
        'withdrawn' => 'Withdrawn',
        'granted' => 'Granted (verified)',
        'granted_unverified' => 'Granted but not verified (excluded)',
    ];

    /**
     * @param  array<int, int>  $accounts  customer account id => user id
     * @return array<int, string> account id => one of self::LABELS keys
     */
    public function forAccounts(array $accounts): array
    {
        $statuses = array_fill_keys(array_keys($accounts), 'unknown');
        $configured = app(ConsentPolicy::class)->configured();
        $grantsEnabled = (new LocalConsentRuntime)->grantsEnabled();
        foreach (array_chunk($accounts, 500, preserve_keys: true) as $chunk) {
            $ids = array_keys($chunk);
            $rows = DB::table('customer_consent_states as s')->join('customer_consent_events as e', 'e.id', '=', 's.event_id')
                ->leftJoin('customer_consent_policies as pol', 'pol.id', '=', 'e.policy_id')
                ->where('s.purpose', ConsentPolicy::PURPOSE)->whereIn('s.customer_account_id', $ids)
                ->get(['s.customer_account_id as account', 's.revision as state_revision', 'e.customer_account_id as event_account', 'e.purpose as event_purpose',
                    'e.revision', 'e.status', 'e.affirmative', 'e.recipient_hmac', 'pol.purpose as policy_purpose', 'pol.version', 'pol.notice_hash', 'pol.policy_hash']);
            $emails = DB::table('users')->whereIn('id', array_values($chunk))->pluck('email', 'id');
            $suppressed = array_flip(DB::table('customer_suppression_targets')->where('purpose', ConsentPolicy::PURPOSE)
                ->whereIn('customer_account_id', $ids)->distinct()->pluck('customer_account_id')->all());
            foreach ($rows as $row) {
                $account = (int) $row->account;
                if ($row->status === 'withdrawn') {
                    $statuses[$account] = 'withdrawn';
                } elseif ($row->status === 'granted') {
                    $statuses[$account] = $this->verified($row, $configured, $grantsEnabled, $emails[$chunk[$account]] ?? null, isset($suppressed[$account]))
                        ? 'granted' : 'granted_unverified';
                }
            }
        }

        return $statuses;
    }

    private function verified(object $row, ?array $configured, bool $grantsEnabled, ?string $email, bool $suppressed): bool
    {
        if ($configured === null || ! $grantsEnabled || $suppressed || $email === null || $row->policy_hash === null
            || (int) $row->state_revision !== (int) $row->revision || (int) $row->event_account !== (int) $row->account
            || $row->event_purpose !== ConsentPolicy::PURPOSE || $row->policy_purpose !== ConsentPolicy::PURPOSE || (int) $row->affirmative !== 1
            || ! hash_equals($configured['policy_hash'], (string) $row->policy_hash) || ! hash_equals($configured['notice_hash'], (string) $row->notice_hash)
            || $configured['version'] !== $row->version) {
            return false;
        }
        try {
            $recipient = CustomerIdentityPolicy::email($email);
        } catch (CustomerAccessException) {
            return false;
        }

        return hash_equals(self::recipientHash((int) $row->account, $recipient), (string) $row->recipient_hmac);
    }

    /**
     * Mirrors the capture-time recipient binding in CustomerConsentPreferences. A test drives the real consent flow and
     * compares, so drift in either place fails closed and is caught.
     */
    public static function recipientHash(int $account, string $email): string
    {
        $key = config('app.key');
        ReportingException::require(is_string($key) && $key !== '', 'unavailable');

        return hash_hmac('sha256', "customer-consent-recipient-v1\0".$account."\0".$email, $key);
    }
}
