<?php

namespace App\Domain\Memberships\Billing;

use App\Domain\Memberships\Production\MembershipValues;
use App\Support\CanonicalJson;

/** Subscription record verification only: no database reads and no current buyer or paid-invoice authority. */
final class BillingSubscriptionRecord
{
    private const V1_KEYS = ['account_ref', 'amount_minor', 'currency', 'customer_ref', 'mode', 'plan_version_id',
        'price_ref', 'purpose', 'schema_version', 'subscription_ref'];

    private const V2_KEYS = ['account_ref', 'amount_minor', 'currency', 'customer_ref', 'mode', 'plan_version_id',
        'price_ref', 'purpose', 'schema_version', 'subscription_ref', 'plan_policy_hash', 'original_terms_hash',
        'original_buyer_binding', 'author_user_id', 'author_binding_hash', 'record'];

    private const ROW_KEYS = ['id', 'account_id', 'user_id', 'identity_origin_id', 'provider_account_hash', 'mode',
        'customer_ref_hash', 'subscription_ref_hash', 'price_ref_hash', 'plan_version_id', 'approval_binding_hash',
        'provenance', 'payload_ciphertext', 'created_at', 'seal'];

    /** Strict v2 admission. Legacy records remain readable through read(), but cannot become award authority. */
    public static function verify(array $row, array $configuration): array
    {
        $verified = self::read($row, $configuration);
        BillingException::require($verified['payload']['schema_version'] === 2, 'legacy_approval');

        return $verified;
    }

    /** @return array{row: array, payload: array, expectation: BillingExpectation} */
    public static function read(array $row, array $configuration): array
    {
        self::keys($row, self::ROW_KEYS);
        $row = BillingValues::integers($row, ['account_id', 'user_id', 'identity_origin_id']);
        BillingException::require(is_string($row['seal']) && is_string($row['payload_ciphertext'])
            && strlen($row['payload_ciphertext']) <= 524288 && hash_equals(BillingValues::seal($row), $row['seal']), 'binding_absent');
        $payload = BillingValues::decrypt($row['payload_ciphertext']);
        $version = $payload['schema_version'] ?? null;
        BillingException::require(in_array($version, [1, 2], true), 'binding_payload');
        self::keys($payload, $version === 1 ? self::V1_KEYS : self::V2_KEYS);
        BillingException::require($payload['purpose'] === 'production_membership_billing_subscription', 'binding_payload');
        foreach (['account_ref', 'mode', 'customer_ref', 'subscription_ref', 'price_ref', 'currency', 'plan_version_id'] as $key) {
            BillingException::require(is_string($payload[$key]), 'binding_payload');
        }
        BillingException::require(is_int($payload['amount_minor']), 'binding_payload');
        $expectation = new BillingExpectation($payload['account_ref'], $payload['mode'], $payload['customer_ref'], $payload['subscription_ref'],
            $payload['price_ref'], $payload['currency'], $payload['amount_minor']);
        $account = $expectation->accountRef;
        $mode = $expectation->mode;
        BillingException::require($account === $configuration['account_ref'] && $mode === $configuration['mode']
            && $row['mode'] === $mode && $row['provenance'] === $configuration['provenance'] && $row['plan_version_id'] === $payload['plan_version_id']
            && hash_equals(BillingValues::hash('provider-account', $account, $mode, $account), $row['provider_account_hash'])
            && hash_equals(BillingValues::hash('customer', $account, $mode, $expectation->customerRef), $row['customer_ref_hash'])
            && hash_equals(BillingValues::hash('subscription', $account, $mode, $expectation->subscriptionRef), $row['subscription_ref_hash'])
            && hash_equals(BillingValues::hash('price', $account, $mode, $expectation->priceRef), $row['price_ref_hash']), 'binding_mismatch');
        if ($version === 2) {
            self::approved($row, $payload, $configuration);
        }

        return ['row' => $row, 'payload' => $payload, 'expectation' => $expectation];
    }

    /** Hashes the authenticated approval facts, including the original staff evidence; never the later requesting staff. */
    public static function approvalHash(array $payload): string
    {
        unset($payload['record']);

        return CanonicalJson::hash($payload);
    }

    /** Every retained column except ciphertext and its outer checksum is authenticated inside the ciphertext. */
    public static function record(array $row): array
    {
        unset($row['payload_ciphertext'], $row['seal']);

        return $row;
    }

    private static function approved(array $row, array $payload, array $configuration): void
    {
        BillingException::require(is_array($payload['record']) && is_array($payload['original_buyer_binding'])
            && is_int($payload['author_user_id']) && $payload['author_user_id'] > 0, 'binding_payload');
        foreach (['plan_policy_hash', 'original_terms_hash', 'author_binding_hash'] as $hash) {
            BillingException::require(is_string($payload[$hash]) && preg_match('/\A[a-f0-9]{64}\z/D', $payload[$hash]) === 1, 'binding_payload');
        }
        MembershipValues::buyer($payload['original_buyer_binding']);
        MembershipValues::id($row['id']);
        MembershipValues::id($row['plan_version_id']);
        MembershipValues::utc($row['created_at']);
        BillingException::require(is_int($row['account_id']) && $row['account_id'] > 0 && is_int($row['user_id']) && $row['user_id'] > 0
            && is_int($row['identity_origin_id']) && $row['identity_origin_id'] > 0
            && $row['account_id'] === $payload['original_buyer_binding']['account_id']
            && $row['user_id'] === $payload['original_buyer_binding']['user_id']
            && $row['provenance'] === $payload['original_buyer_binding']['provenance']
            && $payload['plan_policy_hash'] === $configuration['approved_subscription_policy_hash']
            && CanonicalJson::encode(self::record($row)) === CanonicalJson::encode($payload['record'])
            && hash_equals(self::approvalHash($payload), $row['approval_binding_hash']), 'binding_mismatch');
    }

    private static function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        BillingException::require($actual === $expected, 'binding_payload');
    }
}
