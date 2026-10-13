<?php

namespace App\Domain\Memberships\Production;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** Retained first-award records. Every column is covered by a table/purpose-bound keyed seal. */
final class MembershipPeriodRecords
{
    public const PERIODS = 'production_membership_paid_periods';

    public const EVENTS = 'production_membership_credit_events';

    public static function semantic(MembershipInvoiceBinding $invoice): array
    {
        return ['source_invoice_hash' => $invoice->sourceInvoiceHash, 'invoice_graph_hash' => $invoice->sourceGraphHash,
            'provider_scope_hash' => $invoice->providerScopeHash, 'amount_minor' => $invoice->amountMinor, 'currency' => $invoice->currency,
            'period_start' => $invoice->periodStart, 'period_end' => $invoice->periodEnd, 'paid_at' => $invoice->paidAt,
            'plan_version_id' => $invoice->planVersionId, 'policy_hash' => $invoice->planPolicyHash,
            'original_terms_hash' => $invoice->originalTermsHash, 'buyer_binding' => $invoice->originalBuyerBinding,
            'provenance' => $invoice->provenance];
    }

    public static function create(MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy, int $originId, array $actorBinding): array
    {
        MembershipException::require($originId > 0 && $policy->awardTerms !== null && $invoice->paidAt !== null, 'source_facts_absent');
        $id = (string) Str::uuid();
        $at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $payload = ['schema_version' => 'membership-paid-period-v1', 'purpose' => 'membership-first-period-award',
            'period_id' => $id, 'invoice' => self::semantic($invoice), 'created_at' => $at];
        $period = ['id' => $id, 'account_id' => $invoice->originalBuyerBinding['account_id'],
            'user_id' => $invoice->originalBuyerBinding['user_id'], 'identity_origin_id' => $originId, 'plan_version_id' => $policy->planVersionId,
            'source_invoice_hash' => $invoice->sourceInvoiceHash, 'invoice_graph_hash' => $invoice->sourceGraphHash,
            'owner_binding_hash' => CanonicalJson::hash($invoice->originalBuyerBinding), 'allowance' => $policy->creditAllowance,
            'period_start' => $invoice->periodStart, 'period_end' => $invoice->periodEnd,
            'credit_expires_at' => $policy->awardTerms->expiresAt($invoice->periodEnd),
            'payload_ciphertext' => Crypt::encryptString(CanonicalJson::encode($payload)), 'created_at' => $at];
        $period['seal'] = self::seal(self::PERIODS, $period);
        $event = ['id' => (string) Str::uuid(), 'period_id' => $id, 'sequence' => 1, 'kind' => 'award', 'amount' => $policy->creditAllowance,
            'redemption_id' => null, 'reservation_event_id' => null, 'grant_origin_id' => null, 'grant_receipt_hash' => null,
            'grant_purpose' => null, 'key_hash' => self::key($invoice), 'request_hash' => CanonicalJson::hash(self::semantic($invoice)),
            'prior_seal' => str_repeat('0', 64), 'actor_binding_hash' => CanonicalJson::hash($actorBinding),
            'before_available' => 0, 'before_reserved' => 0, 'before_consumed' => 0, 'before_expired' => 0,
            'after_available' => $policy->creditAllowance, 'after_reserved' => 0, 'after_consumed' => 0, 'after_expired' => 0,
            'created_at' => $at];
        $event['seal'] = self::seal(self::EVENTS, $event);

        return [$period, $event];
    }

