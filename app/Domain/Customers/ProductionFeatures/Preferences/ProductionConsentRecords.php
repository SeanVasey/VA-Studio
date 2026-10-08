<?php

namespace App\Domain\Customers\ProductionFeatures\Preferences;

use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureShape;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** One retained record meaning for preferences and the server-only withdrawal consumer. */
final class ProductionConsentRecords
{
    public static function policy(array $row): void
    {
        foreach (['purpose', 'version', 'notice', 'notice_hash', 'review_reference', 'policy_hash', 'created_at'] as $key) {
            if (! is_string($row[$key] ?? null)) {
                throw new ProductionFeatureException;
            }
        }
        if ($row['purpose'] !== ConsentPolicy::PURPOSE || ! ConsentPolicy::version($row['version'])
            || ! ConsentPolicy::text($row['notice'], 2000, 8000) || ! ConsentPolicy::text($row['review_reference'], 200, 800)
            || ! ProductionFeatureShape::timestamp($row['created_at'])
            || ! hash_equals(hash('sha256', $row['notice']), $row['notice_hash'])
            || ! hash_equals(CanonicalJson::hash(array_intersect_key($row, array_flip(['purpose', 'version', 'notice', 'review_reference']))), $row['policy_hash'])) {
            throw new ProductionFeatureException;
        }
    }

    public static function event(array $event, array $binding, ProductionFeatureConfiguration $configuration): array
    {
        try {
            foreach (['public_id', 'purpose', 'status', 'source', 'recipient_hmac', 'recipient_ciphertext', 'created_at'] as $key) {
                if (! is_string($event[$key] ?? null)) {
                    throw new ProductionFeatureException;
                }
            }
            $configuration->admit();
            $plain = Crypt::decryptString($event['recipient_ciphertext']);
            $configuration->admit();
            $capture = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($capture) || ! ConsentPolicy::keys($capture, ['schema', 'featureBinding', 'purpose', 'revision', 'eventId', 'email'])
                || $capture['schema'] !== 1 || $capture['featureBinding'] !== $binding['binding'] || $capture['purpose'] !== ConsentPolicy::PURPOSE
                || $capture['revision'] !== (int) $event['revision'] || $capture['eventId'] !== $event['public_id']
                || ! is_string($capture['email']) || IdentityPolicy::email($capture['email']) !== $capture['email']
                || $plain !== CanonicalJson::encode($capture) || strlen($event['recipient_ciphertext']) > 8192
                || ! hash_equals(self::recipientHash($binding, $capture['email'], $configuration), $event['recipient_hmac'])
                || (int) $event['binding_id'] !== (int) $binding['row']['id'] || $event['purpose'] !== ConsentPolicy::PURPOSE
                || (int) $event['revision'] < 1 || (int) $event['revision'] > ConsentPolicy::MAX_REVISION
                || ! Str::isUuid($event['public_id']) || ! ProductionFeatureShape::timestamp($event['created_at'])
                || $event['created_at'] < $binding['row']['created_at']
                || $event['source'] !== 'first_party_customer' || ! in_array($event['status'], ['granted', 'withdrawn'], true)
                || (int) $event['affirmative'] !== ($event['status'] === 'granted' ? 1 : 0) || ($event['status'] === 'granted' && $event['policy_id'] === null)) {
                throw new ProductionFeatureException;
            }

            return $capture;
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }

    public static function recipientHash(array $binding, string $recipient, ProductionFeatureConfiguration $configuration): string
    {
        $configuration->admit();

        return IdentityPolicy::digest('production-consent-recipient-v1', CanonicalJson::encode(['bindingHash' => $binding['row']['binding_hash'], 'purpose' => ConsentPolicy::PURPOSE, 'email' => $recipient]));
    }
}