    /** Only the operative first-event format is admitted; later ledger operations extend this audit. */
    public static function verify(array $period, array $events, MembershipInvoiceBinding $invoice, MembershipPolicyBinding $policy, int $originId): array
    {
        MembershipPolicyDocument::keys($period, ['id', 'account_id', 'user_id', 'identity_origin_id', 'plan_version_id',
            'source_invoice_hash', 'invoice_graph_hash', 'owner_binding_hash', 'allowance', 'period_start', 'period_end',
            'credit_expires_at', 'payload_ciphertext', 'seal', 'created_at']);
        self::verifySeal(self::PERIODS, $period);
        MembershipValues::id($period['id']);
        MembershipValues::utc($period['created_at']);
        MembershipException::require(is_string($period['payload_ciphertext']) && strlen($period['payload_ciphertext']) <= 65536, 'tampered');
        try {
            $plain = Crypt::decryptString($period['payload_ciphertext']);
            $payload = json_decode($plain, true, 24, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MembershipException('tampered');
        }
        MembershipException::require(is_array($payload) && strlen($plain) <= 16384 && CanonicalJson::encode($payload) === $plain, 'tampered');
        $expected = ['schema_version' => 'membership-paid-period-v1', 'purpose' => 'membership-first-period-award',
            'period_id' => $period['id'], 'invoice' => self::semantic($invoice), 'created_at' => $period['created_at']];
        MembershipException::require(CanonicalJson::encode($payload) === CanonicalJson::encode($expected), 'conflicting_invoice');
        MembershipException::require($policy->awardTerms !== null
            && (string) $period['account_id'] === (string) $invoice->originalBuyerBinding['account_id']
            && (string) $period['user_id'] === (string) $invoice->originalBuyerBinding['user_id']
            && (string) $period['identity_origin_id'] === (string) $originId
            && $period['plan_version_id'] === $policy->planVersionId
            && $period['source_invoice_hash'] === $invoice->sourceInvoiceHash && $period['invoice_graph_hash'] === $invoice->sourceGraphHash
            && $period['owner_binding_hash'] === CanonicalJson::hash($invoice->originalBuyerBinding)
            && (string) $period['allowance'] === (string) $policy->creditAllowance
            && $period['period_start'] === $invoice->periodStart && $period['period_end'] === $invoice->periodEnd
            && $period['credit_expires_at'] === $policy->awardTerms->expiresAt($invoice->periodEnd), 'conflicting_invoice');
        MembershipException::require(count($events) === 1, 'unsupported_credit_history');
        $event = $events[0];
        MembershipPolicyDocument::keys($event, ['id', 'period_id', 'sequence', 'kind', 'amount', 'redemption_id', 'reservation_event_id',
            'grant_origin_id', 'grant_receipt_hash', 'grant_purpose', 'key_hash', 'request_hash', 'prior_seal', 'actor_binding_hash',
            'before_available', 'before_reserved', 'before_consumed', 'before_expired', 'after_available', 'after_reserved',
            'after_consumed', 'after_expired', 'seal', 'created_at']);
        self::verifySeal(self::EVENTS, $event);
        MembershipValues::id($event['id']);
        MembershipValues::hash($event['actor_binding_hash']);
        MembershipException::require($event['period_id'] === $period['id'] && (string) $event['sequence'] === '1'
            && $event['kind'] === 'award' && (string) $event['amount'] === (string) $policy->creditAllowance
            && $event['prior_seal'] === str_repeat('0', 64) && $event['created_at'] === $period['created_at']
            && $event['key_hash'] === self::key($invoice) && $event['request_hash'] === CanonicalJson::hash(self::semantic($invoice)), 'tampered');
        foreach (['redemption_id', 'reservation_event_id', 'grant_origin_id', 'grant_receipt_hash', 'grant_purpose'] as $column) {
            MembershipException::require($event[$column] === null, 'tampered');
        }
        foreach (['before', 'after'] as $side) {
            foreach (['available', 'reserved', 'consumed', 'expired'] as $balance) {
                MembershipException::require((string) $event[$side.'_'.$balance]
                    === (string) ($side === 'after' && $balance === 'available' ? $policy->creditAllowance : 0), 'tampered');
            }
        }

        return ['period_id' => $period['id'], 'award_event_id' => $event['id'], 'allowance' => $policy->creditAllowance,
            'period_start' => $period['period_start'], 'period_end' => $period['period_end'],
            'credit_expires_at' => $period['credit_expires_at'], 'provenance' => $invoice->provenance];
    }

    public static function seal(string $table, array $row, ?string $key = null): string
    {
        MembershipException::require(in_array($table, [self::PERIODS, self::EVENTS], true), 'tampered');
        unset($row['seal']);

        return hash_hmac('sha256', CanonicalJson::encode(['purpose' => 'membership-award-record-v1',
            'table' => $table, 'row' => MembershipRecords::strings($row)]), $key ?? self::keys()[0]);
    }

    private static function verifySeal(string $table, array $row): void
    {
        MembershipException::require(is_string($row['seal']), 'tampered');
        $matched = false;
        foreach (self::keys() as $key) {
            $matched = hash_equals(self::seal($table, $row, $key), $row['seal']) || $matched;
        }
        MembershipException::require($matched, 'tampered');
    }

    private static function key(MembershipInvoiceBinding $invoice): string
    {
        return CanonicalJson::hash(['purpose' => 'membership-first-award', 'source_invoice_hash' => $invoice->sourceInvoiceHash]);
    }

    private static function keys(): array
    {
        $current = config('app.key');
        $previous = config('app.previous_keys', []);
        MembershipException::require(is_string($current) && strlen($current) >= 32 && is_array($previous), 'key_absent');
        foreach ($previous as $key) {
            MembershipException::require(is_string($key) && strlen($key) >= 32, 'key_absent');
        }

        return array_values(array_unique([$current, ...$previous]));
    }
}
